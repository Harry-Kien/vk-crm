<?php

namespace App\Filament\Portal\Pages;

use App\Actions\Document\ChecklistProgress;
use App\Actions\Portal\RecordStageLogView;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\StageLog;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

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
 * Dưới bảy khối là **lối quay lại danh sách hồ sơ**, và nó gọi {@see MyMatters::getAllUrl()} chứ
 * không `MyMatters::getUrl()`: với một khách có đúng MỘT hồ sơ thì `/portal` trần chuyển hướng
 * ngược về chính trang này, nên một lối quay lại không mang cờ "tất cả" là một cái nút không đi
 * đâu cả. Đo bằng cách ĐI THEO ĐƯỜNG LINK — đọc `href` ra khỏi HTML rồi gọi thật vào nó và đòi
 * 200 — cùng thiết bị mà màn hình danh sách phải dựng cho mục điều hướng của nó, và cùng khuyết
 * tật mà vòng rà soát Task 3 đã tìm thấy.
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
 * nhau giữa hai nửa. Hình dạng ấy giờ có thêm **câu chữ**: `resources/views/errors/404.blade.php`
 * trả lời bằng tiếng Việt và kèm số điện thoại văn phòng, vì người không mở được một trang cần
 * một đường đi tiếp KHÔNG qua một trang.
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
 *  3. **Serialize** — `HidesInternalAttributesFromPortal`, và **một tầng nữa ở chính trang này**:
 *     không một phương thức công khai nào trả về model, xem mục kế tiếp.
 *
 * **`internal_note` không được nhắc tới ở bất kỳ đâu trong lớp này lẫn trong view của nó.** Task
 * 2 đã ghim ra một ranh giới mà không ai nên phải tự phát hiện lại: trait kia chỉ chặn ở
 * `attributesToArray()`, nên `$log->internal_note` trong Blade **trả về chuỗi thật** và một câu
 * `where('internal_note', ...)` là một vị từ SQL hợp lệ. Điều SPEC §11 hứa (không bao giờ có
 * trong HTML/JSON của cổng) đúng vì không đường serialize nào mang nó ra — không phải vì cột đó
 * không với tới được. Luật ở đây vì thế là một luật về mã nguồn: **không nhắc tên cột đó**.
 *
 * # Trang này là một bề mặt RPC, nên nó trả về HÌNH CHIẾU chứ không trả về bản ghi
 *
 * Mọi phương thức `public` của một component Livewire **gọi được từ trình duyệt**, và giá trị trả
 * về của nó được serialize thẳng vào response cập nhật. Vòng rà soát đã đo: `currentStage()` cũ
 * trả về cả dòng `matter_type_stages` — nhãn NỘI BỘ của văn phòng, danh sách giai đoạn kế tiếp
 * được phép, cờ kết thúc, số ngày cập nhật mặc định — còn `matter()` và `timeline()` trả về
 * nguyên bản ghi kèm id luật sư phụ trách và id người công bố. Hai ranh giới tuyệt đối
 * (`internal_note`, `description_internal`) vẫn đứng vững, nên đây là một lần thủng **SPEC §8**
 * ("không thuật ngữ nội bộ trước mặt khách"), không phải §11 — nhưng nó cũng mâu thuẫn với chính
 * docblock của `currentStage()` và với test `assertDontSee` đứng sau nó.
 *
 * Nên từ vòng này: **mọi accessor hướng khách trả về một mảng hẹp gồm đúng những gì view vẽ ra**,
 * và mọi thứ nhận/trả model ({@see self::matter()}, {@see self::stageLabel()},
 * {@see self::canDownload()}, {@see self::downloadUrl()}, {@see self::rejectionReason()},
 * {@see self::isStageChange()}) là `private` — Livewire chỉ gọi được phương thức công khai, nên
 * `private` ở đây là một điều kiện thật chứ không phải một lời khuyên. Ghim bằng một test phát
 * biểu LUẬT chứ không liệt kê tên hàm ("serialises no internal terminology from any public method
 * the browser can call"): nó gọi mọi phương thức công khai khai báo trên chính lớp này và
 * `json_encode` kết quả. Một hàm mới trả về model sẽ làm test đó đỏ mà không ai phải nhớ ra điều
 * gì.
 *
 * `$record` là `#[Locked]` vì cùng một họ lý do: rà soát đo được rằng một `updates:{"record": …}`
 * tự chế trỏ sang một hồ sơ KHÁC CỦA CÙNG KHÁCH trả về 200 kèm mảnh HTML của hồ sơ kia — trong
 * khi thanh địa chỉ vẫn là hồ sơ đầu — và ghi luôn biên bản "đã xem" của hồ sơ kia. Cách ly giữa
 * hai khách hàng không hề thủng ở đường đó; cái thủng là một thuộc tính mà trình duyệt không có
 * việc gì phải đặt lại. **Khoá không thay cho việc gác**: lần giải lại ở mọi request vẫn nguyên,
 * và nó mới là tầng thật.
 *
 * # Biên bản đã xem — và "Khách đã xem" ở panel nội bộ giờ nghĩa là gì
 *
 * Mỗi dòng đã công bố mà trang này vẽ ra sinh một lần gọi {@see RecordStageLogView} (Task 2, nơi
 * ghi duy nhất của bảng `stage_log_views`; trang này không bao giờ ghi thẳng vào bảng) — nhưng
 * lần gọi ấy chạy SAU KHI response của chính request đó đã dựng xong và thành công, xem hai điều
 * kiện ở dưới. Cách đọc đã chốt, và nó phải được nói ra vì đây là **bằng chứng pháp lý**, không
 * phải một con số thống kê:
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
 * **Và từ vòng sửa này, việc ghi KHÔNG nằm trên đường render nữa** — {@see self::timeline()} chỉ
 * ĐẶT LỊCH, còn lần ghi thật chạy ở `RequestHandled`, tức sau khi response của chính request này
 * đã dựng xong. Hai điều kiện, mỗi cái đóng một lỗ đã đo được:
 *
 *  1. **Phương thức request phải mang được thân.** Filament đăng ký route cho cả `GET` lẫn `HEAD`;
 *     với `HEAD` thì trang vẫn dựng đủ rồi Symfony cắt sạch thân, nên khách nhận về KHÔNG BYTE
 *     NÀO trong khi bảng vẫn có đủ biên bản. Xem {@see self::requestCanCarryABody()}.
 *  2. **Response phải hoàn tất và thành công.** `firstOrCreate` commit ngay, ngoài mọi transaction
 *     — nên trước vòng này một exception ở khối 4 hay khối 7 trả về trang 500 cho khách mà vẫn để
 *     lại đủ biên bản "đã xem". Ở `RequestHandled` thì trạng thái cuối cùng đã biết, và một
 *     response không phải 2xx không ghi gì.
 *
 * Vậy nhãn **"Khách đã xem lúc …"** ở tab Tiến độ của panel nội bộ (SPEC §7.2, §4.18) từ nay đọc
 * đúng là: *tài khoản portal này đã yêu cầu trang chi tiết của hồ sơ bằng một phương thức mang
 * được nội dung, và máy chủ đã dựng xong một câu trả lời thành công CÓ CHỨA dòng cập nhật này,
 * vào thời điểm đó, cho một yêu cầu đến từ địa chỉ IP đó.* Nó **không** khẳng định trang đã đi
 * hết đường truyền tới máy khách, càng không khẳng định người đó đã cuộn xuống tới dòng ấy, đã
 * đọc, hay đã hiểu. Câu này hẹp hơn câu cũ ("nằm trong trang được gửi tới trình duyệt của họ")
 * đúng một bậc, và bậc ấy là bậc duy nhất mã nguồn đo được: điều cuối cùng máy chủ biết chắc là
 * nó đã hoàn tất một response 200 có chứa dòng đó.
 *
 * Luật sư đọc nhãn đó để quyết định có cần gọi điện hay không; văn phòng đưa nó ra để chứng minh
 * mình đã ĐƯA TIN. Hai việc đó cần đúng mức khẳng định trên, không hơn — và câu này nằm ở đây để
 * người viết màn hình nội bộ (Task 6) và người đứng trước một câu hỏi pháp lý về sau đọc cùng một
 * định nghĩa.
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

    /**
     * Tham số trên URL. Không dùng model binding: bản ghi được đọc lại qua truy vấn đã có scope.
     *
     * **`#[Locked]`, và đây là một điều kiện đo được.** Xem mục "bề mặt RPC" ở docblock lớp: một
     * `updates:{"record": …}` tự chế trỏ sang hồ sơ khác của cùng khách trả về mảnh HTML của hồ
     * sơ kia trước khi có khoá này. Khoá không thay cho lần giải lại ở
     * {@see self::resolveMatter()}; ghim ở `MatterProgressTest`, "refuses a forged record that
     * names another matter of the same client".
     */
    #[Locked]
    public int|string $record;

    private ?Matter $resolvedMatter = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $resolvedTimeline = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $resolvedChecklist = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $resolvedDocuments = null;

    /** @var Collection<int, array<string, mixed>>|null */
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

    // -------------------------------------------------------------------------------------
    // Khối 1 — Tình trạng hiện tại
    // -------------------------------------------------------------------------------------

    /**
     * Giai đoạn hiện tại, **đã chiếu xuống đúng hai chuỗi view vẽ ra**.
     *
     * **Không bao giờ `label`.** Cột `label` là chữ của văn phòng ("Thu thập hồ sơ"); `client_label`
     * là chữ viết cho khách ("Đang thu thập giấy tờ"). SPEC §8.2 và §8.3 đều chỉ đích danh cột
     * thứ hai, và §8 cấm thuật ngữ — nên một lần đọc nhầm cột ở đây là đưa ngôn ngữ nội bộ ra
     * trước mặt khách.
     *
     * **Và vì thế hàm này trả về một MẢNG, không trả về `MatterTypeStage`.** Trước vòng sửa này
     * nó trả về cả dòng, nên một lần gọi từ trình duyệt nhận đủ `label` nội bộ,
     * `allowed_next_stages`, `is_terminal`, `default_update_interval_days` — tức đúng cái
     * docblock này cấm, chỉ bằng một đường khác. Xem mục "bề mặt RPC" ở docblock lớp.
     *
     * `null` khi không tra được giai đoạn: loại vụ việc không còn khai báo nó, HOẶC cả loại vụ
     * việc đã bị xoá mềm ({@see Matter::currentStage()} dùng `?->` đúng vì trường hợp thứ hai).
     * View có câu riêng cho trường hợp ấy.
     *
     * @return array{label: ?string, description: ?string}|null
     */
    public function currentStage(): ?array
    {
        $stage = $this->matter()->currentStage();

        if ($stage === null) {
            return null;
        }

        return [
            'label' => $stage->client_label,
            'description' => $stage->client_description,
        ];
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
     * # Hai nhóm, vì ô này và thanh tiến độ ngay dưới nó phải nói về CÙNG MỘT tập dòng
     *
     * Bản trước liệt kê mọi đầu mục đang chờ ở khách, bất kể bắt buộc hay không, và không vẽ dấu
     * hiệu nào. Thanh tiến độ thì chỉ nói về tập `Y` của SPEC §4.10. Trên hồ sơ mẫu số 6 khách
     * đọc mười một dòng giấy tờ bên trên một thanh nói "… / 4", và một trong mười một dòng là
     * giấy chứng tử mà văn phòng đã đánh dấu KHÔNG bắt buộc. Hai nguồn sự thật cho một câu, trong
     * ô nổi bật nhất màn hình — cùng hình dạng đã tìm thấy hai lần trước đó trên nhánh này.
     *
     * Nên tập dòng vẫn là MỘT, và nó tách làm hai theo đúng câu hỏi thanh tiến độ hỏi
     * ({@see ChecklistProgress::countedInTotal()}): trong `Y` thì văn phòng còn chờ, ngoài `Y`
     * thì không bắt buộc và khách được nói thẳng điều đó.
     *
     * **"Ngoài `Y`" KHÔNG đồng nghĩa với `is_required = false`.** Một đầu mục không bắt buộc mà
     * khách đã gửi một tờ giấy vào thì thanh tiến độ ĐẾM nó, nên nó thuộc nhóm văn phòng còn
     * chờ — đẩy nó xuống nhóm "không bắt buộc" là bảo khách bỏ dở đúng việc họ đã bắt đầu. Có
     * test riêng gọi tên tình huống đó.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingItems(): Collection
    {
        return $this->checklistItems()
            ->filter(fn (array $item): bool => $this->isWaitingOnTheClient($item['status']))
            ->values();
    }

    /**
     * Phần thanh tiến độ ĐANG ĐẾM — "giấy tờ chúng tôi còn chờ ở anh/chị".
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingCountedItems(): Collection
    {
        return $this->outstandingItems()
            ->filter(fn (array $item): bool => $item['counted_by_progress'] === true)
            ->values();
    }

    /**
     * Phần thanh tiến độ KHÔNG đếm — gửi thêm được thì tốt, không gửi cũng không sao. Nó vẫn
     * hiện ra, vì khách vẫn cần biết văn phòng có thể dùng tới nó; nó chỉ không được đứng lẫn vào
     * danh sách việc phải làm.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstandingOptionalItems(): Collection
    {
        return $this->outstandingItems()
            ->filter(fn (array $item): bool => $item['counted_by_progress'] === false)
            ->values();
    }

    /**
     * `client_action` của dòng cập nhật MỚI NHẤT, không phải của mọi dòng.
     *
     * SPEC §4.8 viết thẳng: "để trống nghĩa là không cần làm gì". Và chỉ dòng mới nhất mới nói
     * về hiện tại — gom `client_action` của mọi dòng lại sẽ dựng ra một danh sách việc mà phần
     * lớn đã xong từ lâu, tức đúng loại màn hình khiến người ta thôi đọc. Những câu cũ vẫn còn
     * nguyên ở dòng thời gian, đúng chỗ của chúng trong lịch sử.
     *
     * "Mới nhất" xác định được kể cả khi hai cập nhật cùng một ngày, và đó là công của tiêu chí
     * phụ `id` giảm dần ở {@see Matter::stageLogs()} — `occurred_at` đến từ một ô chọn NGÀY nên
     * hai dòng cùng ngày bằng nhau tuyệt đối ở cột sắp xếp.
     */
    public function latestClientAction(): ?string
    {
        $latest = $this->timeline()->first()['client_action'] ?? null;

        return filled($latest) ? $latest : null;
    }

    // -------------------------------------------------------------------------------------
    // Khối 3 — Diễn biến vụ việc
    // -------------------------------------------------------------------------------------

    /**
     * Các dòng đã công bố, mới nhất trên cùng — và **nơi biên bản "đã xem" được ĐẶT LỊCH**.
     *
     * Quan hệ `Matter::stageLogs()` đã sắp xếp giảm dần theo `occurred_at` rồi theo `id`, và
     * global scope đã cắt về các dòng `is_published = true` thuộc hồ sơ khách thấy được. Lần lọc
     * qua `Gate` bên dưới là tầng thứ hai, độc lập: `StageLogPolicy::view()` đọc thẳng
     * `is_published` trên bản ghi thay vì tin vào câu `where` kia.
     *
     * **Trả về hình chiếu, không trả về `StageLog`** — xem mục "bề mặt RPC" ở docblock lớp: bản
     * ghi thật mang id người tạo và id người công bố, và một lần gọi từ trình duyệt serialize
     * thẳng chúng vào response.
     *
     * @return Collection<int, array<string, mixed>>
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

        $this->recordViewsOnceTheResponseIsBuilt($logs, $viewer);

        return $this->resolvedTimeline = $logs
            ->map(fn (StageLog $log): array => $this->presentLog($log))
            ->values();
    }

    /**
     * Đặt lịch ghi biên bản cho **đúng những dòng trang vẽ ra**, rồi trả lại việc render.
     *
     * **Vì sao không ghi ngay tại đây, giữa lúc render.** Đó là hình dạng cũ, và nó viết ra bằng
     * chứng cho những trang khách chưa bao giờ nhận được — đo được hai kiểu:
     *
     *  - một request `HEAD` (Filament đăng ký route cho cả `GET` lẫn `HEAD`) dựng trang đủ, ghi
     *    đủ biên bản, rồi Symfony cắt sạch thân: **không byte nào** được gửi đi;
     *  - một exception bất kỳ SAU vòng lặp ghi trả về trang 500 cho khách trong khi các dòng đã
     *    commit — `firstOrCreate` ghi ngay, ngoài mọi transaction, trước khi có một response nào.
     *
     * Hai điều kiện dưới đây đóng đúng hai lỗ ấy, và chúng độc lập với nhau — mỗi cái có test
     * riêng gọi tên nó ở `MatterProgressTest`.
     *
     * `RequestHandled` chứ không `terminating()`: sự kiện đó mang theo CHÍNH response cuối cùng,
     * kể cả response lỗi mà kernel dựng ra từ một exception (đã đọc trong
     * `Illuminate\Foundation\Http\Kernel::handle()`), nên nó là chỗ duy nhất trả lời được câu
     * "câu trả lời đã hoàn tất và nó có thành công không". `terminating()` thì chạy cho cả một
     * response 500, tức không phân biệt được gì.
     *
     * So sánh `$event->request !== $request` bằng ĐỒNG NHẤT THỂ, không bằng URL: một tiến trình
     * thật chỉ phục vụ một request, nhưng trong một test thì nhiều request đi qua cùng một
     * dispatcher, và một listener còn sót lại của request trước sẽ ghi biên bản lên response của
     * request sau.
     *
     * **Phép so sánh ấy SỐNG SÓT một lần đột biến, và câu đó được nói ra thay vì để người đọc
     * sau tưởng nó đang giữ một phép đo:** xoá nó đi thì KHÔNG test nào đỏ. Lý do nằm ở hợp đồng
     * "một dòng, dấu thời gian của lần ĐẦU" của `RecordStageLogView` — một listener còn sót lại
     * chỉ ghi LẠI đúng những dòng đã có, nên hậu quả không quan sát được từ bên ngoài. Giữ vì
     * nếu hợp đồng đó đổi thì đây là thứ duy nhất ngăn biên bản của hồ sơ này gắn vào response
     * của một request khác.
     *
     * # Một lời TỪ CHỐI ở đây bị nuốt, và đây là nơi nói vì sao — kể cả câu trước đây nói ngược lại
     *
     * Câu cũ ở chỗ này viết rằng một lỗi khi ghi "được để nổ ra, có chủ ý". Cơ chế nó mô tả thì
     * đúng, kết luận thì sai, vì nó chưa bao giờ nói ra **chỗ lời nổ ấy hạ cánh**.
     *
     * `Illuminate\Foundation\Http\Kernel::handle()` bắt exception của request bên trong khối
     * `try` của nó, rồi dispatch `RequestHandled` **NGOÀI** khối ấy (đã đọc trong bản đang cài).
     * Nên một exception từ listener này:
     *
     *  - không được kernel bắt, nên nó không thành một response lỗi của ứng dụng;
     *  - không đi qua middleware nào — kể cả `AnswerDeniedPanelRequestsWithNotFound`, thứ đổi 403
     *    thành 404 cho cổng theo SPEC §10.10;
     *  - nổ tới trình xử lý lỗi toàn cục, thứ dựng ra một trang **403**;
     *  - và **vứt đi chính cái trang đã dựng xong** mà khách sắp nhận được.
     *
     * Vậy nên `AuthorizationException` bị nuốt ở đây. Bỏ một biên bản là chấp nhận được tại đúng
     * điểm này, và lý do phải nói rõ chứ không để người đọc sau tự đoán: `RecordStageLogView` đã
     * từ chối ĐÚNG (hồ sơ vừa bị rút khỏi cổng, hoặc tài khoản vừa bị vô hiệu hoá, giữa lúc vẽ
     * trang và lúc request kết thúc), và tới thời điểm này **không còn trang nào để bảo vệ** —
     * response đã dựng xong. Một biên bản không ghi làm bằng chứng THIẾU đi một dòng; một
     * exception thoát ra làm khách đọc một trang lỗi tiếng Anh thay cho hồ sơ của họ, và vẫn
     * không ghi được dòng nào. Bỏ dòng ghi là mất ít hơn, và nó chỉ mất trong đúng cảnh mà khách
     * lẽ ra đã không được xem dòng ấy.
     *
     * **Chỉ `AuthorizationException`, không phải mọi `Throwable`.** Một lỗi cơ sở dữ liệu không
     * phải một lời từ chối: nuốt nó đi là giấu một bảng bằng chứng đang hỏng, đúng thứ không ai
     * nhìn thấy cho tới ngày cần tới nó. Nói thẳng cái giá còn lại của lựa chọn này: một lỗi như
     * thế VẪN thoát ra theo đúng đường mô tả ở trên và vẫn vứt đi trang đã dựng. Đó là một hỏng
     * hóc của hệ thống, không phải một trạng thái nghiệp vụ, và nó phải ồn ào.
     *
     * Về cái trang 403 ấy, nói cho đủ vì nó vừa đổi trong cùng vòng sửa này: trước đây nó là
     * trang mặc định của Laravel — bố cục minh hoạ sẵn có, chữ tiếng Anh, không số điện thoại,
     * không đường quay lại; nay đã có `resources/views/errors/403.blade.php` bằng tiếng Việt, vì
     * một đường tải tệp ký hết hạn cũng dẫn tới đó. **Nhưng nó không phải lời giải cho chỗ
     * này**, và đừng ai đọc nó như vậy: SPEC §10.10 đòi cổng từ chối bằng 404, nên một 403 ở đây
     * — dù đẹp và bằng tiếng Việt — vẫn là cái máy dò sự tồn tại mà §10.10 dựng lên để chặn; và
     * trang khách sắp nhận được thì vẫn mất.
     *
     * @param  Collection<int, StageLog>  $logs
     */
    private function recordViewsOnceTheResponseIsBuilt(Collection $logs, ClientUser $viewer): void
    {
        $request = request();

        if ($logs->isEmpty() || ! $this->requestCanCarryABody($request)) {
            return;
        }

        $ip = $request->ip();
        $receipts = app(RecordStageLogView::class);

        Event::listen(function (RequestHandled $event) use ($request, $logs, $viewer, $ip, $receipts): void {
            if ($event->request !== $request || ! $event->response->isSuccessful()) {
                return;
            }

            try {
                $logs->each(fn (StageLog $log) => $receipts->handle($log, $viewer, $ip));
            } catch (AuthorizationException) {
                // Có chủ ý và không làm gì thêm — xem docblock phía trên.
            }
        });
    }

    /**
     * **`HEAD` không mang được thân, nên nó không sinh biên bản nào.**
     *
     * Filament đăng ký route của trang cho cả `GET` lẫn `HEAD`. Với `HEAD` thì trang vẫn dựng
     * đầy đủ — trước lần vá này nghĩa là vòng lặp biên bản cũng chạy qua từng dòng — rồi
     * `Response::prepare()` của Symfony cắt sạch thân trước khi trả lời. Một dòng
     * `stage_log_views` sinh ra từ đó khẳng định văn phòng đã cho khách xem một cập nhật, về một
     * câu trả lời dài **không byte nào**, và nó không phân biệt được với một dòng thật.
     */
    private function requestCanCarryABody(Request $request): bool
    {
        return ! $request->isMethod('HEAD');
    }

    /**
     * Một dòng diễn biến, chiếu xuống đúng bốn phần SPEC §8.3 mục 3 đòi cộng ngày tháng.
     *
     * `moved_to` là `null` khi dòng KHÔNG đổi giai đoạn, và cũng `null` khi loại vụ việc không
     * còn khai báo nhãn của giai đoạn ấy — view chỉ hỏi một câu "có nhãn để vẽ không", không phải
     * hỏi hai câu rồi tự ghép.
     *
     * @return array<string, mixed>
     */
    private function presentLog(StageLog $log): array
    {
        return [
            'date' => $log->occurred_at?->format('d/m/Y'),
            'moved_to' => $this->isStageChange($log) ? $this->stageLabel($log->to_stage) : null,
            'public_content' => $log->public_content,
            'next_step' => $log->next_step,
            'client_action' => $log->client_action,
            'expected_on' => $log->expected_next_update_at?->format('d/m/Y'),
        ];
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
    private function isStageChange(StageLog $log): bool
    {
        return $log->from_stage !== $log->to_stage;
    }

    /**
     * Nhãn dễ hiểu của giai đoạn một dòng chuyển tới.
     *
     * `null` ở hai trường hợp, và view xử một cách như nhau: dòng không ghi giai đoạn đích, và
     * **cả loại vụ việc đã bị xoá mềm** — trường hợp thứ hai là lý do `?->` sau `matterType`,
     * không phải một thói quen. Một quản trị viên xoá một loại vụ việc làm quan hệ này trả `null`
     * cho mọi hồ sơ đang đứng trong loại đó, và trước lần vá này thì đó là một trang 500 cho từng
     * khách hàng liên quan. Chốt chặn phía ghi nằm ở `MatterTypePolicy::delete()`.
     *
     * **KHÔNG còn `null` khi chỉ MỘT GIAI ĐOẠN đã bị xoá mềm** (Task 19, vòng sửa 1 — Important):
     * xoá mềm một giai đoạn mà chỉ LỊCH SỬ (`stage_logs`) còn dùng là hành vi ĐƯỢC PHÉP
     * (`MatterTypeStagePolicy::delete()` không chặn lịch sử), nên trước đây khách đọc một dòng
     * tiến độ CÓ THẬT nhưng không có nhãn nào — `stageIncludingTrashed()` (MatterType) tra thêm
     * các dòng đã xoá mềm, dùng chung với `StageLogsRelationManager` (tab "Tiến độ" của admin) để
     * hai màn hình không lệch nhau.
     */
    private function stageLabel(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        return $this->matter()->matterType?->stageIncludingTrashed($key)?->client_label;
    }

    // -------------------------------------------------------------------------------------
    // Khối 4 — Hồ sơ giấy tờ
    // -------------------------------------------------------------------------------------

    /**
     * Danh mục giấy tờ của hồ sơ, đã chiếu xuống những gì khối 4 vẽ ra.
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
     * `status` đi qua dưới dạng ENUM chứ không phải chuỗi: view dùng nó cho cả màu lẫn khoá dịch,
     * và một enum backed serialize ra đúng giá trị chuỗi của nó, không mang theo gì khác.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function checklistItems(): Collection
    {
        $viewer = $this->viewer();

        return $this->resolvedChecklist ??= ChecklistProgress::countClientSubmittedDocuments(
            $this->matter()->checklistItems()->getQuery()
        )->get()
            ->filter(fn (MatterChecklistItem $item): bool => Gate::forUser($viewer)->allows('view', $item))
            ->map(fn (MatterChecklistItem $item): array => [
                'id' => $item->getKey(),
                'name' => $item->name,
                'status' => $item->status,
                'is_required' => (bool) $item->is_required,
                // Thanh tiến độ có đếm dòng này không — {@see ChecklistProgress::countedInTotal()}.
                // Khối 2 tách hai nhóm bằng ĐÚNG câu hỏi này, nên hai câu trên một màn hình không
                // thể nói về hai tập khác nhau nữa.
                'counted_by_progress' => ChecklistProgress::countedInTotal($item),
                'description' => $item->description,
                'rejection_reason' => $this->rejectionReason($item),
                // Lối vào màn hình nộp: CHỈ hai trạng thái đang chờ ở khách (SPEC §8.3 mục 4).
                // Khối này là danh sách "còn thiếu gì", không phải một bảng thao tác — một cái nút
                // trên một đầu mục đã nhận đủ chỉ mời khách gửi lại thứ văn phòng đã có. Màn hình
                // nộp thì nhận cả những trạng thái khác (nó là đường sửa sai duy nhất của khách);
                // lối vào ở đây hẹp hơn một cách có chủ đích. URL do `SubmitDocument` sở hữu, nên
                // gọi bằng LỚP chứ không ghép tay.
                'submit_url' => $this->isWaitingOnTheClient($item->status)
                    ? SubmitDocument::urlForItem($item)
                    : null,
            ])
            ->values();
    }

    /**
     * Đang chờ ở KHÁCH, không ở văn phòng. Một chỗ duy nhất cho cả {@see self::outstandingItems()}
     * (khối 2) lẫn cái nút nộp ở khối 4, vì hai chỗ đó phải luôn nói cùng một câu.
     */
    private function isWaitingOnTheClient(ChecklistItemStatus $status): bool
    {
        return in_array($status, [ChecklistItemStatus::Missing, ChecklistItemStatus::Rejected], true);
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
    private function rejectionReason(MatterChecklistItem $item): ?string
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
     * định tài liệu có mặt trên trang, `client_can_download` quyết định có nút tải — ở hình chiếu
     * dưới đây nó là `download_url === null` hay không. Có tài liệu văn phòng cho khách BIẾT là
     * đã có mà chưa cho tải, và đó là một lựa chọn hợp lệ của người công bố, không phải một trạng
     * thái lỡ dở.
     *
     * Lần lọc qua `Gate` là tầng độc lập giữ **nhóm D** ngoài trang này kể cả khi cả hai cờ đã
     * bị bật thẳng trong bảng và global scope quên mất luật của mình:
     * `DocumentPolicy::view()` hỏi `Document::isReleasedToPortal()`, thứ đọc `group` trên chính
     * bản ghi. Xoá dòng lọc này thì `MatterProgressTest` đỏ.
     *
     * **Mọi tệp của bản MỚI NHẤT mỗi chuỗi nộp lại** — xem bình luận trong thân hàm. Điều kiện
     * này thêm ở Task 5 cùng lúc với màn hình nộp tệp, vì trước đó cổng không có đường nào sinh
     * ra bản thứ hai; test đứng sau nó ở `SubmitDocumentTest`. R10 (M6.5 Task 17, checklist-03)
     * đổi "giữ MỘT tài liệu" thành "giữ mọi tài liệu CÙNG version lớn nhất": một lần nộp giờ có
     * thể gồm nhiều tệp (CCCD hai mặt) cùng một version, và luật cũ chỉ vẽ MỘT trong số chúng —
     * đúng bug gốc, nơi mặt sau "che" mặt trước dù cả hai đứng CÙNG version, không phải hai
     * version khác nhau.
     *
     * `id` được giữ lại trong hình chiếu: nó đã nằm sẵn trong đường tải trên chính trang, nên nó
     * không nói thêm điều gì — và nó là thứ `SubmitDocumentTest` đọc để phân biệt hai bản của một
     * chuỗi nộp lại.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function documents(): Collection
    {
        if ($this->resolvedDocuments !== null) {
            return $this->resolvedDocuments;
        }

        $viewer = $this->viewer();

        $visible = $this->matter()->documents()->get()
            ->filter(fn (Document $document): bool => Gate::forUser($viewer)->allows('view', $document));

        // Mọi tệp của bản MỚI NHẤT mỗi chuỗi nộp lại (SPEC §6.6 bước 7, R10) — thêm ở Task 5,
        // đổi ở Task 17. Một lần nộp lại tạo (các) `Document` MỚI và giữ nguyên bản cũ ("không
        // ghi đè"), nên nếu không lọc thì khối này vẽ nhiều dòng cùng tên đầu mục, cùng ngày, và
        // khách không có cách nào biết dòng nào là bản đang có hiệu lực — cái cũ lại chính là
        // cái vừa bị văn phòng từ chối.
        //
        // **Luật: trong mỗi chuỗi, giữ MỌI bản mang `version` LỚN NHẤT trong số những bản khách
        // được xem** — không còn "một bản duy nhất". Bản cũ của Task 5 nhận biết bản bị thay
        // bằng `parent_document_id` của một bản ĐANG HIỂN THỊ, và bình luận cũ ở đây khẳng định
        // cách ấy đứng vững khi chuỗi bị đứt. Nó không đứng vững, và rà soát Task 5 đã đo: bản 1
        // bị từ chối, bản 2 thay nó, bản 2 bị xoá mềm, bản 3 nộp tiếp — `parent_document_id` của
        // bản 3 trỏ vào bản 2 (chuỗi tra bằng `withTrashed()`), bản 2 không hiển thị, nên không
        // ai trỏ vào bản 1 và trang vẽ ra bản 3 CÙNG bản 1. Đúng cái ca mà bình luận cũ nói nó
        // xử được.
        //
        // Số `version` trả lời đúng ca đó vì nó là một con số ĐƠN ĐIỆU trong chuỗi và không bao
        // giờ được cấp lại: `latestInSubmissionChain()` tra bằng `withTrashed()` nên một bản đã
        // xoá mềm vẫn giữ số của mình. "Lớn nhất trong số bản khách được xem" vì thế đọc đúng cả
        // khi bản mới nhất KHÔNG lên cổng (nó ở nhóm khác): khi ấy bản mới nhất khách được xem
        // chính là câu trả lời đúng cho "cái nào đang có hiệu lực với anh/chị". Và vì R10 cho
        // MỘT version nhiều tài liệu, "lớn nhất" giờ là một con số dùng để LỌC (giữ mọi bản có
        // đúng version đó), không còn là một khoá để CHỌN một bản duy nhất.
        //
        // Chuỗi nhận biết bằng đầu mục danh mục + nhóm A, đúng định nghĩa mà
        // `StoresDocumentFile::latestInSubmissionChain()` dùng khi đánh số — tài liệu ngoài
        // chuỗi (nhóm B, C, hoặc không gắn đầu mục nào) không bao giờ bị lọc, vì chúng không bao
        // giờ mang `version` thứ hai. Không truy vấn thêm: mọi vế đọc từ cùng tập hợp đã gác
        // quyền ở trên.
        $latestVersionByChain = $visible
            ->filter(fn (Document $document): bool => $this->belongsToASubmissionChain($document))
            ->groupBy('matter_checklist_item_id')
            ->map(fn (Collection $chain): int => $chain->max('version'))
            ->all();

        return $this->resolvedDocuments = $visible
            ->reject(fn (Document $document): bool => $this->belongsToASubmissionChain($document)
                && $document->version !== ($latestVersionByChain[$document->matter_checklist_item_id] ?? null))
            // Sắp theo `id` TĂNG dần trước — tiêu chí PHỤ, để hai (hoặc nhiều) tài liệu cùng
            // version của cùng một lần nộp (R10) giữ đúng thứ tự "mặt trước rồi mặt sau", theo
            // đúng thứ tự chúng được TẠO ra. `published_at` của cả lô bằng nhau (cùng một
            // transaction), nên một mình nó không phân định được gì giữa chúng.
            ->sortBy(fn (Document $document): int => $document->getKey())
            // Rồi sắp theo `published_at` GIẢM dần — tiêu chí CHÍNH. `sortByDesc` của Laravel là
            // một sắp xếp ỔN ĐỊNH (PHP `uasort` từ 8.0), nên thứ tự phụ vừa đặt ở trên được GIỮ
            // NGUYÊN giữa các phần tử có cùng `published_at`/`created_at` — đây là lý do hai lần
            // sắp phải đứng ĐÚNG thứ tự này, không phải ngược lại.
            ->sortByDesc(fn (Document $document) => $document->published_at ?? $document->created_at)
            ->map(fn (Document $document): array => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'issued_on' => $document->issued_at?->format('d/m/Y'),
                'download_url' => $this->canDownload($document) ? $this->downloadUrl($document) : null,
            ])
            ->values();
    }

    /**
     * Tài liệu này có nằm trong một chuỗi nộp lại không — tức có bị luật "chỉ bản mới nhất" ở
     * {@see self::documents()} động tới không.
     *
     * Định nghĩa lấy nguyên của `StoresDocumentFile::latestInSubmissionChain()`: nhóm A gắn vào
     * một đầu mục danh mục. Một tài liệu nhóm B hay C gắn vào cùng đầu mục là việc hợp lệ và
     * KHÔNG phải một lần nộp tờ giấy đó, nên nó không bao giờ che một bản nào và không bao giờ
     * bị che.
     *
     * **Điều kiện `group` ở đây SỐNG SÓT một lần đột biến, và câu đó phải được nói ra.** Bỏ nó
     * đi (chỉ còn "có gắn đầu mục danh mục") thì KHÔNG test nào đỏ: hôm nay không fixture nào
     * dựng một tài liệu nhóm B hay C gắn vào cùng một đầu mục với số `version` lớn hơn một bản
     * nhóm A. Giữ vì định nghĩa chuỗi ở `StoresDocumentFile` có đúng hai vế, và một chuỗi định
     * nghĩa rộng hơn ở đây sẽ để một văn bản của toà che mất tờ giấy khách vừa nộp — hai thứ
     * không liên quan gì đến nhau ngoài việc cùng trỏ vào một dòng danh mục.
     */
    private function belongsToASubmissionChain(Document $document): bool
    {
        return $document->group === DocumentGroup::ClientProvided
            && filled($document->matter_checklist_item_id);
    }

    /** Cờ thứ hai, hỏi qua policy (`DocumentPolicy::download` hỏi lại `view` trước). */
    private function canDownload(Document $document): bool
    {
        return Gate::forUser($this->viewer())->allows('download', $document);
    }

    /**
     * Đường tải **luôn** là URL đã ký của M4, ký cho đúng người đọc và sống 5 phút (SPEC §10.4).
     * Không bao giờ `Storage::url()` và không bao giờ một đường dẫn của medialibrary: cả hai đều
     * là đường đi vòng qua policy trong `DocumentDownloadController`, và chữ ký URL không thay
     * thế một lần kiểm tra quyền.
     */
    private function downloadUrl(Document $document): string
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
     * `days_left` tính ở đây chứ không trong view: nó là một phép tính, và một phép tính trong
     * Blade là một phép tính không ai đo được.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function deadlines(): Collection
    {
        $viewer = $this->viewer();

        return $this->resolvedDeadlines ??= $this->matter()->deadlines()->get()
            ->filter(fn (Deadline $deadline): bool => ! $deadline->is_completed
                && Gate::forUser($viewer)->allows('view', $deadline))
            ->map(fn (Deadline $deadline): array => [
                'name' => $deadline->name,
                'due_on' => $deadline->due_date->format('d/m/Y'),
                'days_left' => (int) today()->diffInDays($deadline->due_date, false),
            ])
            ->values();
    }

    // -------------------------------------------------------------------------------------
    // Khối 7 — Gửi yêu cầu
    // -------------------------------------------------------------------------------------

    /**
     * Lối vào màn hình gửi yêu cầu (SPEC §8.3 mục 7). **Seam của Task 4, đã được Task 6 lật.**
     *
     * Task 4 để hàm này trả `null` vì {@see MyRequests} chưa tồn tại, và khi `null` thì khối 7
     * đưa ra con đường CÓ THẬT lúc đó: số điện thoại văn phòng — một cái nút dẫn tới một trang
     * chưa có tệ hơn không có nút, vì nó biến một khách đang cần hỏi thành một khách vừa gặp lỗi.
     * Trang đó đã có, nên hàm trả URL của nó, và kiểu trả về nói thẳng điều đó: `string`, không
     * `?string`.
     *
     * Gọi bằng LỚP chứ không bằng một đường dẫn viết tay: trang kia sở hữu `$slug` và hình dạng
     * `{record}` của chính nó, nên một ngày nó đổi thì lời gọi này đi theo.
     *
     * **Cái giá của lần lật ấy đã được trả ở vòng này.** Khi hàm thôi trả `null`, nhánh `@else`
     * của view — câu "gọi điện" — không còn đường nào chạy tới, nên số điện thoại văn phòng lặng
     * lẽ biến mất khỏi trang; docblock cũ ghi lại việc đó và giao cho vòng hợp nhất. Khối 7 giờ
     * vẽ CẢ HAI, không rẽ nhánh: một cái nút gửi yêu cầu, và một số gọi được. Chúng không thay
     * thế nhau — một người đang lo lắng lúc chín giờ tối muốn gọi, không muốn điền biểu mẫu — và
     * một lời từ chối trên cổng (kể cả trang 404) cũng dẫn về đúng số ấy.
     */
    public function requestEntryPoint(): string
    {
        return MyRequests::getUrl(['record' => $this->matter()->getKey()]);
    }

    /**
     * Lối quay lại danh sách hồ sơ.
     *
     * **`getAllUrl()`, không `getUrl()`.** `MyMatters::getUrl()` là `/portal` trần, và `/portal`
     * với một khách có ĐÚNG MỘT hồ sơ chuyển hướng thẳng về chính trang này — tức một lối quay
     * lại dẫn người ta về chỗ họ đang đứng. Cờ `tat-ca` là thứ phân biệt hai chuyện đó, và trang
     * kia sở hữu cả tên cờ lẫn hình dạng URL của mình.
     */
    public function backToListUrl(): string
    {
        return MyMatters::getAllUrl();
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
     *
     * `#[Locked]` trên `$record` KHÔNG thay được lần hỏi này: khoá chặn một đường ghi từ trình
     * duyệt, còn đây là tầng trả lời câu hỏi "người đang đọc có được xem hồ sơ này không", thứ
     * phải được hỏi lại ở mọi request dù giá trị đến từ đâu.
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
     * Hồ sơ đang đọc. **`private`**: giá trị trả về của một phương thức công khai đi thẳng vào
     * response cập nhật Livewire, và bản ghi này mang id luật sư phụ trách cùng mọi cột nội bộ
     * khác của hồ sơ. Xem mục "bề mặt RPC" ở docblock lớp.
     */
    private function matter(): Matter
    {
        return $this->resolvedMatter ??= $this->resolveMatter();
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
