<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\AddMatterParty;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Tab "Các bên" (SPEC §7.2, §4.16): bảng matter_parties. Thêm một bên thì chạy lại
 * RunConflictCheck NGAY và hiện kết quả TẠI CHỖ bằng một Notification (persistent, không cần tải
 * lại trang) — người dùng không phải rời trang để thấy kết quả.
 *
 * **Fix round 1 (review Important #1, #5):** việc lưu bên mới và chạy kiểm tra xung đột giờ đi
 * qua Action `App\Actions\AddMatterParty` — thuần nghiệp vụ, không biết gì về Filament — thay vì
 * lưu trước rồi mới kiểm tra sau (lỗ hổng cũ: một bên gây mức đỏ vẫn được lưu, không chặn, không
 * ghi đè, không xác nhận, đúng lúc SPEC §6.10 bắt buộc kiểm tra này chặn được). Lớp này giờ chỉ
 * còn hai việc: thu thập dữ liệu form và hiển thị kết quả — nghiệp vụ nằm ở `app/Actions/` đúng
 * CLAUDE.md.
 *
 * **Fix round 2 (review, cùng bản sửa round 1):** `createParty()` round 1 chỉ gọi
 * `notifyConflictCheckResult()` ở hai nhánh `catch` — nhánh THÀNH CÔNG (kể cả sau khi một manager
 * ghi đè mức đỏ) không hiện kết quả gì cả, đúng ngược lại với những gì docblock round 1 tuyên bố.
 * Sửa: `AddMatterParty::handle()` giờ trả `App\Support\AddMatterPartyResult` (bên + kết quả), nên
 * `createParty()` gọi `notifyConflictCheckResult($addition->result)` ở CẢ ba nhánh — xem docblock
 * `createParty()`.
 */
class PartiesRelationManager extends RelationManager
{
    use ScopesToVisibleMatters;

    protected static string $relationship = 'parties';

    /**
     * Mức của lần kiểm tra xung đột TRƯỚC trong modal "thêm bên" đang mở, chờ người dùng tích
     * "đã xem xét" ở lần gửi kế tiếp — xem docblock `createParty()`. `null` khi chưa có lần kiểm
     * tra nào bị chặn bởi yêu cầu xác nhận, hoặc sau khi bên đã lưu thành công.
     *
     * `#[Locked]` (fix round 2, finding B): không có nó, đây là một property Livewire công khai
     * bình thường — một payload bị sửa tay có thể tự đặt sẵn giá trị này rồi tích luôn ô "đã xem
     * xét" ở LẦN GỬI ĐẦU, thoả điều kiện xác nhận mà không ai từng thấy kết quả kiểm tra thật (vô
     * hiệu hoá mục đích của bước xác nhận, dù không vượt qua được chặn đỏ — chặn đỏ còn đòi vai
     * trò và lý do, cả hai đều được server kiểm tra lại trong `AddMatterParty`). `Locked` chặn mọi
     * `wire:model`/cập nhật property từ phía client, chỉ code PHP phía server (đúng những dòng
     * dưới đây) được đổi giá trị này.
     */
    #[Locked]
    public ?string $pendingConflictLevel = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.parties');
    }

    /**
     * Filament 5 mặc định coi relation manager trên trang ViewRecord là chỉ đọc
     * (`Panel::hasReadOnlyRelationManagersOnResourceViewPagesByDefault()` = true), nên CreateAction
     * bị `Response::deny()` bất kể policy nói gì — phải tắt ở đây để authorization thật sự (xem
     * `->authorize()` trên CreateAction bên dưới, fix round 1 finding 4) là nơi quyết định duy
     * nhất, đúng yêu cầu "thêm một bên" của SPEC §7.2.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label(__('matters.party_fields.role'))
                    ->options(collect(PartyRole::cases())->mapWithKeys(fn (PartyRole $role) => [$role->value => $role->label()]))
                    ->required(),
                Toggle::make('is_our_client')
                    ->label(__('matters.party_fields.is_our_client'))
                    ->live()
                    ->default(false),
                Select::make('client_id')
                    ->label(__('matters.party_fields.client'))
                    // Fix round 1 finding 3: KHÔNG liệt kê toàn bộ khách hàng văn phòng — một
                    // lawyer có matter.update nhưng không có client.manage chỉ được thấy khách
                    // hàng của những vụ việc họ đã liệt kê được (Matter::listableBy), đúng ranh
                    // giới ClientPolicy::view đã định nghĩa cho MỌI nơi khác đọc danh sách khách
                    // hàng. Chỉ ai có client.manage mới thấy toàn bộ.
                    ->options(fn (): array => VisibleClientOptions::forCurrentUser())
                    ->searchable()
                    // I-2: bắt buộc khi công tắc bật — `BuildsMatterParties` từ chối một bên tự
                    // nhận là khách hàng của văn phòng mà không có hồ sơ nào. Luật ở trait (đúng
                    // cả với seeder/job/console); ô này chỉ nói ra luật đó bằng lỗi gắn đúng ô.
                    ->required(fn (Get $get): bool => (bool) $get('is_our_client'))
                    ->visible(fn (Get $get): bool => (bool) $get('is_our_client')),
                TextInput::make('name')
                    ->label(__('matters.party_fields.name'))
                    ->required()
                    ->maxLength(200),
                TextInput::make('id_number')
                    ->label(__('matters.party_fields.id_number'))
                    ->maxLength(20),
                TextInput::make('phone')
                    ->label(__('matters.party_fields.phone'))
                    ->tel()
                    ->maxLength(20),
                TextInput::make('address')
                    ->label(__('matters.party_fields.address'))
                    ->maxLength(300),
                Textarea::make('note')
                    ->label(__('matters.party_fields.note'))
                    ->columnSpanFull(),
                Toggle::make('acknowledge_conflict')
                    ->label(__('matters.party_fields.acknowledge_conflict'))
                    ->helperText(__('matters.party_fields.acknowledge_conflict_help'))
                    ->default(false),
                Textarea::make('override_reason')
                    ->label(__('matters.party_fields.override_reason'))
                    ->helperText(__('matters.party_fields.override_reason_help'))
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('role')
                    ->label(__('matters.party_fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (PartyRole $state): string => $state->label()),
                TextColumn::make('name')
                    ->label(__('matters.party_fields.name'))
                    ->searchable(),
                IconColumn::make('is_our_client')
                    ->label(__('matters.party_fields.is_our_client'))
                    ->boolean(),
                TextColumn::make('client.name')
                    ->label(__('matters.party_fields.client'))
                    ->placeholder('—'),
                TextColumn::make('address')
                    ->label(__('matters.party_fields.address'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->icon(Heroicon::OutlinedUserPlus)
                    // Fix round 1 finding 4: MatterPartyPolicy::create() không nhận Matter (áp
                    // dụng chung theo matter.update, không theo từng vụ việc — hạn chế đã biết,
                    // xem báo cáo). Filament không tự truyền $matter vào policy này
                    // (getCreateAuthorizationResponse() gọi authorize('create') không kèm record),
                    // nên an toàn thật sự cho ĐÚNG vụ việc này phải tự kiểm tra ở đây.
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    ->using(fn (array $data): MatterParty => $this->createParty($data)),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query));
    }

    /**
     * Thu dữ liệu form → gọi `AddMatterParty` → dịch hai exception nghiệp vụ
     * (`ConflictBlocked`/`ConflictAcknowledgementRequired`) thành lỗi form giữ modal mở, để
     * người dùng đọc Notification kết quả rồi tích "đã xem xét" hoặc điền lý do ghi đè và gửi lại
     * — Livewire giữ nguyên dữ liệu đã nhập khi một action ném ValidationException, nên đây là một
     * vòng lặp thật, không phải màn hình chết.
     *
     * `$pendingConflictLevel` (public property của chính relation manager — một Livewire
     * component) nhớ mức của lần kiểm tra TRƯỚC trong modal đang mở, để khi người dùng tích "đã
     * xem xét" ở lần gửi THỨ HAI, Action nhận đúng `ConflictLevel` cần khớp (hợp đồng
     * `AddMatterParty`/`OpenMatter`: `$acknowledged` phải khớp CHÍNH XÁC mức của lần kiểm tra hiện
     * tại — không có sẵn trước khi biết mức, nên không thể truyền ngay từ lần gửi đầu).
     *
     * Lỗi ném ra dùng `errorKey()` để tính đúng tiền tố state-path của form đang mở
     * (`mountedActionSchema0.override_reason`) — Filament chỉ hiển thị lỗi ở đúng ô khi khoá lỗi
     * khớp CHÍNH XÁC state path đó; một `ValidationException` với khoá trần (`'override_reason'`)
     * bị Filament coi là không thuộc form nào và không hiện lỗi ở đúng ô (đã tự xác nhận: dùng
     * khoá trần khiến `TypeError`/lỗi không gắn đúng ô trong lần chạy thử đầu tiên).
     *
     * **Fix round 2, finding "kết quả kiểm tra không còn hiện trên đường thành công":**
     * `notifyConflictCheckResult()` giờ được gọi ở CẢ BA nhánh — hai `catch` (như trước) VÀ nhánh
     * thành công bên dưới `try`. Bỏ sót nhánh thành công là lỗi thật của fix round 1: một mức đỏ
     * được manager ghi đè vẫn LƯU ĐƯỢC (đúng thiết kế), nhưng trước bản sửa này không ai còn thấy
     * đã ghi đè xung đột với hồ sơ nào — đúng lúc "hiện kết quả tại chỗ" (SPEC §7.2) quan trọng
     * nhất. `AddMatterParty::handle()` giờ trả `AddMatterPartyResult` (bên + kết quả kiểm tra)
     * thay vì trần `MatterParty`, để nhánh thành công có `$addition->result` mà gọi.
     */
    private function createParty(array $data): MatterParty
    {
        /** @var Matter $matter */
        $matter = $this->getOwnerRecord();
        $actor = Auth::user();
        $isOurClient = (bool) ($data['is_our_client'] ?? false);

        // Review fix round 3, finding I-5 — cùng bản sửa, cùng một hàm với
        // `CreateMatter::mutateFormDataBeforeCreate()`: `VisibleClientOptions` chỉ giới hạn ô chọn
        // HIỂN THỊ gì, còn payload thì phía client gửi gì cũng được. Từ khi `BuildsMatterParties`
        // lấy TÊN và định danh của một bên `is_our_client` thẳng từ hồ sơ `Client` đã khoá, một
        // `client_id` giả mạo sẽ ghi TÊN THẬT của một khách hàng ngoài tầm nhìn lên dòng bên này.
        // `AddMatterParty` cố ý KHÔNG tự kiểm tra (xem docblock `CreateMatter` cho lý do: đây là
        // ranh giới tầm nhìn của panel, không phải ranh giới nghiệp vụ của Action), nên hai màn
        // hình phải gọi chung đúng hàm này để không lệch nhau.
        if ($isOurClient && filled($data['client_id'] ?? null)) {
            VisibleClientOptions::assertVisibleToCurrentUser($data['client_id']);
        }

        // tryFrom(), không from() (fix round 2, finding B): $pendingConflictLevel là một property
        // Livewire công khai (dù đã #[Locked] chặn ghi từ client) — vẫn phòng thủ ở điểm dùng,
        // không tin giá trị lưu trữ là một ConflictLevel hợp lệ. Giá trị không hợp lệ (hoặc null)
        // chỉ đơn giản không khớp mức thật của lần kiểm tra NÀY, nên AddMatterParty vẫn từ chối
        // đúng cách (ConflictAcknowledgementRequired) thay vì 500.
        $acknowledgeTicked = (bool) ($data['acknowledge_conflict'] ?? false);
        $acknowledged = ($acknowledgeTicked && $this->pendingConflictLevel !== null)
            ? ConflictLevel::tryFrom($this->pendingConflictLevel)
            : null;

        try {
            $addition = app(AddMatterParty::class)->handle(
                matter: $matter,
                actor: $actor,
                partyData: [
                    'role' => $data['role'],
                    'is_our_client' => $isOurClient,
                    'client_id' => $isOurClient ? ($data['client_id'] ?? null) : null,
                    'name' => $data['name'],
                    'address' => $data['address'] ?? null,
                    'note' => $data['note'] ?? null,
                    'id_number' => $data['id_number'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ],
                overrideReason: $data['override_reason'] ?? null,
                acknowledged: $acknowledged,
            );
        } catch (ConflictBlocked $exception) {
            $this->pendingConflictLevel = null;
            static::notifyConflictCheckResult($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('override_reason') => [__('matters.parties.conflict_blocked_retry')],
            ]);
        } catch (ConflictAcknowledgementRequired $exception) {
            $this->pendingConflictLevel = $exception->result->level->value;
            static::notifyConflictCheckResult($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('acknowledge_conflict') => [__('matters.parties.conflict_ack_retry')],
            ]);
        }

        $this->pendingConflictLevel = null;
        static::notifyConflictCheckResult($addition->result);

        return $addition->party;
    }

    /**
     * `{mountedActionSchemaN}.{field}` — cùng công thức `Filament\Forms\Testing\TestsForms::
     * assertHasFormErrors()` dùng để định vị lỗi trường của action đang mở (mượn tên schema từ
     * chính action đang mounted thay vì tự đoán chỉ số, vì action có thể lồng nhau).
     */
    private function errorKey(string $field): string
    {
        $schemaName = $this->getMountedActionSchemaName();
        $statePath = $schemaName !== null ? $this->getSchema($schemaName)?->getStatePath() : null;

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }

    /**
     * Fix round 1 finding 2: màu và tiêu đề lấy từ `requiresAcknowledgement()`, KHÔNG chỉ từ
     * `level` — một mức xanh có bên thiếu định danh (`hasIncompleteParties()`) vẫn đòi xem xét
     * (`requiresAcknowledgement()` true dù `level === Green`, xem docblock `ConflictCheckResult`),
     * nên KHÔNG được mang màu/tiêu đề "sạch" như một mức xanh thật.
     */
    private static function notifyConflictCheckResult(ConflictCheckResult $result): void
    {
        $needsAttention = $result->requiresAcknowledgement();

        $color = match (true) {
            $result->level === ConflictLevel::Red => 'danger',
            $needsAttention => 'warning',
            default => 'success',
        };

        $title = $needsAttention
            ? __('matters.parties.conflict_check_title_attention')
            : __('matters.parties.conflict_check_title_clear');

        $body = $result->matches->isEmpty()
            ? __('matters.parties.conflict_check_clear')
            : $result->matches
                ->map(fn (ConflictMatch $match): string => sprintf(
                    '%s (%s) — %s, %s: %s',
                    $match->matterCode,
                    $match->matterTypeName,
                    $match->partyRole->label(),
                    $match->tier->label(),
                    $match->level->label(),
                ))
                ->implode("\n");

        if ($result->hasIncompleteParties()) {
            $body .= "\n".__('matters.parties.conflict_check_incomplete', ['names' => implode(', ', $result->incompleteParties())]);
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->color($color)
            ->persistent()
            ->send();
    }
}
