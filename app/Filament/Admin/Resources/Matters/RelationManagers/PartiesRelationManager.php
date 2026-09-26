<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Actions\AddMatterParty;
use App\Actions\RemoveMatterParty;
use App\Actions\UpdateMatterParty;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Filament\Admin\Concerns\ScopesToVisibleMatters;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\AddMatterPartyResult;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use App\Support\ConflictOverride;
use App\Support\Normalizer;
use App\Support\UpdateMatterPartyResult;
use Closure;
use DomainException;
use Filament\Actions\Action;
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
 * **Fix round 2:** `createParty()` round 1 chỉ gọi thông báo kết quả ở hai nhánh `catch` — nhánh
 * THÀNH CÔNG (kể cả sau khi một manager ghi đè mức đỏ) không hiện kết quả gì cả. `AddMatterParty::
 * handle()` từ đó trả `App\Support\AddMatterPartyResult` (bên + kết quả) để cả ba nhánh nói được.
 *
 * ---
 *
 * **Fix round 4, Critical C-1 — cùng khiếm khuyết đã sửa ở `CreateMatter`, còn nguyên ở đây.**
 * Hai nửa, và nửa thứ hai mới là nửa nguy hiểm:
 *
 *  1. *Hai ô quyết định hiện vô điều kiện.* `override_reason` không có `visible()` cũng không có
 *     `disabled()`, và `createParty()` đẩy thẳng nó xuống `AddMatterParty`. Một manager gõ lý do
 *     ngay LƯỢT GỬI ĐẦU vì vậy đi thẳng vào nhánh ghi đè: bên được lưu đè lên một mức đỏ chưa ai
 *     từng nhìn thấy, `conflict_overridden: true` và lý do vào nhật ký VĨNH VIỄN — một bản ghi
 *     append-only về một quyết định mà người đó chưa bao giờ thật sự được đưa ra. Một lý do viết
 *     cho một xung đột chưa hiện ra không phải một quyết định; nó chỉ là một ô trống đã điền sẵn.
 *
 *     Giờ cả `override_reason` LẪN `acknowledge_conflict` đều `visible()` theo `$conflictResult`,
 *     tức chỉ tồn tại sau khi một kết quả kiểm tra THẬT đã được hiện ra. Một trường `hidden`
 *     KHÔNG được Filament dehydrate — đã đối chiếu vendor: `HasState::isDehydrated()` trả
 *     `! isHiddenAndNotDehydratedWhenHidden()`, và `$isDehydratedWhenHidden` mặc định `false` —
 *     nên đây là một cổng phía MÁY CHỦ, không phải trang trí. `createParty()` vẫn kiểm tra lại
 *     `$conflictResult !== null` một lần nữa để cổng không đặt hết trọng lượng lên một chi tiết
 *     nội bộ của framework, đúng như `CreateMatter::handleRecordCreation()`.
 *
 *     `visible()` và `disabled()` CHỒNG lên nhau chứ không triệt tiêu nhau: `disabled()` gọi
 *     `saved(false)` và `isDehydrated()` rơi về `isSaved()`, nên một luật sư ở lượt hai vẫn ĐỌC
 *     được ô lý do và câu giải thích ai mới ghi đè được, mà không gửi được gì qua nó. Trước bản
 *     sửa này ô đó mở cho luật sư gõ vào rồi vẫn bị Action từ chối — một lời hứa sai.
 *
 *  2. *Và màn hình nói sai về những gì vừa xảy ra.* Nhánh thành công cũ gọi cùng một hàm thông báo
 *     với hai nhánh bị chặn, nên một dòng ĐÃ LƯU XONG được gắn tiêu đề "Cần xem xét TRƯỚC KHI
 *     LƯU", không bao giờ nói rằng vừa có một lần ghi đè, và không bao giờ hiện lại lý do.
 *     `AddMatterPartyResult` lúc đó chỉ mang `party` + `result`, nên màn hình này về mặt CẤU TRÚC
 *     không thể nói thật kể cả khi câu chữ được viết lại: `handle()` trả về bình thường ở cả
 *     "xanh sạch" lẫn "đỏ đã ghi đè", và `$result->level` không phân biệt được "đỏ bị chặn" với
 *     "đỏ đã ghi đè".
 *
 *     Giờ có BA hàm thông báo riêng — `notifyConflictBlocked()`, `notifyAcknowledgementRequired()`
 *     và `notifySaved()` — thay vì một hàm kèm cờ. Đây là điểm mấu chốt: mỗi hàm chỉ biết một
 *     giai đoạn, nên không hàm nào CÓ THỂ mô tả một dòng đã lưu bằng câu "trước khi lưu".
 *     `notifySaved()` đọc `$addition->overridden` / `->overrideReason` / `->result` và chia ba mức
 *     hiển thị: ĐỎ ĐÃ GHI ĐÈ (`danger`, kèm mã hồ sơ xung đột và lý do nguyên văn), ĐÃ XEM XÉT
 *     (`warning`, gồm cả mức xanh có bên thiếu định danh — cùng luật `requiresAcknowledgement()`),
 *     và XANH SẠCH (`success`).
 *
 * **Vẫn là Notification chứ không phải một BẢNG trong modal, khác `CreateMatter` có chủ đích.**
 * SPEC §6.10 đòi dạng bảng cho *form tạo vụ việc*; ở đây modal đóng lại sau khi lưu nên một bảng
 * trong modal sẽ biến mất đúng lúc cần đọc nhất, còn Notification `persistent()` thì ở lại. Ghi ra
 * đây để lần sau ai đó thấy hai màn hình khác nhau thì biết là quyết định, không phải bỏ sót.
 *
 * ---
 *
 * # M6.5 Task 9 — sửa, gỡ, hiển thị (`conflict-05`, `conflict-09`, `conflict-10`, brief R14)
 *
 * Bảng giờ có thêm hai `recordActions`: `editPartyAction()` (gọi `App\Actions\UpdateMatterParty`,
 * cùng cơ chế RED/override/acknowledge của `AddMatterParty`, xem docblock Action đó) và
 * `removePartyAction()` (gọi `App\Actions\RemoveMatterParty`, xoá mềm kèm lý do bắt buộc). Cả ba
 * form (thêm/sửa) giờ dùng CHUNG `partyFields()` — tách ra từ `form()` cũ — để không lệch nhau lần
 * thứ tư trên nhánh này; hai màn hình chỉ khác câu giúp của `id_number`/`phone` (form sửa: "để
 * trống nếu không đổi", vì hai ô đó không bao giờ điền sẵn được — số gốc không lưu, SPEC §10.5).
 *
 * `conflictSummary()` giờ ghép các dòng bằng `<br>` (đã escape từng mảnh động qua `e()`), không
 * còn `"\n"` — `conflict-09`: CSS đã biên dịch của Filament không có `white-space: pre-line`, nên
 * nhiều khớp từng chạy liền thành một đoạn khó đọc.
 *
 * Ô điện thoại (`partyFields()`) bỏ regex mặc định của `->tel()` (`->regex(null)`, gọi SAU
 * `->tel()`), thay bằng một `->rule()` kiểm bằng `Normalizer::phone()` — `conflict-10`: regex cũ
 * từ chối `(+84) 912 345 678`/`+84 (0) 912-345-678`, hai cách viết Normalizer chuẩn hoá đúng.
 * `app/Filament/Admin/Resources/Matters/Schemas/MatterForm.php` (form mở vụ) sửa y hệt.
 */
class PartiesRelationManager extends RelationManager
{
    use ScopesToVisibleMatters;

    protected static string $relationship = 'parties';

    /**
     * Kết quả lần kiểm tra xung đột GẦN NHẤT của modal "thêm bên" đang mở, dạng
     * `ConflictCheckResult::toArray()` — mảng thuần để Livewire tuần tự hoá được. `null` khi chưa
     * có lần kiểm tra nào trong modal này, sau khi bên đã lưu, hoặc sau khi người dùng sửa một ô
     * làm kết quả cũ hết hiệu lực.
     *
     * Đây là thứ DUY NHẤT gác hai ô quyết định, nên `#[Locked]`: nếu client đặt được nó, một
     * payload dàn dựng lại mở được hai ô đó ngay lượt gửi đầu và C-1 quay lại nguyên vẹn qua cửa
     * sau. `Locked` chặn mọi cập nhật property từ phía client; chỉ code PHP dưới đây đổi được.
     */
    #[Locked]
    public ?array $conflictResult = null;

    /**
     * Mức của lần kiểm tra TRƯỚC trong modal "thêm bên" đang mở, chờ người dùng tích "đã xem xét"
     * ở lần gửi kế tiếp — xem docblock `createParty()`. `null` khi chưa có lần kiểm tra nào bị
     * chặn bởi yêu cầu xác nhận, hoặc sau khi bên đã lưu thành công.
     *
     * `#[Locked]` (fix round 2, finding B): không có nó, đây là một property Livewire công khai
     * bình thường — một payload bị sửa tay có thể tự đặt sẵn giá trị này rồi tích luôn ô "đã xem
     * xét" ở LẦN GỬI ĐẦU, thoả điều kiện xác nhận mà không ai từng thấy kết quả kiểm tra thật.
     */
    #[Locked]
    public ?string $pendingConflictLevel = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('matters.tabs.parties');
    }

    /**
     * Nhãn của MỘT dòng, dùng cho nút "Tạo mới…" và tiêu đề modal. Không đặt thì Filament tự sinh
     * từ tên lớp và màn hình đọc "Tạo mới matter party" / "Tạo Matter Party" — tiếng Anh, ngay
     * trên hai chỗ đập vào mắt nhất của tab này (CLAUDE.md: chuỗi giao diện qua `__()`/`lang/vi`).
     */
    protected static function getModelLabel(): ?string
    {
        return __('matters.party_label');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('matters.party_plural_label');
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
        return $schema->components($this->partyFields(isEdit: false));
    }

    /**
     * Trường của form "thêm bên" VÀ form "sửa bên" (M6.5 Task 9) — tách ra một hàm dùng chung để
     * hai màn hình không lệch nhau như `conflict-05` mô tả (sửa từng lệch tạo nhiều lần trên nhánh
     * này). Chỉ HAI ô đổi theo `$isEdit`, không đổi luật, chỉ đổi CÂU:
     *
     *  - `id_number`/`phone`: giúp đọc "để trống nếu không đổi" chỉ đúng ở form SỬA. Số căn cước/
     *    điện thoại GỐC không bao giờ được lưu (SPEC §10.5), nên form sửa không có gì để điền sẵn
     *    vào hai ô này — chúng LUÔN bắt đầu trống dù bên đã có định danh, và
     *    `MatterParty::identifyKeepingWhenBlank()` (qua `UpdateMatterParty`) giữ nguyên định danh cũ
     *    khi bỏ trống. Câu giúp của form TẠO ("nên nhập… bỏ trống thì không đối chiếu được") sẽ nói
     *    SAI ở form sửa: một bên ĐÃ có định danh mà bỏ trống hai ô này không hề mất định danh.
     *
     * **Regex mặc định của `->tel()` bị bỏ (`conflict-10`).** Filament gắn kèm
     * `/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/` — từ chối cả `(+84) 912 345 678` (dấu `(`
     * đứng SAU `+`) lẫn `+84 (0) 912-345-678` (dấu `(` nằm ngoài phần mã đầu), hai cách viết rất
     * hay gặp trên danh thiếp/hợp đồng Việt Nam mà `Normalizer::phone()` chuẩn hoá đúng cả hai.
     * `->regex(null)` GHI ĐÈ closure mà `->tel()` vừa gắn (gọi SAU `->tel()`, `CanBeValidated::
     * regex()` chỉ ghi đè `$regexPattern` — không hợp hai lời gọi) — giữ nguyên `type="tel"` (bàn
     * phím số trên di động) mà không còn ràng buộc hình dạng chuỗi nào. `->rule()` thay bằng đúng
     * hàm mà `RunConflictCheck` sẽ dùng để đối chiếu: `Normalizer::phone()` trả `null` khi KHÔNG có
     * chữ số nào trong chuỗi — tức chuỗi không giống một số điện thoại ở bất kỳ cách viết nào —
     * đúng và chỉ đúng loại lỗi gõ (`"abc"`, toàn ký tự) cần chặn; mọi cách viết có chữ số, dù ngoặc
     * hay khoảng trắng ở đâu, đều qua được, đúng ý "bỏ regex mặc định… kiểm bằng Normalizer::phone()".
     *
     * @return array<int, mixed>
     */
    private function partyFields(bool $isEdit): array
    {
        return [
            Select::make('role')
                ->label(__('matters.party_fields.role'))
                ->options(collect(PartyRole::cases())->mapWithKeys(fn (PartyRole $role) => [$role->value => $role->label()]))
                ->live()
                ->afterStateUpdated($this->forgetConflictResultOnChange())
                ->required(),
            Toggle::make('is_our_client')
                ->label(__('matters.party_fields.is_our_client'))
                ->live()
                ->afterStateUpdated($this->forgetConflictResultOnChange())
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
                ->visible(fn (Get $get): bool => (bool) $get('is_our_client'))
                ->live()
                ->afterStateUpdated($this->forgetConflictResultOnChange()),
            TextInput::make('name')
                ->label(__('matters.party_fields.name'))
                ->live(onBlur: true)
                ->afterStateUpdated($this->forgetConflictResultOnChange())
                ->required()
                ->maxLength(200),
            TextInput::make('id_number')
                ->label(__('matters.party_fields.id_number'))
                ->helperText($isEdit ? __('matters.party_fields.id_number_edit_help') : null)
                ->live(onBlur: true)
                ->afterStateUpdated($this->forgetConflictResultOnChange())
                ->maxLength(20),
            TextInput::make('phone')
                ->label(__('matters.party_fields.phone'))
                ->helperText($isEdit ? __('matters.party_fields.phone_edit_help') : null)
                ->tel()
                ->regex(null)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && Normalizer::phone($value) === null) {
                        $fail(__('matters.party_fields.phone_invalid'));
                    }
                })
                ->live(onBlur: true)
                ->afterStateUpdated($this->forgetConflictResultOnChange())
                ->maxLength(20),
            TextInput::make('address')
                ->label(__('matters.party_fields.address'))
                ->maxLength(300),
            Textarea::make('note')
                ->label(__('matters.party_fields.note'))
                ->columnSpanFull(),
            // Hai ô QUYẾT ĐỊNH (C-1, xem docblock lớp): chỉ TỒN TẠI sau khi một kết quả kiểm
            // tra thật đã hiện ra cho người dùng đọc.
            Toggle::make('acknowledge_conflict')
                ->label(__('matters.party_fields.acknowledge_conflict'))
                ->helperText(__('matters.party_fields.acknowledge_conflict_help'))
                ->visible(fn (): bool => $this->conflictResult !== null)
                ->default(false),
            Textarea::make('override_reason')
                ->label(__('matters.party_fields.override_reason'))
                // Hiện cho MỌI vai trò nhưng KHOÁ với ai không ghi đè được, đúng như
                // `MatterForm`: người dùng cần đọc được luật ngay tại chỗ, và lỗi mức đỏ cần
                // một ô để bám vào.
                ->helperText(fn (): string => $this->canOverrideRedConflict()
                    ? __('matters.party_fields.override_reason_help_allowed')
                    : __('matters.party_fields.override_reason_help_denied'))
                ->visible(fn (): bool => $this->redResultShown())
                ->disabled(fn (): bool => ! $this->canOverrideRedConflict())
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /** SPEC §6.10 bước 3: chỉ `manager`/`admin` ghi đè được mức đỏ. Chỉ để HIỂN THỊ — cổng thật ở `AddMatterParty`. */
    public function canOverrideRedConflict(): bool
    {
        return ConflictOverride::allowedForCurrentUser();
    }

    /**
     * Một kết quả kiểm tra mức ĐỎ đã thật sự được hiện ra cho người dùng đọc — điều kiện DUY NHẤT
     * làm một lý do ghi đè có nghĩa (I-A, review gộp nhánh M3).
     *
     * **Vì sao không phải `conflictResult !== null`.** Ghi đè chỉ tồn tại cho mức đỏ (SPEC §6.10
     * bảng mức), nhưng ô lý do từng `visible()` trên MỌI kết quả đã lưu — vàng, và cả xanh có bên
     * thiếu định danh. Nên một manager viết lý do trong một vòng VÀNG (nơi ô đó không có việc gì để
     * làm), rồi mức leo lên ĐỎ trước lượt gửi kế tiếp — một lần thêm bên song song trên cùng vụ
     * việc, hay một lần sửa hồ sơ `Client` kích hoạt `SyncClientPartyIdentities` ghi lại
     * `id_number_hash` — thì câu viết cho vòng vàng đó được `AddMatterParty` đọc như một quyết định
     * ghi đè: `isBlocking()` + manager + lý do khác rỗng ⟹ LƯU. `conflict_overridden: true` và câu
     * đó vào dòng nhật ký append-only VĨNH VIỄN, trong khi không một bảng ĐỎ nào từng được hiện ra.
     * Thông báo sau đó nói thật, nhưng bản ghi thì đọc như một quyết định của cấp trên về một xung
     * đột không ai được xem — trên đúng chức năng SPEC gọi là nghĩa vụ đạo đức nghề nghiệp.
     *
     * Một hàm, HAI tầng dùng: `visible()` của ô (nên Filament không dehydrate nó ngoài vòng đỏ) và
     * lời kiểm tra lại trong `createParty()` (nên cổng không đặt trọng lượng lên một chi tiết
     * dehydrate của framework). Hai tầng không thể lệch nhau vì chúng hỏi cùng một câu.
     *
     * Ô "đã xem xét" thì KHÔNG hẹp lại theo mức: một vòng vàng cần đúng dấu tích đó để đi tiếp.
     */
    public function redResultShown(): bool
    {
        return ($this->conflictResult['level'] ?? null) === ConflictLevel::Red->value;
    }

    /**
     * Quên kết quả kiểm tra đang giữ, mức đang chờ xác nhận, VÀ dấu tích "đã xem xét".
     *
     * Kết quả kiểm tra là một ẢNH CHỤP của dữ liệu tại lúc bấm lưu, nhưng nó nằm im trong khi
     * người dùng sửa tiếp form — nên nó có thể đang mô tả một bên không còn tồn tại như thế nữa.
     * Tệ hơn, `$pendingConflictLevel` chỉ nhớ MỨC: sửa một ô giữa hai lượt gửi mà mức vẫn vàng thì
     * dấu tích của lần kiểm tra CŨ được nhận cho lần kiểm tra MỚI. Cùng Minor 3/4 đã sửa ở
     * `CreateMatter`; hai màn hình sinh đôi phải cho ra cùng một kết quả, nếu không chúng lại lệch
     * nhau lần thứ tư.
     *
     * Dấu tích VÀ lý do ghi đè đều bị gỡ, không chỉ mức: để nguyên một ô đã tích trong khi lời từ
     * chối bảo người dùng "hãy tích ô này" là một màn hình tự mâu thuẫn, và một lý do viết cho kết
     * quả kiểm tra NÀY mà sống sót sang kết quả KẾ TIẾP là chính C-1 ở quy mô nhỏ hơn. Ghi qua `data_set` lên chính component
     * Livewire — đúng cách Filament tự ghi state (`HasState::rawState()` cũng làm y vậy), vì state
     * của một action đang mounted nằm ở `mountedActions.N.data`, không ở `$this->data` như một
     * trang CreateRecord.
     */
    public function forgetConflictResult(): void
    {
        $this->conflictResult = null;
        $this->pendingConflictLevel = null;

        $acknowledgeKey = $this->errorKey('acknowledge_conflict');

        // Khác khoá trần nghĩa là có một action đang mounted và tính được state path của nó.
        if ($acknowledgeKey !== 'acknowledge_conflict') {
            data_set($this, $acknowledgeKey, false);
            // Lý do ghi đè rơi theo (Minor, fix round 4 — cùng bản sửa ở `CreateMatter`): một lý
            // do viết cho kết quả kiểm tra NÀY mà sống sót sang kết quả KẾ TIẾP sẽ ghi đè một
            // xung đột khác bằng một câu chưa ai viết cho nó.
            data_set($this, $this->errorKey('override_reason'), null);
        }
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
                    // Nhãn nút và tiêu đề modal viết thẳng, không mượn nhãn model: câu mặc định
                    // của Filament dựng từ tên lớp ("Tạo mới matter party", "Tạo Matter Party"),
                    // và ngay cả khi đặt `getModelLabel()` tiếng Việt thì tiêu đề vẫn bị
                    // `Str::ucwords()` biến thành "Tạo Bên Trong Vụ Việc" — tiếng Việt không viết
                    // hoa từng chữ như vậy.
                    ->label(__('matters.actions.add_party'))
                    ->modalHeading(__('matters.actions.add_party_heading'))
                    // Fix round 1 finding 4: MatterPartyPolicy::create() không nhận Matter (áp
                    // dụng chung theo matter.update, không theo từng vụ việc — hạn chế đã biết,
                    // xem báo cáo). Filament không tự truyền $matter vào policy này
                    // (getCreateAuthorizationResponse() gọi authorize('create') không kèm record),
                    // nên an toàn thật sự cho ĐÚNG vụ việc này phải tự kiểm tra ở đây.
                    ->authorize(fn (): bool => Gate::allows('update', $this->getOwnerRecord()))
                    // Mỗi lần MỞ modal bắt đầu từ con số không (C-1, cửa sau): không có bước này,
                    // một modal bỏ dở rồi mở lại cho một bên HOÀN TOÀN KHÁC sẽ hiện sẵn hai ô
                    // quyết định, nói về kết quả kiểm tra của bên trước. Giữ nguyên hành vi mặc
                    // định `$schema->fill()` của `CanBeMounted::getMountUsing()`, chỉ thêm việc
                    // dọn trạng thái của lớp này.
                    ->mountUsing(function (?Schema $schema): void {
                        $this->forgetConflictResult();
                        $schema?->fill();
                    })
                    ->using(fn (array $data): MatterParty => $this->createParty($data))
                    // Tắt thông báo "Đã tạo" mặc định của Filament — cùng Minor 6 đã sửa ở
                    // `CreateMatter::getCreatedNotification()`. `CreateAction` gửi nó NGAY SAU
                    // `using()`, nên một lần thêm bên hiện HAI thông báo chồng nhau: cái thứ hai
                    // không nói gì mà `notifySaved()` chưa nói, và nó đẩy câu về kết quả kiểm tra
                    // xung đột (thứ SPEC §6.10 bắt buộc người dùng đọc) xuống dưới — đúng lúc câu
                    // đó là "ĐÃ GHI ĐÈ XUNG ĐỘT MỨC ĐỎ".
                    ->successNotification(null),
            ])
            ->recordActions([
                $this->editPartyAction(),
                $this->removePartyAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => static::scopeToVisibleMatters($query));
    }

    /**
     * "Sửa" một bên (M6.5 Task 9, `conflict-05`). Dùng một `Action` viết tay, KHÔNG phải
     * `Filament\Actions\EditAction` mặc định: cần gọi `App\Actions\UpdateMatterParty` (nghiệp vụ
     * nằm ở `app/Actions/`, CLAUDE.md) và dịch hai exception xung đột thành lỗi form giữ modal mở —
     * đúng hình dạng `CreateAction` bên trên, cùng lớp `partyFields()` dùng chung (đọc docblock
     * hàm đó cho lý do hai form không được lệch nhau).
     *
     * **`mountUsing()` làm HAI việc, không phải một (như `CreateAction` ở trên).** Filament tự
     * dựng `mountUsing()` từ `->fillForm()` (xem `CanBeMounted::fillForm()` — nó CHÍNH LÀ
     * `mountUsing(fn ($action, $schema) => $schema?->fill(...))`), nên gọi CẢ `->fillForm()` LẪN
     * `->mountUsing()` sẽ để lời gọi SAU ghi đè lời gọi TRƯỚC (cùng một thuộc tính `$mountUsing`
     * duy nhất) — không "cộng dồn" như tên hai hàm gợi ý. Vì mọi lần MỞ modal sửa cũng phải
     * `forgetConflictResult()` (C-1, cùng lý do `CreateAction`: một modal bỏ dở rồi mở cho một
     * bên KHÁC không được hiện sẵn kết quả kiểm tra của bên trước), hàm này viết MỘT `mountUsing()`
     * làm cả hai việc, không dùng `->fillForm()`.
     *
     * **Không điền `id_number`/`phone` (cố ý, khác mọi trường khác).** Hai cột đó không tồn tại
     * trên `MatterParty` — chỉ `id_number_hash`/`phone_normalized` còn lại (SPEC §10.5) — nên
     * không có GIÁ TRỊ THẬT nào để điền sẵn; bỏ qua hai khoá này trong mảng fill là đủ, `Schema::
     * fill()` để trống những khoá không có mặt.
     */
    private function editPartyAction(): Action
    {
        return Action::make('editParty')
            ->label(__('matters.actions.edit_party'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('matters.actions.edit_party_heading'))
            // Cùng lớp phòng thủ hai tầng của `AddMatterParty`/`CreateAction`:
            // `MatterPartyPolicy::update()` không phân biệt vụ việc RESTRICTED khỏi vụ thường theo
            // TỪNG BÊN (nó gọi lại `canSeeMatter()`), nên tự hỏi Gate tại đây trên ĐÚNG bản ghi là
            // lớp phòng thủ đứng cạnh chính sách, không thay thế nó.
            ->authorize(fn (MatterParty $record): bool => Gate::allows('update', $record))
            ->schema($this->partyFields(isEdit: true))
            ->mountUsing(function (?Schema $schema, MatterParty $record): void {
                $this->forgetConflictResult();
                $schema?->fill([
                    'role' => $record->role->value,
                    'is_our_client' => $record->is_our_client,
                    'client_id' => $record->client_id,
                    'name' => $record->name,
                    'address' => $record->address,
                    'note' => $record->note,
                ]);
            })
            // `Action` (chung, khác `EditAction`) không có `->using()` — dùng `->action()` như
            // mọi Action viết tay khác của lớp này (`changeResponsibleAction()` ở
            // `DeadlinesRelationManager` cùng công thức).
            ->action(function (MatterParty $record, array $data): void {
                $this->editParty($record, $data);
            })
            // Cùng lý do `CreateAction`: thông báo mặc định của Filament sẽ chồng lên
            // `notifyUpdateSaved()`, đúng lúc câu đó có thể là "ĐÃ GHI ĐÈ XUNG ĐỘT MỨC ĐỎ".
            ->successNotification(null);
    }

    /**
     * "Gỡ" một bên (M6.5 Task 9, brief R14). Xoá mềm kèm lý do bắt buộc — đọc docblock
     * `App\Actions\RemoveMatterParty` cho lý lẽ đầy đủ, kể cả vì sao bên của CHÍNH khách hàng vụ
     * việc không gỡ được ở đây.
     */
    private function removePartyAction(): Action
    {
        return Action::make('removeParty')
            ->label(__('matters.actions.remove_party'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->modalHeading(__('matters.actions.remove_party_heading'))
            ->authorize(fn (MatterParty $record): bool => Gate::allows('delete', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('matters.remove_party_form.reason'))
                    ->helperText(__('matters.remove_party_form.reason_help'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (MatterParty $record, array $data): void {
                $this->removeParty($record, $data);
            });
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
     * `$conflictResult` được đặt TRƯỚC khi ném `ValidationException` ở cả hai nhánh `catch`: đó là
     * thứ làm hai ô quyết định hiện ra ở lượt render ngay sau lời từ chối, nên lỗi gắn vào chúng
     * mới có một ô để bám. Đặt sau sẽ là một lỗi trỏ vào một trường không tồn tại.
     *
     * Lỗi ném ra dùng `errorKey()` để tính đúng tiền tố state-path của form đang mở
     * (`mountedActionSchema0.override_reason`) — Filament chỉ hiển thị lỗi ở đúng ô khi khoá lỗi
     * khớp CHÍNH XÁC state path đó; một `ValidationException` với khoá trần (`'override_reason'`)
     * bị Filament coi là không thuộc form nào và không hiện lỗi ở đúng ô.
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

        // C-1 + I-A: lý do ghi đè chỉ có nghĩa khi một kết quả mức ĐỎ đã hiện ra cho người dùng
        // đọc — không phải "một kết quả bất kỳ". Xem `redResultShown()` cho đường leo mức mà điều
        // kiện cũ để lọt. Ô này đã `visible()` theo cùng hàm đó nên ngoài vòng đỏ Filament còn
        // không dehydrate nó; kiểm tra lại ở đây để cổng không đặt hết trọng lượng lên một chi tiết
        // dehydrate của framework — cùng lập luận với `CreateMatter::handleRecordCreation()`.
        $overrideReason = $this->redResultShown() ? ($data['override_reason'] ?? null) : null;

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
                overrideReason: $overrideReason,
                acknowledged: $acknowledged,
            );
        } catch (ConflictBlocked $exception) {
            // Mức đỏ không phải thứ "thử lại là qua": xoá mức đang chờ để một ô xác nhận còn tích
            // sót từ lượt trước không mang nghĩa gì ở lượt sau.
            $this->pendingConflictLevel = null;
            $this->conflictResult = $exception->result->toArray();
            static::notifyConflictBlocked($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('override_reason') => [$this->canOverrideRedConflict()
                    ? __('matters.parties.conflict_blocked_retry')
                    : __('matters.parties.conflict_blocked_retry_denied')],
            ]);
        } catch (ConflictAcknowledgementRequired $exception) {
            $this->pendingConflictLevel = $exception->result->level->value;
            $this->conflictResult = $exception->result->toArray();
            static::notifyAcknowledgementRequired($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('acknowledge_conflict') => [__('matters.parties.conflict_ack_retry')],
            ]);
        } catch (DomainException $exception) {
            // Lưới an toàn cho MỌI luật nghiệp vụ còn lại của tầng Action (hôm nay chỉ có
            // `OurClientPartyNeedsClient`; `ConflictBlocked`/`ConflictAcknowledgementRequired` đã
            // được bắt ở trên và cũng là `DomainException`, nên thứ tự `catch` ở đây là bắt buộc).
            // `bootstrap/app.php` không đăng ký bất kỳ `render()` nào cho `DomainException`, nên
            // không có lưới này thì một luật như vậy thoát ra khỏi modal thành trang lỗi 500 —
            // cùng lập luận và cùng hình dạng với `BuildsStageUpdateSchema`. Đường chính đã bị
            // `required()` chặn từ trước; đây là cho những đường vào chưa lường trước.
            //
            // Lỗi gắn vào `client_id`: luật duy nhất đi qua đây hôm nay nói về đúng ô đó, và ô đó
            // chỉ hiện khi công tắc "là khách hàng của văn phòng" bật — tức đúng lúc luật có thể
            // vi phạm. Thông điệp lấy nguyên từ exception, vốn đã là một câu tiếng Việt qua
            // `lang/vi/exceptions.php`.
            throw ValidationException::withMessages([
                $this->errorKey('client_id') => [$exception->getMessage()],
            ]);
        }

        static::notifySaved($addition);

        // Bên đã lưu xong: lần mở modal sau phải bắt đầu từ con số không — nếu không, hai ô quyết
        // định hiện sẵn cho một bên hoàn toàn khác. `mountUsing` cũng dọn; đây là lớp thứ hai, cho
        // cả đường "Tạo & tạo thêm" vốn không mount lại action.
        $this->forgetConflictResult();

        return $addition->party;
    }

    /**
     * Thu dữ liệu form → gọi `App\Actions\UpdateMatterParty` → dịch hai exception nghiệp vụ thành
     * lỗi form giữ modal mở — CÙNG hình dạng `createParty()` ở trên (đọc docblock hàm đó cho lý lẽ
     * đầy đủ về `$pendingConflictLevel`/`errorKey()`, không lặp lại ở đây), chỉ khác Action đích và
     * ba khoá dịch câu (`update_parties.*` thay vì `parties.*`, xem `notifyUpdateSaved()`).
     */
    private function editParty(MatterParty $record, array $data): MatterParty
    {
        $actor = Auth::user();
        $isOurClient = (bool) ($data['is_our_client'] ?? false);

        // Cùng lý do `createParty()`: `VisibleClientOptions` chỉ giới hạn ô chọn HIỂN THỊ gì, còn
        // payload thì phía client gửi gì cũng được.
        if ($isOurClient && filled($data['client_id'] ?? null)) {
            VisibleClientOptions::assertVisibleToCurrentUser($data['client_id']);
        }

        $acknowledgeTicked = (bool) ($data['acknowledge_conflict'] ?? false);
        $acknowledged = ($acknowledgeTicked && $this->pendingConflictLevel !== null)
            ? ConflictLevel::tryFrom($this->pendingConflictLevel)
            : null;

        $overrideReason = $this->redResultShown() ? ($data['override_reason'] ?? null) : null;

        try {
            $update = app(UpdateMatterParty::class)->handle(
                party: $record,
                actor: $actor,
                partyData: [
                    'role' => $data['role'],
                    'is_our_client' => $isOurClient,
                    'client_id' => $isOurClient ? ($data['client_id'] ?? null) : null,
                    'name' => $data['name'],
                    'address' => $data['address'] ?? null,
                    'note' => $data['note'] ?? null,
                    // Để trống nghĩa là "không đổi" ở form SỬA (xem docblock `partyFields()` và
                    // `BuildsMatterParties::applyMatterPartyData()`), KHÁC form thêm bên.
                    'id_number' => $data['id_number'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ],
                overrideReason: $overrideReason,
                acknowledged: $acknowledged,
            );
        } catch (ConflictBlocked $exception) {
            $this->pendingConflictLevel = null;
            $this->conflictResult = $exception->result->toArray();
            static::notifyUpdateConflictBlocked($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('override_reason') => [$this->canOverrideRedConflict()
                    ? __('matters.update_parties.conflict_blocked_retry')
                    : __('matters.update_parties.conflict_blocked_retry_denied')],
            ]);
        } catch (ConflictAcknowledgementRequired $exception) {
            $this->pendingConflictLevel = $exception->result->level->value;
            $this->conflictResult = $exception->result->toArray();
            static::notifyAcknowledgementRequired($exception->result);

            throw ValidationException::withMessages([
                $this->errorKey('acknowledge_conflict') => [__('matters.parties.conflict_ack_retry')],
            ]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                $this->errorKey('client_id') => [$exception->getMessage()],
            ]);
        }

        static::notifyUpdateSaved($update);

        $this->forgetConflictResult();

        return $update->party;
    }

    /**
     * Thu lý do gỡ → gọi `App\Actions\RemoveMatterParty` → dịch `ValidationException` của Action
     * (lý do rỗng, hoặc bên của chính khách hàng vụ việc) sang đúng ô `reason` của modal đang mở.
     */
    private function removeParty(MatterParty $record, array $data): void
    {
        $actor = Auth::user();

        try {
            app(RemoveMatterParty::class)->handle($record, $actor, $data['reason'] ?? '');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                $this->errorKey('reason') => $exception->errors()['reason'] ?? [__('actions.unauthorized')],
            ]);
        }

        Notification::make()
            ->title(__('matters.remove_party_form.success'))
            ->success()
            ->send();
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
     * Hook gắn vào mọi ô có thể làm đổi kết quả kiểm tra — xem `forgetConflictResult()`.
     *
     * `live()` đi kèm là BẮT BUỘC chứ không phải tuỳ chọn: một trường không `live()` không gửi gì
     * về máy chủ cho tới lúc bấm lưu, nên `afterStateUpdated` của nó không bao giờ chạy.
     * `onBlur: true` cho các ô gõ tay — gửi từng ký tự về chỉ để xoá một mảng thường đã null là
     * lãng phí, còn rời ô đã đủ sớm (kết quả chỉ hiện lại ở lượt bấm lưu kế tiếp).
     *
     * `address`/`note` cố ý KHÔNG có hook: `RunConflictCheck` không đọc hai cột đó, và form này
     * chỉ có MỘT bên nên danh sách ô liên quan đủ ngắn để đọc hết trong một màn hình — khác
     * `MatterForm`, nơi mọi ô trong repeater đều `live()` để không ai phải nhớ ô nào quan trọng
     * giữa một danh sách bên dài tuỳ ý.
     *
     * So `$state` với `$old` chứ không quên vô điều kiện: gõ lại ĐÚNG giá trị cũ không làm ảnh
     * chụp kết quả kiểm tra sai đi, nên không có lý do gì bắt người dùng chạy lại kiểm tra. (Trong
     * trình duyệt Livewire vốn không gửi gì khi giá trị không đổi, nên phép so này chủ yếu giữ cho
     * hành vi phía máy chủ khớp với những gì người dùng thật sự thấy — và giữ cho một lời gọi
     * `fillForm()` trong test không vô tình mang nghĩa "người dùng vừa sửa cả form".)
     */
    private function forgetConflictResultOnChange(): Closure
    {
        return function (mixed $state, mixed $old): void {
            if ($state === $old) {
                return;
            }

            $this->forgetConflictResult();
        };
    }

    /**
     * Mức đỏ, KHÔNG có ghi đè hợp lệ: chưa lưu gì cả. Tiêu đề nói đúng điều đó thay vì mượn câu
     * "cần xem xét" của nhánh vàng — một mức đỏ không phải một lời nhắc, nó là một lời từ chối.
     */
    private static function notifyConflictBlocked(ConflictCheckResult $result): void
    {
        Notification::make()
            ->title(__('matters.parties.conflict_blocked_title'))
            ->body(static::conflictSummary($result, null))
            ->color('danger')
            ->persistent()
            ->send();
    }

    /**
     * Cần xác nhận (vàng, hoặc xanh có bên thiếu định danh — `requiresAcknowledgement()`, KHÔNG
     * chỉ `level === Yellow`, xem docblock `ConflictCheckResult`): cũng chưa lưu gì, nên "trước
     * khi lưu" ở đây là câu ĐÚNG.
     */
    private static function notifyAcknowledgementRequired(ConflictCheckResult $result): void
    {
        Notification::make()
            ->title(__('matters.parties.conflict_check_title_attention'))
            ->body(static::conflictSummary($result, null))
            ->color('warning')
            ->persistent()
            ->send();
    }

    /**
     * Bên ĐÃ LƯU. Ba mức hiển thị, không hai — và không mức nào được mô tả bằng câu "trước khi
     * lưu", vì việc lưu đã xong (C-1, xem docblock lớp).
     *
     *  - `$addition->overridden`: lần lưu này có đi qua cổng ghi đè mức đỏ hay không. KHÔNG suy ra
     *    được từ `level` — đỏ xuất hiện ở CẢ nhánh bị chặn lẫn nhánh được ghi đè.
     *  - `$addition->result`: mức và danh sách bản ghi trùng, để câu thông báo kể ra ĐÃ ghi đè
     *    xung đột với hồ sơ nào, không chỉ rằng có ghi đè.
     *  - `$addition->overrideReason`: lý do đã ghi vĩnh viễn vào nhật ký, hiện lại nguyên văn để
     *    người vừa gõ nó nhìn thấy mình vừa ký vào cái gì.
     */
    private static function notifySaved(AddMatterPartyResult $addition): void
    {
        $result = $addition->result;

        // Fix round 1, C3 (Critical, `conflict-01`): một kết quả không có khớp MỚI nhưng CÓ khớp
        // đã xác nhận/ghi đè trước đó (R13c) không được phép dùng tiêu đề + màu XANH của "không
        // tìm thấy xung đột" — trước bản sửa này, một dòng "…: Đỏ" trong thân thông báo đứng cạnh
        // tiêu đề "Không tìm thấy xung đột lợi ích" màu success, tự mâu thuẫn với chính nội dung
        // nó vừa hiện ra. Bốn mức, không ba.
        [$title, $color] = match (true) {
            $addition->overridden => [__('matters.parties.saved_overridden'), 'danger'],
            $result->requiresAcknowledgement() => [__('matters.parties.saved_after_review'), 'warning'],
            $result->confirmedMatches->isNotEmpty() => [
                __('matters.parties.saved_clear_with_confirmed', ['count' => $result->confirmedMatches->count()]),
                'warning',
            ],
            default => [__('matters.parties.conflict_check_title_clear'), 'success'],
        };

        Notification::make()
            ->title($title)
            ->body(static::conflictSummary($result, $addition->overrideReason))
            ->color($color)
            ->persistent()
            ->send();
    }

    /**
     * Mức đỏ, KHÔNG có ghi đè hợp lệ, khi đang SỬA một bên (M6.5 Task 9). Cùng lý lẽ
     * `notifyConflictBlocked()` ở trên — chưa lưu gì cả, chỉ khác câu: "chưa lưu THAY ĐỔI này",
     * không phải "chưa thêm bên này", vì bên đó đã tồn tại từ trước lượt sửa.
     */
    private static function notifyUpdateConflictBlocked(ConflictCheckResult $result): void
    {
        Notification::make()
            ->title(__('matters.update_parties.conflict_blocked_title'))
            ->body(static::conflictSummary($result, null))
            ->color('danger')
            ->persistent()
            ->send();
    }

    /**
     * Bên ĐÃ SỬA XONG (M6.5 Task 9) — cùng bốn mức, cùng lý lẽ `notifySaved()` ở trên, chỉ đổi hai
     * khoá dịch nói riêng về việc "thêm" thành "sửa"/"lưu thay đổi". Hai khoá còn lại
     * (`saved_clear_with_confirmed`, `conflict_check_title_clear`) dùng CHUNG với `notifySaved()`:
     * câu chữ của chúng không nhắc "thêm bên" nên đúng cho cả hai thao tác.
     */
    private static function notifyUpdateSaved(UpdateMatterPartyResult $update): void
    {
        $result = $update->result;

        [$title, $color] = match (true) {
            $update->overridden => [__('matters.update_parties.saved_overridden'), 'danger'],
            $result->requiresAcknowledgement() => [__('matters.update_parties.saved_after_review'), 'warning'],
            $result->confirmedMatches->isNotEmpty() => [
                __('matters.parties.saved_clear_with_confirmed', ['count' => $result->confirmedMatches->count()]),
                'warning',
            ],
            default => [__('matters.parties.conflict_check_title_clear'), 'success'],
        };

        Notification::make()
            ->title($title)
            ->body(static::conflictSummary($result, $update->overrideReason))
            ->color($color)
            ->persistent()
            ->send();
    }

    /**
     * Phần thân chung của cả ba thông báo: danh sách hồ sơ trùng, cảnh báo bên thiếu định danh, và
     * lý do ghi đè nếu có.
     *
     * **Mỗi dòng mang ĐÚNG sáu trường của `ConflictMatch`, theo đúng thứ tự các cột của bảng trên
     * màn hình sinh đôi:** mã hồ sơ, loại vụ việc, vai của bên trùng, TÊN của bên trùng, tầng khớp,
     * mức. Không tiêu đề, không tóm tắt, không id — ranh giới lộ thông tin của SPEC §6.10 đoạn cuối.
     *
     * **`partyName` từng thiếu, trong khi docblock này tự nhận là có (I-B, review gộp nhánh M3).**
     * Đính chính SPEC 2026-09-16 nói thẳng vì sao cột đó không bỏ được: "không có nó thì người dùng
     * không có cách nào kiểm chứng hay phản bác kết quả". Đây là màn hình nơi một manager ký một lý
     * do ghi đè vĩnh viễn — họ phải đọc được mình đang ghi đè lên AI, không chỉ lên hồ sơ nào. Việc
     * thêm KHÔNG nới ranh giới: `partyName` vốn đã là một trong sáu trường của `ConflictMatch` và
     * đã hiện trên bảng của `CreateMatter` từ đầu.
     *
     * Dòng lý do dùng CHUNG khoá dịch với `CreateMatter`
     * (`matters.conflict.saved_overridden_reason`): câu đó nói về chính cái nhật ký, không về thao
     * tác, nên nó giống hệt nhau ở hai màn hình. Những câu KHÁC nhau (thao tác là "mở vụ việc" hay
     * "thêm bên") thì mỗi màn hình giữ khoá riêng.
     *
     * **Fix round 1 — spec gap: "bên phía mình" (R13d) và nhãn "đã xem xét ở lần trước" (R13c)
     * giờ có mặt trong CHÍNH thông báo, không chỉ trong bảng `conflict-check-result.blade.php`
     * của `CreateMatter`.** Round 0 thêm hai trường này vào `ConflictMatch` nhưng chỉ hiện chúng
     * ở bảng trong form — tab "Các bên" không có bảng nào (modal đóng lại sau khi lưu, xem
     * docblock lớp), nên thông báo là nơi DUY NHẤT còn lại; thiếu hai nhãn đó ở đây là thiếu hẳn,
     * không phải thiếu một bản sao. Dùng `allMatches()` (khớp MỚI + đã xác nhận) để một khớp đã
     * xác nhận/ghi đè trước đó vẫn "hiện" (R13c), đánh dấu bằng `already_confirmed`.
     *
     * **`<br>`, không `"\n"` (`conflict-09`, M6.5 Task 9).** Filament in thân thông báo qua
     * `str($body)->sanitizeHtml()` (`Symfony\Component\HtmlSanitizer`, cấu hình `allowSafeElements()`
     * — cho phép các thẻ định dạng cơ bản như `<br>`), rồi bơm HTML đó thẳng vào DOM trong một
     * `<div>`. CSS đã biên dịch của lớp `.fi-no-notification-body` không có `white-space: pre-line`,
     * nên MỘT chuỗi xuống dòng bằng `"\n"` chạy liền thành một đoạn — hai hồ sơ trùng trở thành một
     * dòng khó đọc, đúng lỗ hổng `conflict-09` mô tả. `<br>` là HTML thật, được sanitizer giữ lại.
     *
     * **Từng mảnh ĐỘNG (tên bên, lý do ghi đè, danh sách bên thiếu định danh) phải qua `e()` TRƯỚC
     * khi ghép chuỗi.** Sanitizer chạy trên CẢ CHUỖI cuối cùng, nên một tên bên gõ tay chứa `<`/`&`
     * (dù vô tình hay cố ý) sẽ được TRÌNH DUYỆT/SANITIZER hiểu như mở đầu một thẻ HTML nếu không
     * escape trước — `e()` biến nó về thực thể HTML (`&lt;`) để nó luôn hiện đúng NGUYÊN VĂN như
     * một tên, không bao giờ bị hiểu nhầm là đánh dấu. Chỉ hai dấu phân cách `<br>` do CHÍNH hàm
     * này chèn vào mới là HTML thật, cố ý không escape.
     */
    private static function conflictSummary(ConflictCheckResult $result, ?string $overrideReason): string
    {
        $formatMatch = fn (ConflictMatch $match, bool $alreadyConfirmed): string => sprintf(
            '%s (%s) — %s, %s, %s: %s — %s: %s, %s%s',
            e($match->matterCode),
            e($match->matterTypeName),
            e($match->partyRole->label()),
            e($match->partyName),
            e($match->tier->label()),
            e($match->level->label()),
            e(__('matters.conflict.column_our_party')),
            e($match->ourPartyRole->label()),
            e($match->ourPartyName),
            $alreadyConfirmed ? ' — '.e(__('matters.conflict.already_confirmed')) : '',
        );

        $allLines = $result->matches->map(fn (ConflictMatch $match) => $formatMatch($match, false))
            ->concat($result->confirmedMatches->map(fn (ConflictMatch $match) => $formatMatch($match, true)));

        $lines = [$allLines->isEmpty() ? e(__('matters.parties.conflict_check_clear')) : $allLines->implode('<br>')];

        if ($result->hasIncompleteParties()) {
            $lines[] = e(__('matters.parties.conflict_check_incomplete', ['names' => implode(', ', $result->incompleteParties())]));
        }

        if (filled($overrideReason)) {
            $lines[] = e(__('matters.conflict.saved_overridden_reason', ['reason' => $overrideReason]));
        }

        return implode('<br>', $lines);
    }
}
