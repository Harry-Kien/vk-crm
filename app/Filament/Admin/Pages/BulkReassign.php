<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Matter\BulkReassignMatterResult;
use App\Actions\Matter\ReassignMatters;
use App\Enums\Role as StaffRole;
use App\Models\Matter;
use App\Models\User;
use App\Support\OpenWork;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bàn giao HÀNG LOẠT nhiều vụ việc của MỘT luật sư đang phụ trách sang MỘT lead mới (SPEC §6.11;
 * M7 Task 2). Phán quyết controller Task 2: trang riêng cho ADMIN/TRƯỞNG PHÒNG (luật sư vẫn dùng
 * nút "Bàn giao" từng vụ trên `ViewMatter` như hiện nay — họ không thấy trang này).
 *
 * # Cổng: `Gate::define('bulkReassign')`, hỏi Ở CẢ MOUNT LẪN HÀNH ĐỘNG THẬT
 *
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` mặc định trả `true` cho MỌI trang tự
 * viết, và middleware 404 của panel (`AnswerDeniedPanelRequestsWithNotFound`) không phủ được
 * request cập nhật Livewire (đã đo ở nhiều trang khác trong dự án — `SubmitDocument`,
 * `MyRequests`) — nên trang này tự hỏi `Gate` ở HAI chỗ: {@see self::canAccess()} (panel gọi lúc
 * tải trang, VÀ chính `mount()` gọi lại lần nữa bằng `abort_unless` để phòng một request cập nhật
 * Livewire không đi qua `canAccess()` của panel), và {@see self::reassignSelected()} (hành động
 * THẬT — không tin trang đã lọc đúng, đúng kỷ luật `ReassignMatter`/`ReassignMatters` đã có).
 *
 * `Gate::define('bulkReassign')` (không phải một quyền thứ 14 trong `App\Enums\Permission`) —
 * xem docblock ở nơi khai báo nó (`AppServiceProvider::boot()`) cho lý do.
 *
 * # Luồng bốn bước, MỘT schema duy nhất
 *
 * 1. Chọn "luật sư đang phụ trách" ({@see self::currentLeadOptions()}) — tham số `?from=<id>` mở
 *    sẵn (từ liên kết trong thông điệp chặn nghỉ việc của `EditUser`/`DeleteStaffMember`, R6).
 * 2. Danh sách vụ việc ĐANG MỞ của người đó hiện ra ({@see self::matterOptions()}) — CHỈ những vụ
 *    actor hiện tại `manageTeam` được (vụ `restricted` bị lọc khỏi danh sách này cho một trưởng
 *    phòng không phải admin/lead — không phải ẩn thêm một lớp UI, mà là KHÔNG BAO GIỜ vào tới
 *    mảng `options()` để mà hiện ra).
 * 3. Chọn nhiều vụ, chọn lead mới ({@see self::newLeadOptions()}, cùng tập vai
 *    `ViewMatter::reassignCandidateOptions()` chấp nhận), lý do, công tắc "giữ lead cũ".
 * 4. Bấm — {@see self::reassignSelected()} gọi {@see ReassignMatters}, hiện báo cáo từng vụ.
 *
 * **Payload bị ép ({@see self::reassignSelected()}) KHÔNG được tin.** `matter_ids` gửi lên có thể
 * mang một id KHÔNG nằm trong `matterOptions()` đã render (Livewire `set()` bỏ qua hẳn tầng hiển
 * thị) — trang KHÔNG lọc lại `matter_ids` theo `matterOptions()` trước khi gọi Action: đó là việc
 * của chính `ReassignMatter::handle()` (hỏi lại `manageTeam` là câu ĐẦU TIÊN nó làm, dưới khoá,
 * không tin bất kỳ trang nào đã lọc đúng — cùng kỷ luật toàn dự án). Một vụ bị ép vào payload mà
 * actor không `manageTeam` được nhận một dòng kết quả THẤT BẠI không mang mã/tiêu đề (xem docblock
 * {@see BulkReassignMatterResult}) — không lộ sự tồn tại của nó.
 *
 * `new_lead_id` VẪN được hỏi lại {@see self::newLeadOptions()} ở đúng lúc submit (không chỉ tin
 * Select đã lọc đúng) — cùng công thức `CreateMatter::mutateFormDataBeforeCreate()`'s
 * `leadLawyerOptions()`: Select tự cài một luật `in:` phía máy chủ, nhưng lớp phòng thủ tường minh
 * này không phụ thuộc vào việc cấu hình Select không đổi trong tương lai.
 *
 * # Mọi phương thức trả về danh sách/tuỳ chọn đều `private`/`protected`
 *
 * Cùng gotcha Livewire M6.5 Task 3 đã ghi (`TeamRelationManager::memberOptions()`,
 * `ViewMatter::reassignCandidateOptions()`): một phương thức `public` trên một trang Filament
 * (cũng là một component Livewire) là một điểm cuối GỌI ĐƯỢC TỪ XA, độc lập với việc ô chọn có
 * hiện nó ra hay không.
 */
class BulkReassign extends Page
{
    protected string $view = 'filament.admin.pages.bulk-reassign';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * `null` trước lần bấm đầu tiên; sau đó mảng kết quả từng vụ (đúng thứ tự đã chọn), kể cả vụ
     * thất bại — phán quyết controller Task 2 ("báo cáo kết quả từng vụ... thay vì một thông điệp
     * chung").
     *
     * **Mảng THUẦN (không phải đối tượng {@see BulkReassignMatterResult})** — Livewire chỉ
     * serialize được các kiểu nó có synth đăng ký sẵn (mảng, chuỗi, số, Model/Collection Eloquent,
     * enum…); một đối tượng thường (`final readonly class`) không có synth ném lỗi "Property type
     * not supported" ngay khi Livewire cố dehydrate thuộc tính công khai này sau lần gọi
     * {@see self::reassignSelected()} đầu tiên. {@see self::toResultRow()} dịch mỗi đối tượng kết
     * quả của Action sang một mảng phẳng TRƯỚC KHI gán vào đây.
     *
     * @var array<int, array{matterId: int, success: bool, message: string, matterCode: ?string, matterTitle: ?string, suggestIntroduction: bool}>|null
     */
    public ?array $results = null;

    public static function getNavigationLabel(): string
    {
        return __('reassign.bulk.navigation_label');
    }

    public function getTitle(): string
    {
        return __('reassign.bulk.page_title');
    }

    public static function canAccess(): bool
    {
        return Gate::forUser(Auth::user())->allows('bulkReassign');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** `$from` — tham số `?from=<user_id>` (liên kết từ thông điệp chặn nghỉ việc, R6). */
    public function mount(int|string|null $from = null): void
    {
        // Xem docblock lớp, mục "Cổng" — mount() tự hỏi lại, không chỉ tin canAccess() của panel.
        abort_unless(static::canAccess(), 404);

        $from ??= request()->query('from');

        $this->form->fill([
            'lead_lawyer_id' => filled($from) ? (int) $from : null,
            'matter_ids' => [],
            'new_lead_id' => null,
            'reason' => '',
            'keep_old_lead_as_associate' => true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('lead_lawyer_id')
                    ->label(__('reassign.bulk.fields.lead_lawyer_id'))
                    ->options(fn (): array => self::currentLeadOptions())
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('matter_ids', []);
                    })
                    ->required(),
                CheckboxList::make('matter_ids')
                    ->label(__('reassign.bulk.fields.matter_ids'))
                    ->options(fn (Get $get): array => self::matterOptions((int) ($get('lead_lawyer_id') ?? 0)))
                    ->live()
                    ->columns(1)
                    // `CheckboxList` mặc định tự suy "luật in:" từ CHÍNH `options()` đã render
                    // (`CanBeValidated::getInValidationRuleValues()` → `array_keys($this->
                    // getEnabledOptions())`) — một id KHÔNG còn trong `matterOptions()` LÚC
                    // SUBMIT (ví dụ vụ vừa bị bàn giao ở tab khác giữa lúc form đang mở, hoặc một
                    // vụ `restricted` một trưởng phòng không `manageTeam` được, bị ép vào payload)
                    // sẽ bị CHÍNH Livewire chặn ở tầng validate-form với một lỗi "đã chọn không
                    // hợp lệ" trên `matter_ids.*` — CHẶN CẢ LÔ, kể cả những vụ hợp lệ khác trong
                    // cùng lần submit, và KHÔNG BAO GIỜ chạm tới `ReassignMatters`. Đây chính là
                    // "lọc theo matterOptions() TRƯỚC KHI gọi Action" mà docblock lớp cấm — chỉ
                    // Filament tự làm việc đó thay vì trang. Ghi đè `in()` bằng danh sách RỘNG HƠN
                    // để luật "in:" chỉ còn giữ vai trò vệ sinh cơ bản — quyết định AI được bàn
                    // giao VỤ NÀO vẫn hoàn toàn thuộc về `manageTeam` bên trong Action, đúng ý đồ
                    // ban đầu.
                    //
                    // Fix round 2 (I2 — review needs_fixes 2026-09-28): danh sách RỘNG HƠN đó KHÔNG
                    // còn được dò từ `Matter::query()->pluck('id')` (id của những vụ THẬT SỰ còn
                    // tồn tại) — làm vậy biến chính luật "in:" thành một oracle tồn tại. Một id
                    // CHƯA TỪNG được cấp cho vụ nào (hoặc đã xoá mềm) bị luật đó chặn NGAY tại đây,
                    // thành một LỖI FORM trên `matter_ids.0`; còn id của một vụ `restricted` CÓ
                    // THẬT (một trưởng phòng không `manageTeam` được) lại QUA được luật này, chạm
                    // tới tận `ReassignMatters` và hiện một DÒNG KẾT QUẢ. Hai HÌNH DẠNG phản hồi
                    // khác nhau (lỗi form ≠ dòng kết quả) đã đủ để một trưởng phòng dò lần lượt
                    // nhiều id liên tiếp và đếm ra chính xác bao nhiêu vụ `restricted` đang tồn tại
                    // — đúng thứ "vụ không hiện thì không lộ... số lượng" (brief Task 2) cấm, dù
                    // không một mã/tiêu đề nào rời khỏi response ở CẢ HAI hình dạng đó riêng lẻ.
                    // Thay bằng một DẢI LIÊN TỤC [1, id lớn nhất TỪNG được cấp phát, kể cả cho một
                    // vụ đã xoá mềm] (`withTrashed()->max('id')`) — không còn hỏi CSDL "id này có
                    // ĐANG là một vụ việc không", chỉ hỏi "id này có nằm trong khoảng TỪNG được cấp
                    // không". Mọi vụ `restricted` đang tồn tại chắc chắn nằm trong dải này (không
                    // vụ nào có id vượt quá kim chỉ auto-increment hiện tại), nên KHÔNG còn id thật
                    // nào của một vụ restricted bị luật này chặn riêng biệt so với một id chưa từng
                    // cấp phát NẰM TRONG cùng dải — cả hai đều qua được lớp này, và ở lớp
                    // `ReassignMatters` (xem docblock của nó) giờ nhận CHUNG một câu trả lời trung
                    // lập. Một id NGOÀI dải (lớn hơn kim chỉ hiện tại) vẫn bị chặn ở đây, nhưng
                    // không còn gì để dò theo id cụ thể nữa — CHƯA có vụ `restricted` nào (hay vụ
                    // nào khác) có thể mang một id như vậy.
                    ->in(fn (): array => range(1, max((int) (Matter::withTrashed()->max('id') ?? 0), 1))),
                Select::make('new_lead_id')
                    ->label(__('reassign.bulk.fields.new_lead_id'))
                    ->options(fn (Get $get): array => self::newLeadOptions(filled($get('lead_lawyer_id')) ? (int) $get('lead_lawyer_id') : null))
                    ->searchable()
                    ->native(false)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('reassign.bulk.fields.reason'))
                    ->rows(3)
                    ->required()
                    // Cùng trần chủ động của ViewMatter::reassignAction() — xem docblock ở đó.
                    ->maxLength(5000)
                    ->columnSpanFull(),
                // KHÔNG có nhánh visible() riêng cho vụ restricted (khác Toggle tương ứng của
                // ViewMatter::reassignAction()): một lô có thể trộn vụ thường và vụ hạn chế, và
                // ẩn/hiện MỘT công tắc theo TỪNG vụ đã chọn không có nghĩa. ReassignMatters tự ép
                // false cho từng vụ restricted bất kể giá trị ở đây (xem docblock của nó) —
                // helperText nói thẳng điều đó thay vì ẩn ô.
                Toggle::make('keep_old_lead_as_associate')
                    ->label(__('reassign.bulk.fields.keep_old_lead_as_associate'))
                    ->helperText(__('reassign.bulk.fields.keep_old_lead_as_associate_hint'))
                    ->default(true),
            ]);
    }

    /**
     * Hành động THẬT (M7 Task 2) — hỏi lại `Gate` (xem docblock lớp), hỏi lại
     * {@see self::newLeadOptions()} cho `new_lead_id` (Select tự cài `in:` phía máy chủ, đây là
     * lớp thứ hai cố ý — cùng công thức `CreateMatter`), rồi gọi {@see ReassignMatters}.
     *
     * `matter_ids` KHÔNG được lọc lại theo {@see self::matterOptions()} trước khi gọi Action — xem
     * docblock lớp, mục "Payload bị ép... KHÔNG được tin": việc hỏi lại `manageTeam` cho TỪNG vụ
     * là việc của Action, không phải của trang.
     *
     * `$leadLawyerId` (đã hỏi lại {@see self::newLeadOptions()} bên trên cho `new_lead_id`) được
     * truyền TIẾP xuống {@see ReassignMatters::handle()} làm `$expectedLeadId` — fix round 1,
     * finding 1: mỗi vụ trong lô phải ĐÚNG còn do người này phụ trách (hoặc bị từ chối) tại thời
     * điểm bàn giao, không chỉ tại thời điểm màn hình dựng danh sách. Xem docblock
     * {@see ReassignMatter}, mục "$expectedLeadId", cho toàn bộ lý lẽ.
     */
    public function reassignSelected(): void
    {
        abort_unless(static::canAccess(), 404);

        $data = $this->form->getState();

        $matterIds = array_map('intval', $data['matter_ids'] ?? []);

        if ($matterIds === []) {
            throw ValidationException::withMessages([
                $this->errorKey('matter_ids') => [__('reassign.bulk.validation.no_matters_selected')],
            ]);
        }

        $newLeadId = (int) ($data['new_lead_id'] ?? 0);
        $leadLawyerId = filled($data['lead_lawyer_id'] ?? null) ? (int) $data['lead_lawyer_id'] : null;

        abort_unless(array_key_exists($newLeadId, self::newLeadOptions($leadLawyerId)), 404);

        // Fix round 1, finding 1: `lead_lawyer_id` là bắt buộc trên form (`Select::required()`),
        // nên `$this->form->getState()` ngay trên đã tự ném ValidationException nếu ô này trống
        // — tới đây `$leadLawyerId` luôn khác `null`. Giữ một khẳng định tường minh (thay vì ép
        // kiểu âm thầm) để một thay đổi tương lai bỏ `required()` khỏi form không lặng lẽ truyền
        // `expectedLeadId` sai xuống Action.
        if ($leadLawyerId === null) {
            throw ValidationException::withMessages([
                $this->errorKey('lead_lawyer_id') => [__('validation.required', ['attribute' => __('reassign.bulk.fields.lead_lawyer_id')])],
            ]);
        }

        /** @var User $actor */
        $actor = Auth::user();

        $actionResults = app(ReassignMatters::class)->handle(
            matterIds: $matterIds,
            actor: $actor,
            newLead: User::query()->findOrFail($newLeadId),
            reason: (string) ($data['reason'] ?? ''),
            keepOldLeadAsAssociate: (bool) ($data['keep_old_lead_as_associate'] ?? false),
            expectedLeadId: $leadLawyerId,
        );

        $this->results = array_map(self::toResultRow(...), $actionResults);

        $successCount = collect($this->results)->where('success', true)->count();
        $failureCount = count($this->results) - $successCount;

        // Fix round 1, finding 4 — tiêu đề VÀ màu phải khớp kết quả thật, không cứng
        // 'reassign.action.success' + ->success() cho mọi trường hợp (câu đó hứa một điều chưa
        // chắc đã xảy ra khi $failureCount > 0, và nói dối hoàn toàn khi $successCount === 0).
        $notification = Notification::make()
            ->title(__(match (true) {
                $failureCount === 0 => 'reassign.bulk.notification_titles.success',
                $successCount === 0 => 'reassign.bulk.notification_titles.failure',
                default => 'reassign.bulk.notification_titles.partial',
            }))
            ->body(__('reassign.bulk.notification_body', ['success' => $successCount, 'failure' => $failureCount]));

        match (true) {
            $failureCount === 0 => $notification->success(),
            $successCount === 0 => $notification->danger(),
            default => $notification->warning(),
        };

        $notification->send();

        // Chọn lại từ đầu cho vụ vừa xử lý xong — giữ nguyên lead_lawyer_id/new_lead_id để dễ mở
        // tiếp một lô khác của cùng người, cùng người nhận.
        $this->form->fill([
            ...$data,
            'matter_ids' => [],
            'reason' => '',
        ]);
    }

    /**
     * Dịch một {@see BulkReassignMatterResult} sang mảng phẳng — xem docblock
     * {@see self::$results} cho lý do (Livewire không serialize được đối tượng thường).
     *
     * @return array{matterId: int, success: bool, message: string, matterCode: ?string, matterTitle: ?string, suggestIntroduction: bool}
     */
    private static function toResultRow(BulkReassignMatterResult $result): array
    {
        return [
            'matterId' => $result->matterId,
            'success' => $result->success,
            'message' => $result->message,
            'matterCode' => $result->matterCode,
            'matterTitle' => $result->matterTitle,
            'suggestIntroduction' => $result->suggestIntroduction,
        ];
    }

    /**
     * Người "đang phụ trách" để chọn ở đầu trang — bất kỳ nhân sự nào hiện đứng tên
     * `lead_lawyer_id` trên ít nhất một vụ việc ĐANG MỞ mà ACTOR HIỆN TẠI `manageTeam` được (fix
     * round 1, finding 2 — CÙNG bộ lọc {@see self::matterOptions()} dùng, không phải một luật
     * riêng), kể cả một tài khoản đã bị vô hiệu hoá/xoá mềm qua một đường khác (`withTrashed()`)
     * — R7 (M6.5 Task 4) chặn vô hiệu hoá/xoá MỘT người còn dẫn vụ mở, nên trong luồng bình
     * thường người này luôn còn hoạt động; giữ `withTrashed()` chỉ để phòng một trạng thái không
     * nhất quán đã có từ trước luật đó, để trang này vẫn còn cách bàn giao nốt việc của họ.
     *
     * **Vì sao cần lọc — bản trước KHÔNG lọc (finding 2).** Danh sách dựng từ MỌI vụ đang mở, kể
     * cả vụ `restricted` mà actor không xem được. Một trưởng phòng không phải admin/lead của một
     * vụ `restricted` sẽ vẫn thấy TÊN của lead vụ đó xuất hiện ở ô chọn này, dù chọn xong danh
     * sách vụ việc lại rỗng (`matterOptions()` đã lọc đúng) — bản thân sự có mặt của cái tên đó
     * đã LỘ "người này đang phụ trách ít nhất một vụ hạn chế", một kênh rò rỉ MỚI: trang quản lý
     * nhân sự vốn chỉ admin xem được, nên trước bản sửa này trưởng phòng không có cách nào biết
     * chuyện đó qua đường quản lý nhân sự — cùng luật "vụ restricted không bao giờ lộ mã/tiêu
     * đề/khách/SỐ LƯỢNG" (ràng buộc toàn cục của làn) mở rộng thêm một kênh nữa là "có mặt trong
     * danh sách". Lọc bằng `Gate::allows('manageTeam', ...)` — CÙNG câu `matterOptions()` hỏi cho
     * TỪNG vụ — đóng đúng kênh đó: một lead chỉ dẫn vụ `restricted` mà actor không quản lý được
     * hoàn toàn KHÔNG xuất hiện trong mảng trả về, dù họ có đang dẫn vụ mở nào khác hay không.
     *
     * KHÔNG lọc theo vai trò: một vụ việc có thể còn đứng tên `lead_lawyer_id` của một người đã
     * đổi chức danh sang Trợ lý/Kế toán (cột đó không tự đổi theo — xem docblock
     * `MatterPolicy::manageTeam()`), và người đó vẫn cần bàn giao được vụ việc cũ của mình.
     *
     * `->with('team')` — cùng lý do {@see self::matterOptions()} nạp trước: nhánh trong-bộ-nhớ
     * của `MatterPolicy::view()`.
     *
     * @return array<int, string>
     */
    private static function currentLeadOptions(): array
    {
        $actor = Auth::user();

        if (! ($actor instanceof User)) {
            return [];
        }

        $leadIds = Matter::query()
            ->open()
            ->whereNotNull('lead_lawyer_id')
            ->with('team')
            ->get()
            ->filter(fn (Matter $matter): bool => Gate::forUser($actor)->allows('manageTeam', $matter))
            ->pluck('lead_lawyer_id')
            ->unique();

        return User::query()
            ->withTrashed()
            ->whereIn('id', $leadIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Vụ việc ĐANG MỞ của `$leadLawyerId`, CHỈ những vụ actor hiện tại `manageTeam` được (M6.5
     * Task 3's `MatterPolicy::manageTeam()` — lead của chính vụ đó, manager được `view()`, hoặc
     * admin, và không phải trợ lý). Vụ `restricted` mà actor không phải admin/lead của CHÍNH vụ
     * đó KHÔNG BAO GIỜ vào tới mảng trả về — không phải một hàng bị ẩn, mà là một hàng CHƯA TỪNG
     * được tính (không mã/tiêu đề/khách nào rời khỏi hàm này để mà lộ).
     *
     * `->with('team')` để nhánh trong-bộ-nhớ của `MatterPolicy::view()` chạy (xem docblock hàm
     * đó) — cùng ngân sách truy vấn `MyMatters::buildCards()` đã ghim, không phải tối ưu tuỳ chọn.
     *
     * @return array<int, string>
     */
    private static function matterOptions(int $leadLawyerId): array
    {
        if ($leadLawyerId <= 0) {
            return [];
        }

        $actor = Auth::user();

        if (! ($actor instanceof User)) {
            return [];
        }

        return Matter::query()
            ->open()
            ->where('lead_lawyer_id', $leadLawyerId)
            ->with('team')
            ->get()
            ->filter(fn (Matter $matter): bool => Gate::forUser($actor)->allows('manageTeam', $matter))
            ->mapWithKeys(fn (Matter $matter): array => [$matter->getKey() => "{$matter->code} — {$matter->title}"])
            ->all();
    }

    /**
     * Ứng viên lead mới — CÙNG tập vai `ViewMatter::reassignCandidateOptions()` chấp nhận (luật sư
     * hoặc trưởng phòng, đang hoạt động), trừ chính người "đang phụ trách" đã chọn ở đầu trang
     * (không hợp lý bàn giao một lô vụ việc VỀ LẠI đúng người đang giữ chúng).
     *
     * @return array<int, string>
     */
    private static function newLeadOptions(?int $excludeUserId): array
    {
        return User::query()
            ->where('is_active', true)
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole(StaffRole::Lawyer->value) || $user->hasRole(StaffRole::Manager->value))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * `{schema-state-path}.{field}` — cùng công thức `EditUser::errorKey()`/
     * `PartiesRelationManager::errorKey()`: một khoá TRẦN không khớp state path thật của form
     * (`data.matter_ids`, không phải `matter_ids`) thì Filament coi khoá đó không thuộc field nào
     * và không hiện lỗi ở đâu cả.
     */
    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }

    /**
     * Liên kết tới trang này, mở sẵn với `$target` (R6, brief Task 2: "thêm liên kết tới màn hình
     * này vào thông điệp chặn nghỉ việc của M6.5") — dùng bởi `EditUser` (tắt `is_active`, và
     * `DeleteAction`) khi `GuardsStaffOffboarding` chặn vì người này CÒN DẪN một vụ việc mở.
     *
     * `null` trong HAI trường hợp: `$target` không còn vụ việc lead nào đang mở (liên kết tới một
     * màn hình rỗng không giúp gì — kiểm tra TRỰC TIẾP qua {@see OpenWork}, không suy ra từ nội
     * dung chuỗi lý do, vì chuỗi đó có thể gộp cả ba loại việc và không phải lúc nào cũng có mảnh
     * `leadMatters`), hoặc người đang xem (actor) không {@see self::canAccess()} được chính trang
     * này — một luật sư tự bấm "Xoá" cho đồng nghiệp (nếu ai đó mở được nút này cho họ) không nên
     * thấy một liên kết dẫn tới trang họ sẽ bị 404.
     */
    public static function offboardingLinkAction(User $target): ?Action
    {
        if (OpenWork::forUser($target)->leadMatters->isEmpty()) {
            return null;
        }

        if (! static::canAccess()) {
            return null;
        }

        return Action::make('openBulkReassign')
            ->label(__('reassign.bulk.action_label'))
            ->url(static::getUrl(['from' => $target->getKey()], panel: 'admin'))
            ->button();
    }
}
