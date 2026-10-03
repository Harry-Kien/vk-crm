<?php

namespace App\Filament\Admin\Resources\IntakeRequests\Pages;

use App\Actions\Intake\ConvertIntakeToMatter;
use App\Enums\ClientType;
use App\Enums\Confidentiality;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\DuplicateClientDetected;
use App\Exceptions\DuplicateClientNotVisible;
use App\Filament\Admin\Resources\IntakeRequests\IntakeRequestResource;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Models\IntakeParty;
use App\Models\IntakeRequest;
use App\Models\MatterType;
use App\Models\User;
use App\Support\ConflictOverride;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Trang "Chuyển thành vụ việc" của MỘT lần tiếp nhận (M10 Task 4, R3). Trang không có nghiệp vụ
 * riêng: nó điền sẵn form từ bản ghi, gọi `ConvertIntakeToMatter`, và dịch lời từ chối thành lỗi
 * tiếng Việt đúng ô.
 *
 * **Cổng:** chỉ người qua `IntakeRequestPolicy::convert` (`intake.convert` VÀ `matter.create`, và
 * thấy được bản ghi) — kiểm tra tường minh ở `mount()` VÀ ở mọi request sau (`hydrate()`), trả 404
 * (SPEC §10.10), không 403; cả hai chạy trước hook `canAccess()` của Filament (mặc định cho qua).
 * Bản ghi không thấy được thì 404 ngay ở bước resolve (truy vấn của resource). Bản ghi thấy được
 * nhưng không còn chuyển đổi được ({@see ConvertIntakeToMatter::refusal()}) thì trang đưa người dùng
 * về trang bản ghi kèm câu lý do. Action tự kiểm tra lại cả hai.
 *
 * **Không gõ lại:** người liên hệ, các bên đối lập (chỉ hiện tên và vai — định danh đi thẳng từ bản
 * ghi), vai của khách (`contact_role`), lĩnh vực, câu chuyện (thành ghi chú nội bộ, sửa được), tiêu
 * đề gợi ý, luật sư phụ trách (chính người bấm). Ngoại lệ duy nhất là số căn cước thô của khách
 * (R3) — tuỳ chọn.
 *
 * **Hai lượt xác nhận/ghi đè, sao lại đúng khuôn `CreateMatter`:** lượt 1 bấm chuyển đổi →
 * `OpenMatter` ném `ConflictAcknowledgementRequired`/`ConflictBlocked` → trang giữ kết quả
 * (`#[Locked]`), hiện bảng `filament.conflict-check-result`, và gắn lỗi vào ô "đã xem xét" / "lý do
 * ghi đè" (hai ô chỉ TỒN TẠI sau khi một kết quả thật đã hiện, ô lý do chỉ với kết quả Đỏ và chỉ
 * mở với quản lý/admin). Lượt 2 gửi `acknowledged` đúng bằng mức của lượt 1. Đổi vai của khách hay
 * số căn cước làm kết quả đang hiện mất hiệu lực (`forgetConflictResult()`).
 *
 * **Sau khi chuyển:** hai thông báo — kết quả kiểm tra xung đột (cùng hàm của form mở vụ,
 * `CreateMatter::notifySaved()`), và việc chuyển đổi (khách mới hay khách đã có) — rồi tới trang vụ
 * việc nếu người bấm xem được nó, không thì danh sách tiếp nhận (vụ `restricted` giao cho luật sư
 * khác: cả vụ lẫn bản ghi đều thôi hiện với người bấm).
 *
 * Không transaction ngoài: `OpenMatter` cấm (xem cảnh báo ở docblock của nó).
 */
class ConvertIntakeRequest extends Page
{
    use InteractsWithRecord;

    protected static string $resource = IntakeRequestResource::class;

    /** Các ô của form mà một `ValidationException` của Action gắn được vào (Action bỏ qua mọi khoá khác). */
    private const FORM_FIELDS = [
        'title', 'matter_type_id', 'lead_lawyer_id', 'client_role', 'opened_at', 'confidentiality',
        'court_name', 'case_number', 'description_internal', 'client_type', 'client_id_number',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Cùng vai trò và cùng lý do `#[Locked]` như `CreateMatter::$conflictResult`. */
    #[Locked]
    public ?array $conflictResult = null;

    /** Cùng vai trò và cùng lý do `#[Locked]` như `CreateMatter::$pendingConflictLevel`. */
    #[Locked]
    public ?string $pendingConflictLevel = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless($this->mayConvert(), 404);

        $refusal = ConvertIntakeToMatter::refusal($this->intake());

        if ($refusal !== null) {
            Notification::make()->title($refusal)->warning()->send();
            $this->redirect(IntakeRequestResource::getUrl('edit', ['record' => $this->intake()]));

            return;
        }

        $this->form->fill($this->prefill());
    }

    /** Mỗi request sau lần mở cũng hỏi lại cổng (quyền có thể đã bị thu hồi giữa hai lượt). */
    public function hydrate(): void
    {
        abort_unless($this->mayConvert(), 404);
    }

    public function getTitle(): string|Htmlable
    {
        return __('intake.convert.title', ['code' => $this->intake()->code]);
    }

    public function getBreadcrumb(): string
    {
        return __('intake.convert.breadcrumb');
    }

    public function form(Schema $schema): Schema
    {
        $intake = $this->intake();

        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                Section::make(__('intake.convert.sections.carried'))
                    ->description(__('intake.convert.sections.carried_description'))
                    ->columnSpanFull()
                    ->schema($this->carriedLines($intake)),
                Section::make(__('intake.convert.sections.client'))
                    ->description(__('intake.convert.sections.client_description'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('client_type')
                            ->label(__('intake.convert.client_type'))
                            ->options(collect(ClientType::cases())->mapWithKeys(fn (ClientType $type): array => [$type->value => $type->label()])->all())
                            ->native(false)
                            ->required(),
                        TextInput::make('client_id_number')
                            ->label(__('intake.convert.id_number'))
                            ->helperText($intake->contact_id_number_hash !== null
                                ? __('intake.convert.id_number_recorded_help')
                                : __('intake.convert.id_number_help'))
                            // Cùng trần với ô CCCD của khối "Tạo khách mới" (`MatterForm`) và luật Action.
                            ->maxLength(20)
                            // Số căn cước đổi khách hàng được tra ra, tức đổi chính bên mà kiểm tra xét.
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn () => $this->forgetConflictResult()),
                    ]),
                Section::make(__('intake.convert.sections.matter'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('client_role')
                            ->label(__('matters.create_form.client_role'))
                            ->helperText(__('matters.create_form.client_role_help'))
                            // R13(f): không "luật sư đối phương" cho chính khách hàng (cùng luật `MatterForm`).
                            ->options(collect(PartyRole::cases())
                                ->reject(fn (PartyRole $role): bool => $role === PartyRole::OpposingCounsel)
                                ->mapWithKeys(fn (PartyRole $role): array => [$role->value => $role->label()])
                                ->all())
                            ->native(false)
                            // Vai của khách quyết định bên nào là bên đối lập — đổi nó có thể lật mức.
                            ->live()
                            ->afterStateUpdated(fn () => $this->forgetConflictResult())
                            ->required(),
                        Select::make('matter_type_id')
                            ->label(__('matters.fields.matter_type'))
                            ->options(fn (): array => MatterType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Select::make('lead_lawyer_id')
                            ->label(__('matters.fields.lead_lawyer'))
                            ->options(fn (): array => CreateMatter::leadLawyerOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('title')
                            ->label(__('matters.fields.title'))
                            ->required()
                            ->maxLength(250)
                            ->columnSpanFull(),
                        Textarea::make('description_internal')
                            ->label(__('matters.transition_form.internal_note'))
                            ->helperText(__('matters.transition_form.internal_note_hint'))
                            ->rows(5)
                            // Cùng trần với ô câu chuyện của bản ghi (20.000 ký tự × tối đa 3 byte vừa dưới
                            // trần 60.000 byte của Action; cột `text` 65.535 byte).
                            ->maxLength(20000)
                            ->columnSpanFull(),
                        DatePicker::make('opened_at')
                            ->label(__('matters.overview_fields.opened_at'))
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->required(),
                        Select::make('confidentiality')
                            ->label(__('matters.overview_fields.confidentiality'))
                            ->options(collect(Confidentiality::cases())->mapWithKeys(fn (Confidentiality $case): array => [$case->value => $case->label()])->all())
                            ->native(false)
                            ->required(),
                        TextInput::make('court_name')
                            ->label(__('matters.overview_fields.court_name'))
                            ->maxLength(200),
                        TextInput::make('case_number')
                            ->label(__('matters.overview_fields.case_number'))
                            ->maxLength(80),
                    ]),
                Section::make(__('matters.conflict.section'))
                    ->description(__('matters.conflict.section_description'))
                    ->columnSpanFull()
                    ->schema([
                        View::make('filament.conflict-check-result')
                            ->viewData(fn (): array => EditIntakeRequest::conflictTableData($this->conflictResult ?? []))
                            ->visible(fn (): bool => $this->conflictResult !== null),
                        Toggle::make('acknowledge_conflict')
                            ->label(__('matters.conflict.acknowledge'))
                            ->helperText(__('matters.conflict.acknowledge_help'))
                            ->visible(fn (): bool => $this->conflictResult !== null)
                            ->default(false),
                        Textarea::make('override_reason')
                            ->label(__('matters.conflict.override_reason'))
                            ->helperText(fn (): string => $this->canOverrideRedConflict()
                                ? __('matters.conflict.override_reason_help_allowed')
                                : __('matters.conflict.override_reason_help_denied'))
                            ->visible(fn (): bool => $this->redResultShown())
                            ->disabled(fn (): bool => ! $this->canOverrideRedConflict())
                            ->rows(2),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('convert')
                ->footer([
                    Actions::make([
                        Action::make('submitConversion')
                            ->label(__('intake.convert.submit'))
                            ->submit('convert'),
                        Action::make('backToRecord')
                            ->label(__('intake.convert.cancel'))
                            ->color('gray')
                            ->url(fn (): string => IntakeRequestResource::getUrl('edit', ['record' => $this->intake()])),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function convert(): void
    {
        $actor = $this->actor();
        $data = $this->form->getState();

        // Cùng hai cổng của `CreateMatter::handleRecordCreation()`: xác nhận chỉ khớp mức đã hiện; lý
        // do ghi đè chỉ có nghĩa khi một bảng ĐỎ đã hiện (ô đó `visible()` theo cùng hàm).
        $acknowledged = ((bool) ($data['acknowledge_conflict'] ?? false) && $this->pendingConflictLevel !== null)
            ? ConflictLevel::tryFrom($this->pendingConflictLevel)
            : null;
        $overrideReason = $this->redResultShown() ? ($data['override_reason'] ?? null) : null;

        try {
            $conversion = app(ConvertIntakeToMatter::class)->handle(
                $actor,
                $this->intake(),
                $data,
                $overrideReason,
                $acknowledged,
            );
        } catch (ConflictBlocked $exception) {
            $this->pendingConflictLevel = null;
            $this->conflictResult = $exception->result->toArray();

            throw ValidationException::withMessages([
                'data.override_reason' => [$this->canOverrideRedConflict()
                    ? __('matters.conflict.blocked_retry')
                    : __('matters.conflict.blocked_retry_denied')],
            ]);
        } catch (ConflictAcknowledgementRequired $exception) {
            $this->pendingConflictLevel = $exception->result->level->value;
            $this->conflictResult = $exception->result->toArray();

            throw ValidationException::withMessages([
                'data.acknowledge_conflict' => [__('matters.conflict.ack_retry')],
            ]);
        } catch (ClientLookupThrottled|DuplicateClientNotVisible|DuplicateClientDetected $exception) {
            // Lời từ chối của bước tra/tạo khách: gắn vào khối "Khách hàng". Câu của
            // `DuplicateClientNotVisible` là câu trung lập, không tên, không mã (M6.5 R4a).
            throw ValidationException::withMessages(['data.client_id_number' => [$exception->getMessage()]]);
        } catch (ValidationException $exception) {
            $this->failOnFields($exception->errors());
        } catch (DomainException $exception) {
            $this->sendFailure($exception->getMessage());

            return;
        } catch (AuthorizationException) {
            abort(404);
        }

        CreateMatter::notifySaved($conversion->opening);

        Notification::make()
            ->title(__('intake.convert.done', ['intake' => $conversion->intake->code, 'matter' => $conversion->opening->matter->code]))
            ->body($conversion->clientCreated ? __('intake.convert.done_new_client') : __('intake.convert.done_existing_client'))
            ->success()
            ->send();

        // Người bấm thường thấy vụ vừa mở (`OpenMatter` thêm họ vào đội ngũ khi họ không phải lead). Ngoại
        // lệ: vụ `restricted` giao cho luật sư khác — khi đó cả vụ lẫn bản ghi (`scopeVisibleTo()`) đều
        // thôi hiện với họ, nên về danh sách tiếp nhận.
        $this->redirect(Gate::allows('view', $conversion->opening->matter)
            ? MatterResource::getUrl('view', ['record' => $conversion->opening->matter])
            : IntakeRequestResource::getUrl('index'));
    }

    /** Cùng hàm, cùng lý do như `CreateMatter::forgetConflictResult()`. */
    public function forgetConflictResult(): void
    {
        $this->conflictResult = null;
        $this->pendingConflictLevel = null;
        $this->data['acknowledge_conflict'] = false;
        $this->data['override_reason'] = null;
    }

    /** Chỉ để HIỂN THỊ — cổng thật ở `OpenMatter` (`ConflictOverride`). */
    public function canOverrideRedConflict(): bool
    {
        return ConflictOverride::allowedForCurrentUser();
    }

    /** Cùng định nghĩa `CreateMatter::redResultShown()`. */
    public function redResultShown(): bool
    {
        return ($this->conflictResult['level'] ?? null) === ConflictLevel::Red->value;
    }

    private function mayConvert(): bool
    {
        return Gate::allows('convert', $this->intake());
    }

    private function intake(): IntakeRequest
    {
        /** @var IntakeRequest $record */
        $record = $this->getRecord();

        return $record;
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 404);

        return $actor;
    }

    /**
     * Giá trị điền sẵn — xem docblock lớp, "Không gõ lại". Lĩnh vực chỉ điền khi còn đang dùng (ô chọn
     * chỉ liệt kê loại đang dùng; loại đã ngưng thì tiêu đề gợi ý chỉ là tên người liên hệ); luật sư
     * phụ trách là người bấm.
     *
     * @return array<string, mixed>
     */
    private function prefill(): array
    {
        $intake = $this->intake();
        $type = $intake->matter_type_id === null
            ? null
            : MatterType::query()->where('is_active', true)->find($intake->matter_type_id);

        return [
            'client_type' => ClientType::Individual->value,
            'client_id_number' => null,
            'client_role' => $intake->contact_role?->value,
            'matter_type_id' => $type?->getKey(),
            // Ai có `intake.convert` hôm nay cũng có `matter.transitionStage` (ô chọn dưới chỉ nhận người đó).
            'lead_lawyer_id' => $this->actor()->getKey(),
            'title' => Str::limit($type === null
                ? (string) $intake->contact_name
                : __('intake.convert.default_title', ['name' => $intake->contact_name, 'type' => $type->name]), 250, ''),
            'description_internal' => $intake->summary,
            'opened_at' => today()->toDateString(),
            'confidentiality' => Confidentiality::Normal->value,
            'court_name' => null,
            'case_number' => null,
            'acknowledge_conflict' => false,
            'override_reason' => null,
        ];
    }

    /**
     * Những gì sang từ bản ghi, nói bằng chữ: tên, SĐT, email của người liên hệ (người bấm đã thấy chúng
     * ở trang bản ghi), có CCCD hay không (không bao giờ số — chỉ dấu băm tồn tại), và vai + tên từng
     * bên đối lập (định danh của họ đi thẳng sang, không hiện).
     *
     * @return array<int, Text>
     */
    private function carriedLines(IntakeRequest $intake): array
    {
        $parties = $intake->parties()->orderBy('id')->get()
            ->map(fn (IntakeParty $party): Text => Text::make(__('intake.convert.party_line', [
                'role' => $party->role?->label(),
                'name' => $party->name,
            ])))
            ->all();

        return [
            Text::make(__('intake.convert.contact_line', ['name' => $intake->contact_name])),
            Text::make(__('intake.convert.contact_phone_line', ['phone' => $intake->contact_phone ?? '—'])),
            Text::make(__('intake.convert.contact_email_line', ['email' => $intake->contact_email ?? '—'])),
            Text::make($intake->contact_id_number_hash !== null
                ? __('intake.convert.contact_id_line')
                : __('intake.convert.contact_no_id_line')),
            ...($parties === [] ? [Text::make(__('intake.convert.no_parties'))->color('gray')] : $parties),
        ];
    }

    /**
     * Lỗi của Action: khoá có ô trên form → lỗi đúng ô (`data.<khoá>`); khoá khác (`intake` — bản ghi
     * vừa thôi chuyển đổi được ở tab khác) → thông báo. Cùng khuôn `TranslatesIntakeFailures`.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function failOnFields(array $errors): never
    {
        $bound = [];
        $unbound = [];

        foreach ($errors as $key => $messages) {
            if (in_array($key, self::FORM_FIELDS, true)) {
                $bound["data.{$key}"] = $messages;

                continue;
            }

            $unbound = [...$unbound, ...$messages];
        }

        if ($unbound !== []) {
            $this->sendFailure(implode("\n", $unbound));
        }

        // Không khoá nào bám được vào một ô: một lỗi rỗng — dừng, dữ liệu đã nhập giữ nguyên.
        throw ValidationException::withMessages($bound);
    }

    private function sendFailure(string $message): void
    {
        Notification::make()
            ->title(__('actions.failed_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();
    }
}
