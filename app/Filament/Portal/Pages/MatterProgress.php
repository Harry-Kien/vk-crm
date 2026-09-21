<?php

namespace App\Filament\Portal\Pages;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Portal\RecordStageLogView;
use App\Enums\ChecklistItemStatus;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterTypeStage;
use App\Models\StageLog;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Chi tiết một hồ sơ trên cổng khách hàng — SPEC §8.3, bảy khối dọc, đúng thứ tự SPEC liệt kê.
 *
 * Đây là màn hình mà cả M0–M4 tồn tại để dẫn tới: một khách hàng, trên điện thoại, đọc vụ việc
 * của mình đang ở đâu, còn phải làm gì, và văn phòng đã nói gì với mình. Bảy khối, theo thứ tự:
 * tình trạng hiện tại, việc anh/chị cần làm (**chỉ hiện khi có**), diễn biến, hồ sơ giấy tờ,
 * tài liệu, mốc thời hạn, gửi yêu cầu. Không có khối thứ tám: `communication_logs` **không** lên
 * cổng (phán quyết của người điều phối M5, 19/09/2026 — SPEC §5 không liệt kê nó, và thêm một
 * model vào danh sách đó là một việc cần chữ ký của văn phòng, không phải một mặc định).
 *
 * # Trang này tự hỏi `Gate`, và nó BẮT BUỘC phải tự hỏi
 *
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` **mặc định trả `true`** cho mọi trang
 * tuỳ chỉnh — đọc trong `vendor/filament/filament/src/Pages/Concerns/CanAuthorizeAccess.php` ở
 * Task 2, không phải nhớ. Và nó là `static`, nên nó không có cách nào nhìn thấy `{record}` trên
 * URL. Hệ quả: `AnswerDeniedPanelRequestsWithNotFound` chỉ đổi **hình dạng** của một lời từ chối
 * đã có sẵn, nó không bao giờ tự sinh ra một lời từ chối. Một trang mang tham số bản ghi mà không
 * tự hỏi quyền là một trang **mở**.
 *
 * Nên {@see self::resolveMatter()} hỏi `Gate::forUser()` tường minh. `forUser()` chứ không
 * `Gate::allows()`: facade mặc định phân giải người dùng qua guard `web`, và trên cổng khách
 * hàng guard đó trống — một lần hỏi như vậy trả lời về `null`, không về khách đang đọc.
 *
 * **Và nó hỏi lại ở MỌI request, không chỉ ở lần tải đầu.** Livewire chỉ mang theo thuộc tính
 * CÔNG KHAI giữa hai request; phần còn lại được dựng lại từ đầu. Nên `{@see self::$resolvedMatter}`
 * và các bộ nhớ đệm khác ở đây là `private` một cách có chủ ý: chúng là bộ nhớ đệm TRONG MỘT
 * REQUEST, không phải trạng thái của component. Thứ duy nhất đi qua được là `$record` — một con
 * số — và từ con số đó thì {@see self::resolveMatter()} bắt buộc phải chạy lại. Đổi một trong
 * chúng thành `public` là để bản ghi đã được gác trôi qua từng lần cập nhật mà không ai hỏi lại
 * quyền; có test ghim (`MatterProgressTest`, "carries no authorised record on a public property").
 *
 * **`abort(404)` thẳng, không `abort(403)` rồi trông vào middleware.** SPEC §10.10 đòi "không có
 * quyền" và "không tồn tại" cùng một câu trả lời; middleware 404 phủ được request tải trang
 * nhưng **không** phủ request cập nhật Livewire (Task 2 đã chứng minh trong vendor: middleware
 * persistent chạy với một response stub 200 trước khi hydrate), và toàn bộ cổng này là Livewire.
 * Một lời từ chối chỉ đúng hình dạng ở một nửa số đường đi là một lời từ chối tự kể ra sự khác
 * nhau giữa hai nửa.
 *
 * # Ba tầng, và tầng nào đang giữ cái gì trên chính trang này
 *
 *  1. **Truy vấn** — `ClientPortalScope` cắt mọi truy vấn theo khách đang đăng nhập. Không một
 *     dòng nào ở đây viết `where('client_id', ...)`.
 *  2. **Quyền** — mỗi tập hợp còn đi qua một lần `Gate::allows('view', ...)` trên TỪNG bản ghi
 *     ({@see self::timeline()}, {@see self::documents()}, {@see self::deadlines()},
 *     {@see self::checklistItems()}). Đó không phải phòng thủ thừa: nó là tầng thứ hai của nghi
 *     thức ba tầng, và với ba tập đầu nó là thứ DUY NHẤT còn đứng khi tầng truy vấn quên một câu
 *     `where`. **`checklistItems()` là ngoại lệ và sắc thái của nó được viết ra ở chính docblock
 *     của phương thức đó** — ở đấy quan hệ cha đã giữ phạm vi, nên lần hỏi `Gate` sống sót một
 *     lần đột biến và được giữ vì một lý do khác, cũng đã đo. Đo
 *     được ở `MatterProgressTest`, test "keeps a group D document off the page even with both
 *     client flags on and the scope emptied": scope bị thay bằng một scope rỗng, hai cờ khách
 *     bật thẳng trong bảng, và tài liệu nhóm D vẫn không lên trang — xoá lần hỏi `Gate` trong
 *     {@see self::documents()} thì test đó đỏ.
 *  3. **Serialize** — `HidesInternalAttributesFromPortal`.
 *
 * **`internal_note` không được nhắc tới ở bất kỳ đâu trong lớp này lẫn trong view của nó.** Task
 * 2 đã ghim ra một ranh giới mà không ai nên phải tự phát hiện lại: trait kia chỉ chặn ở
 * `attributesToArray()`, nên `$log->internal_note` trong Blade **trả về chuỗi thật** và một câu
 * `where('internal_note', ...)` là một vị từ SQL hợp lệ. Điều SPEC §11 hứa (không bao giờ có
 * trong HTML/JSON của cổng) đúng vì không đường serialize nào mang nó ra — không phải vì cột đó
 * không với tới được. Luật ở đây vì thế là một luật về mã nguồn: **không nhắc tên cột đó**.
 *
 * # Biên bản đã xem — và "Khách đã xem" ở panel nội bộ giờ nghĩa là gì
 *
 * Mỗi dòng đã công bố **được trang này vẽ ra** sinh một lần gọi
 * {@see RecordStageLogView} (Task 2, nơi ghi duy nhất của bảng
 * `stage_log_views`; trang này không bao giờ ghi thẳng vào bảng). Cách đọc đã chốt, và nó phải
 * được nói ra vì đây là **bằng chứng pháp lý**, không phải một con số thống kê:
 *
 *  - Ghi khi khách **mở trang chi tiết hồ sơ** và dòng đó nằm trong phần trang vẽ ra. **Không**
 *    ghi khi dòng chỉ xuất hiện trong một danh sách — "đã lướt qua trong một danh sách" không
 *    phải "đã được cho xem cập nhật" (phán quyết người điều phối M5, 19/09/2026).
 *  - **Không** ghi theo kiểu "dòng đã thật sự hiện ra trước mắt" (giao nhau với khung nhìn). Cách
 *    đó đúng nghĩa hơn với chữ "đã xem", nhưng nó phụ thuộc vào JavaScript chạy trên máy khách,
 *    tức vào một thứ người phản biện tắt đi được — một bằng chứng như vậy yếu hơn, không mạnh
 *    hơn. Trang không phân trang dòng thời gian, nên "trang đã vẽ ra" ở đây bằng đúng "toàn bộ
 *    các dòng đã công bố của hồ sơ". **Nếu có ngày dòng thời gian được phân trang, cách đọc này
 *    đổi nghĩa** và phải được quyết lại chứ không được thừa kế trong im lặng.
 *
 * Vậy nhãn **"Khách đã xem lúc …"** ở tab Tiến độ của panel nội bộ (SPEC §7.2, §4.18) từ nay đọc
 * đúng là: *tài khoản portal này đã mở trang chi tiết của hồ sơ, và dòng cập nhật này nằm trong
 * trang được gửi tới trình duyệt của họ, vào thời điểm đó, từ địa chỉ IP đó*. Nó **không** khẳng
 * định người đó đã cuộn xuống tới dòng ấy, đã đọc, hay đã hiểu. Luật sư đọc nhãn đó để quyết định
 * có cần gọi điện hay không; văn phòng đưa nó ra để chứng minh mình đã ĐƯA TIN. Hai việc đó cần
 * đúng mức khẳng định trên, không hơn — và câu này nằm ở đây để người viết màn hình nội bộ
 * (Task 6) và người đứng trước một câu hỏi pháp lý về sau đọc cùng một định nghĩa.
 *
 * Dấu thời gian là của **lần mở đầu tiên** và không bao giờ bị dời — hợp đồng đó thuộc về
 * `RecordStageLogView`, và trang này không được phép làm yếu nó đi.
 *
 * # Rủi ro mang tiếp sang M6, ghi ra thay vì để nó tự xuất hiện
 *
 * Tắt rồi bật lại `is_published_to_portal` trên một hồ sơ làm **cả một loạt dòng đã công bố hiện
 * ra cùng lúc trên chính màn hình này**, với `notified_at` chưa được đặt lại —
 * `MatterNotPublishedToPortal` chỉ chặn ở thời điểm TẠO dòng. M5 không sửa việc đó (M6 sở hữu
 * nửa thông báo), nhưng hệ quả nhìn thấy được nằm ở đây: khách mở trang và gặp một chồng cập
 * nhật cũ như thể tất cả vừa xảy ra, và mỗi dòng trong chồng đó sinh một biên bản "đã xem" mang
 * dấu thời gian của hôm nay.
 */
class MatterProgress extends Page
{
    protected string $view = 'filament.portal.pages.matter-progress';

    /**
     * Đường dẫn tiếng Việt không dấu: khách hàng thỉnh thoảng đọc URL ra qua điện thoại cho trợ
     * lý. `/portal/ho-so/12` nói được thành lời; `/portal/matter-progress/12` thì không.
     */
    protected static ?string $slug = 'ho-so';

    /**
     * Không vào thanh điều hướng: trang này luôn nói về MỘT hồ sơ, nên một mục menu không có
     * tham số thì không trỏ đi đâu cả. Lối vào là thẻ hồ sơ ở danh sách (SPEC §8.2, Task 3).
     */
    protected static bool $shouldRegisterNavigation = false;

    /** Tham số trên URL. Không dùng model binding: bản ghi được đọc lại qua truy vấn đã có scope. */
    public int|string $record;

    private ?Matter $resolvedMatter = null;

    /** @var Collection<int, StageLog>|null */
    private ?Collection $resolvedTimeline = null;

    /** @var Collection<int, MatterChecklistItem>|null */
    private ?Collection $resolvedChecklist = null;

    /** @var Collection<int, Document>|null */
    private ?Collection $resolvedDocuments = null;

    /** @var Collection<int, Deadline>|null */
    private ?Collection $resolvedDeadlines = null;

    /**
     * `{record}` nối vào đường dẫn ở đây chứ không ở `$slug`: `getRelativeRouteName()` dựng tên
     * route từ chính `getSlug()`, nên nhét tham số vào slug sẽ sinh ra một tên route mang dấu
     * ngoặc nhọn.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{record}';
    }

    public function mount(int|string $record): void
    {
        $this->record = $record;

        // Giải quyết NGAY ở mount: một lời từ chối phải là 404 của chính request tải trang, chứ
        // không phải một trang dựng xong rồi mới vỡ ở giữa lúc render.
        $this->matter();
    }

    public function getTitle(): string|Htmlable
    {
        return __('portal_progress.title', ['code' => $this->matter()->code]);
    }

    public function getHeading(): string|Htmlable
    {
        return $this->matter()->title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('portal_progress.subheading', ['code' => $this->matter()->code]);
    }

    public function matter(): Matter
    {
        return $this->resolvedMatter ??= $this->resolveMatter();
    }

    // -------------------------------------------------------------------------------------
    // Khối 1 — Tình trạng hiện tại
    // -------------------------------------------------------------------------------------

    /**
     * Giai đoạn hiện tại, để view lấy `client_label` và `client_description`.
     *
     * **Không bao giờ `label`.** Cột `label` là chữ của văn phòng ("Thu thập hồ sơ"); `client_label`
     * là chữ viết cho khách ("Đang thu thập giấy tờ"). SPEC §8.2 và §8.3 đều chỉ đích danh cột
     * thứ hai, và §8 cấm thuật ngữ — nên một lần đọc nhầm cột ở đây là đưa ngôn ngữ nội bộ ra
     * trước mặt khách.
     *
     * `null` khi loại vụ việc không còn cấu hình giai đoạn này (quản trị viên xoá mềm một giai
     * đoạn trong khi một hồ sơ đang đứng ở đó). View có câu riêng cho trường hợp ấy.
     */
    public function currentStage(): ?MatterTypeStage
    {
        return $this->matter()->currentStage();
    }

    // -------------------------------------------------------------------------------------
    // Khối 2 — Việc anh/chị cần làm (CHỈ hiện khi có)
    // -------------------------------------------------------------------------------------

    /**
     * SPEC §8.3 mục 2: khối này **biến mất hoàn toàn** khi không có việc — không phải hiện một
     * dòng "hiện không có việc gì". Một ô nổi bật nói "không có gì" vẫn chiếm chỗ nổi bật nhất
     * màn hình để nói một điều không ai cần biết, và trên màn hình 375px thì chỗ đó là thứ đắt
     * nhất đang có.
     *
     * Hai vế, độc lập với nhau, mỗi vế một mình đủ làm khối hiện ra: giấy tờ đang còn chờ ở
     * khách, và `client_action` của dòng cập nhật **mới nhất**.
     */
    public function hasTodo(): bool
    {
        return $this->outstandingItems()->isNotEmpty() || filled($this->latestClientAction());
    }

    /**
     * Những đầu mục đang chờ ở KHÁCH: `missing` (chưa nộp) và `rejected` (đã nộp nhưng cần nộp
     * lại). `pending_review` cố ý không có mặt — nó đang chờ ở VĂN PHÒNG, và liệt kê nó vào "việc
     * anh/chị cần làm" là giục khách làm một việc họ đã làm xong.
     *
     * @return Collection<int, MatterChecklistItem>
     */
    public function outstandingItems(): Collection
    {
        return $this->checklistItems()->filter(
            fn (MatterChecklistItem $item): bool => in_array(
                $item->status,
                [ChecklistItemStatus::Missing, ChecklistItemStatus::Rejected],
                true,
            ),
        )->values();
    }

    /**
     * `client_action` của dòng cập nhật MỚI NHẤT, không phải của mọi dòng.
     *
     * SPEC §4.8 viết thẳng: "để trống nghĩa là không cần làm gì". Và chỉ dòng mới nhất mới nói
     * về hiện tại — gom `client_action` của mọi dòng lại sẽ dựng ra một danh sách việc mà phần
     * lớn đã xong từ lâu, tức đúng loại màn hình khiến người ta thôi đọc. Những câu cũ vẫn còn
     * nguyên ở dòng thời gian, đúng chỗ của chúng trong lịch sử.
     */
    public function latestClientAction(): ?string
    {
        $latest = $this->timeline()->first()?->client_action;

        return filled($latest) ? $latest : null;
    }

    // -------------------------------------------------------------------------------------
    // Khối 3 — Diễn biến vụ việc
    // -------------------------------------------------------------------------------------

    /**
     * Các dòng đã công bố, mới nhất trên cùng — và **nơi biên bản "đã xem" được ghi**.
     *
     * Quan hệ `Matter::stageLogs()` đã sắp xếp giảm dần theo `occurred_at`, và global scope đã
     * cắt về các dòng `is_published = true` thuộc hồ sơ khách thấy được. Lần lọc qua `Gate` bên
     * dưới là tầng thứ hai, độc lập: `StageLogPolicy::view()` đọc thẳng `is_published` trên bản
     * ghi thay vì tin vào câu `where` kia.
     *
     * Ghi biên bản ngay sau khi tập hợp đã chốt, nên nó ghi **đúng những dòng trang vẽ ra**, không
     * nhiều hơn. `RecordStageLogView` tự hỏi lại quyền và tự chống hai tab cùng lúc; trang chỉ
     * đưa cho nó danh tính người đọc và địa chỉ IP.
     *
     * **Một lỗi ở đây được để nổ ra, có chủ ý.** `RecordStageLogView` chỉ từ chối khi khách thật
     * sự không được xem dòng đó (hồ sơ vừa bị rút khỏi cổng, tài khoản vừa bị vô hiệu hoá giữa
     * hai câu truy vấn) — và khi đó họ cũng không được xem cả trang, nên một lời từ chối là câu
     * trả lời đúng. Nuốt lỗi đi thì đổi lại được một trang vẽ xong mà **không có bằng chứng nào
     * được ghi**, tức đúng thứ hỏng mà không ai nhìn thấy cho tới ngày cần tới bảng này.
     *
     * @return Collection<int, StageLog>
     */
    public function timeline(): Collection
    {
        if ($this->resolvedTimeline !== null) {
            return $this->resolvedTimeline;
        }

        $viewer = $this->viewer();

        $logs = $this->matter()->stageLogs()->get()
            ->filter(fn (StageLog $log): bool => Gate::forUser($viewer)->allows('view', $log))
            ->values();

        $receipts = app(RecordStageLogView::class);

        $logs->each(fn (StageLog $log) => $receipts->handle($log, $viewer, request()->ip()));

        return $this->resolvedTimeline = $logs;
    }

    /**
     * **`from_stage === to_stage` là dòng KHÔNG đổi giai đoạn, không phải `from_stage === null`.**
     *
     * SPEC §4.8 và §6.3 mâu thuẫn nhau ở đúng câu này, và M3 đã đi theo §6.3 — §6.3 viết thẳng
     * "Lúc đó `from_stage` và `to_stage` đều bằng giai đoạn hiện tại". Đọc theo §4.8 (`null` là
     * dòng không đổi) thì một dòng `filed → filed` sẽ được vẽ ra như một lần chuyển giai đoạn,
     * tức kể sai lịch sử vụ việc cho đúng người có quyền được kể đúng. Có test phân biệt được
     * hai cách đọc ở `MatterProgressTest`.
     */
    public function isStageChange(StageLog $log): bool
    {
        return $log->from_stage !== $log->to_stage;
    }

    /** Nhãn dễ hiểu của giai đoạn một dòng chuyển tới; `null` nếu loại vụ việc không còn khai báo nó. */
    public function stageLabel(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        return $this->matter()->matterType->stage($key)?->client_label;
    }

    // -------------------------------------------------------------------------------------
    // Khối 4 — Hồ sơ giấy tờ
    // -------------------------------------------------------------------------------------

    /**
     * Danh mục giấy tờ của hồ sơ.
     *
     * **Lần hỏi `Gate` ở đây SỐNG SÓT một lần đột biến, và câu đó phải được nói ra thay vì để
     * người đọc sau tưởng nó đang giữ một điều kiện.** Đo được: xoá dòng `filter()` bên dưới thì
     * KHÔNG test nào đỏ — vì `$this->matter()->checklistItems()` đã giới hạn theo đúng hồ sơ mà
     * {@see self::resolveMatter()} vừa gác, nên một đầu mục của hồ sơ khác không có đường nào
     * vào tập hợp này để mà bị từ chối.
     *
     * Nó được GIỮ, và lý do cũng là một phép đo chứ không phải một linh cảm. Thay quan hệ bằng
     * một truy vấn trần (`MatterChecklistItem::query()->get()`) — đúng hình dạng "ai đó đổi cách
     * lấy dữ liệu" — cho hai kết quả khác nhau:
     *
     *  - **giữ** dòng `filter()`: bộ test vẫn XANH, tức chính lần hỏi `Gate` này là thứ đang giữ;
     *  - **xoá** nó cùng lúc: test "keeps a checklist item of another matter off the page when
     *    its scope forgets its rule" ĐỎ, và một đầu mục của khách hàng khác lên thẳng trang.
     *
     * Vậy hai thứ ở đây không phải một thứ nói hai lần: quan hệ giữ phạm vi, `Gate` giữ quyền, và
     * mỗi cái đỡ được lần quên của cái kia. Xoá dòng này vì "không test nào đỏ" là gỡ đúng cái
     * lưới sẽ đỡ lần sửa sau.
     *
     * @return Collection<int, MatterChecklistItem>
     */
    public function checklistItems(): Collection
    {
        $viewer = $this->viewer();

        return $this->resolvedChecklist ??= $this->matter()->checklistItems()->get()
            ->filter(fn (MatterChecklistItem $item): bool => Gate::forUser($viewer)->allows('view', $item))
            ->values();
    }

    /**
     * `Đã nộp X / Y` đến từ `App\Actions\Document\ChecklistProgress` và **không được tính lại ở
     * đây**. Công thức có một đính chính ghi ngày trong SPEC §4.10 (`Y` là một TẬP HỢP, `X` đếm
     * bên trong nó, và "đã có tài liệu" nghĩa là có tài liệu không thuộc nhóm D), và M4 đã đo
     * thấy bản cũ trả HAI con số khác nhau cho cùng một hồ sơ tuỳ guard nào đang mở. Một phép
     * đếm viết lại lần thứ hai ở màn hình khách là cách chắc chắn nhất để khách và văn phòng đọc
     * hai con số khác nhau về cùng một tập giấy tờ.
     *
     * @return array{submitted: int, total: int}
     */
    public function checklistProgress(): array
    {
        return app(ChecklistProgress::class)->handle($this->matter());
    }

    /**
     * Lý do từ chối, **đầy đủ, không cắt ngắn** (SPEC §8.3 mục 4, §4.10).
     *
     * Câu đó có ràng buộc tối thiểu 20 ký tự ở tầng Action chính vì nó được viết ra cho khách
     * đọc và làm theo. Một dấu "…" ở đây biến một hướng dẫn thành một lời trách.
     */
    public function rejectionReason(MatterChecklistItem $item): ?string
    {
        return $item->status === ChecklistItemStatus::Rejected && filled($item->rejection_reason)
            ? $item->rejection_reason
            : null;
    }

    // -------------------------------------------------------------------------------------
    // Khối 5 — Tài liệu
    // -------------------------------------------------------------------------------------

    /**
     * Tài liệu khách được xem. **Hai cờ độc lập** (SPEC §6.5 bước 3): `client_can_view` quyết
     * định tài liệu có mặt trên trang, `client_can_download` quyết định có nút tải — xem
     * {@see self::canDownload()}. Có tài liệu văn phòng cho khách BIẾT là đã có mà chưa cho tải,
     * và đó là một lựa chọn hợp lệ của người công bố, không phải một trạng thái lỡ dở.
     *
     * Lần lọc qua `Gate` là tầng độc lập giữ **nhóm D** ngoài trang này kể cả khi cả hai cờ đã
     * bị bật thẳng trong bảng và global scope quên mất luật của mình:
     * `DocumentPolicy::view()` hỏi `Document::isReleasedToPortal()`, thứ đọc `group` trên chính
     * bản ghi. Xoá dòng lọc này thì `MatterProgressTest` đỏ.
     *
     * @return Collection<int, Document>
     */
    public function documents(): Collection
    {
        $viewer = $this->viewer();

        return $this->resolvedDocuments ??= $this->matter()->documents()->get()
            ->filter(fn (Document $document): bool => Gate::forUser($viewer)->allows('view', $document))
            ->sortByDesc(fn (Document $document) => $document->published_at ?? $document->created_at)
            ->values();
    }

    /** Cờ thứ hai, hỏi qua policy (`DocumentPolicy::download` hỏi lại `view` trước). */
    public function canDownload(Document $document): bool
    {
        return Gate::forUser($this->viewer())->allows('download', $document);
    }

    /**
     * Đường tải **luôn** là URL đã ký của M4, ký cho đúng người đọc và sống 5 phút (SPEC §10.4).
     * Không bao giờ `Storage::url()` và không bao giờ một đường dẫn của medialibrary: cả hai đều
     * là đường đi vòng qua policy trong `DocumentDownloadController`, và chữ ký URL không thay
     * thế một lần kiểm tra quyền.
     */
    public function downloadUrl(Document $document): string
    {
        return $document->downloadUrlFor($this->viewer());
    }

    // -------------------------------------------------------------------------------------
    // Khối 6 — Mốc thời hạn sắp tới
    // -------------------------------------------------------------------------------------

    /**
     * Chỉ mốc `is_published` (SPEC §8.3 mục 6) và chỉ mốc **chưa hoàn tất**: chữ "sắp tới" của
     * SPEC là chữ về việc còn phải làm. Mốc đã xong không biến mất khỏi hồ sơ nội bộ, nó chỉ
     * thôi là thứ khách cần nhớ.
     *
     * Quan hệ đã sắp xếp tăng dần theo `due_date`, nên mốc gần nhất đứng đầu — đúng thứ tự một
     * người đọc trên điện thoại cần.
     *
     * @return Collection<int, Deadline>
     */
    public function deadlines(): Collection
    {
        $viewer = $this->viewer();

        return $this->resolvedDeadlines ??= $this->matter()->deadlines()->get()
            ->filter(fn (Deadline $deadline): bool => ! $deadline->is_completed
                && Gate::forUser($viewer)->allows('view', $deadline))
            ->values();
    }

    // -------------------------------------------------------------------------------------
    // Khối 7 — Gửi yêu cầu
    // -------------------------------------------------------------------------------------

    /**
     * Lối vào màn hình gửi yêu cầu (SPEC §8.3 mục 7). **Seam của Task 4, được Task 6 lật.**
     *
     * Task 4 để hàm này trả `null` vì {@see MyRequests} chưa tồn tại, và khi `null` thì khối 7
     * đưa ra con đường CÓ THẬT lúc đó: số điện thoại văn phòng — một cái nút dẫn tới một trang
     * chưa có tệ hơn không có nút, vì nó biến một khách đang cần hỏi thành một khách vừa gặp lỗi.
     * Trang đó đã có, nên hàm trả URL của nó.
     *
     * Gọi bằng LỚP chứ không bằng một đường dẫn viết tay: trang kia sở hữu `$slug` và hình dạng
     * `{record}` của chính nó, nên một ngày nó đổi thì lời gọi này đi theo.
     *
     * **Nói đúng cái giá của lần lật này, vì docblock cũ hứa rộng hơn sự thật.** View vẽ khối 7
     * bằng `@if ($url = $this->requestEntryPoint()) … @else … @endif`, nên từ lúc hàm này thôi
     * trả `null`, nhánh `@else` — câu "gọi điện", `portal_progress.blocks.requests.call` — không
     * còn được vẽ ra nữa. Khoá dịch vẫn còn và nhánh vẫn còn; chúng chỉ thôi chạy tới. Để cả nút
     * lẫn số điện thoại cùng hiện là một thay đổi trong MARKUP của khối 7, và tệp view đó đang
     * được ba task dùng chung ở M5 (phán quyết của người điều phối: Task 6 chỉ lật hàm này, Task
     * 5 chỉ sửa seam nút gửi của khối 4). Nên nó được ghi lại ở đây và giao cho vòng hợp nhất,
     * chứ không sửa lén vào một tệp người khác đang viết dở.
     */
    public function requestEntryPoint(): ?string
    {
        return MyRequests::getUrl(['record' => $this->matter()->getKey()]);
    }

    // -------------------------------------------------------------------------------------

    /**
     * Đọc lại hồ sơ qua truy vấn đã có scope, rồi hỏi `Gate` một lần nữa — hai tầng, hai câu
     * lệnh khác nhau, không chung một điều kiện nào.
     *
     * Tham số `{record}` đến từ URL và **không được tin**: không có model binding ở route này
     * đúng vì lý do đó. `whereKey()` trên truy vấn đã có `ClientPortalScope` trả `null` cho id
     * của khách khác y hệt như cho một id bịa đặt, và cả hai đi ra bằng cùng một câu trả lời —
     * SPEC §10.10.
     */
    private function resolveMatter(): Matter
    {
        $viewer = $this->viewer();

        $matter = Matter::query()->whereKey($this->record)->first();

        abort_if($matter === null, 404);
        abort_unless(Gate::forUser($viewer)->allows('view', $matter), 404);

        return $matter;
    }

    /**
     * Khách đang đọc. `Filament::auth()` là guard của panel hiện hành (`client`), không phải
     * guard mặc định của ứng dụng.
     */
    private function viewer(): ClientUser
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof ClientUser, 404);

        return $user;
    }
}
