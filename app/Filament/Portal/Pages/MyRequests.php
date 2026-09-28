<?php

namespace App\Filament\Portal\Pages;

use App\Actions\Portal\OpenClientRequest;
use App\Actions\Portal\ReplyToClientRequest;
use App\Enums\ClientRequestStatus;
use App\Exceptions\ClientRequestNotOpen;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\ClientRequestActivity;
use Closure;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Gửi yêu cầu và đọc câu trả lời — SPEC §8.3 mục 7, đầu KHÁCH của cuộc trao đổi.
 *
 * Khối 7 của trang chi tiết hồ sơ dẫn tới đây ({@see MatterProgress::requestEntryPoint()}). Đầu
 * kia — hộp thư của văn phòng — là
 * {@see ClientRequestsRelationManager},
 * và hai đầu tồn tại cùng một lúc vì tiêu chí SPEC §14 mục 4 đòi khách **nhận được phản hồi**:
 * một yêu cầu gửi đi mà không ai trong văn phòng nhìn thấy thì tiêu chí đó không chứng minh được.
 *
 * # Mô hình hội thoại: TRẢ LỜI THEO LUỒNG
 *
 * Phán quyết 19/09/2026. Khách viết tiếp vào chính yêu cầu đã gửi (`client_request_replies`);
 * một yêu cầu mới là một luồng mới. Hệ quả nhìn thấy được trên màn hình này: mỗi yêu cầu là một
 * khối, trong khối có toàn bộ lượt trao đổi theo thứ tự thời gian, và một ô viết tiếp ở cuối —
 * chứ không phải một danh sách phẳng những câu rời rạc.
 *
 * # Phạm vi đọc: THEO `Client`, không theo `ClientUser`
 *
 * Phán quyết 19/09/2026, phát biểu một lần duy nhất ở
 * {@see ClientRequest::applyClientPortalConstraints()}. Trang này **không lặp lại** điều kiện
 * đó và không viết `where('client_id', ...)` ở đâu cả. Hệ quả cần nói thẳng với người đọc mã,
 * vì nó là một quyết định về sự riêng tư giữa hai người trong cùng một gia đình: **hai tài khoản
 * portal của cùng một khách hàng đọc — và viết được vào — cuộc trao đổi của nhau.**
 *
 * # Trang này tự hỏi `Gate`, và nó BẮT BUỘC phải tự hỏi
 *
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` **mặc định trả `true`** cho mọi trang
 * tuỳ chỉnh (đo ở Task 2), và nó là `static` nên nó không nhìn thấy `{record}` trên URL. Hệ quả:
 * `AnswerDeniedPanelRequestsWithNotFound` chỉ đổi HÌNH DẠNG của một lời từ chối đã có sẵn; nó
 * không bao giờ tự sinh ra một lời từ chối. Nên {@see self::resolveMatter()} hỏi
 * `Gate::forUser()` tường minh — `forUser()` chứ không `Gate::allows()`, vì facade mặc định phân
 * giải người dùng qua guard `web` và trên cổng khách hàng guard đó trống.
 *
 * `abort(404)` thẳng, không `abort(403)` rồi trông vào middleware: SPEC §10.10 đòi "không có
 * quyền" và "không tồn tại" cùng một câu trả lời, và middleware 404 **không** phủ request cập
 * nhật Livewire (Task 2 đã chứng minh trong vendor) — trong khi {@see self::submitReply()} chạy
 * đúng trên một request như vậy.
 *
 * # Thuộc tính công khai mang gì, và không mang gì
 *
 * Livewire chỉ serialize thuộc tính `public`. Ở đây có bốn: `$record` (một con số, **`#[Locked]`**)
 * và ba ô nhập — `$subject`, `$content`, `$replies`. {@see self::$resolvedMatter} và
 * {@see self::$resolvedThreads} là `private`, tức là bộ nhớ đệm TRONG MỘT REQUEST, nên mỗi lần
 * cập nhật đều phải giải quyết và gác lại từ đầu.
 *
 * `$replies` là một mảng công khai **khoá theo id yêu cầu**, và người dùng sửa được cả khoá lẫn
 * giá trị. Điều đó không sao và không phải một chỗ hở: khoá không bao giờ được tin —
 * {@see self::submitReply()} đọc lại hàng thật qua truy vấn đã có scope, hỏi `Gate`, rồi đưa cho
 * một Action tự hỏi lại tất cả một lần nữa. Một khoá bịa đặt là một 404.
 *
 * # Không nhắc tới `internal_note`, và không đưa model nào cho view
 *
 * Task 2 ghim ra một ranh giới: `internal_note` chỉ được canh ở TẦNG SERIALIZE, nên
 * `$log->internal_note` trong Blade trả về chuỗi thật. Luật cho mọi màn hình cổng là **không
 * nhắc tên cột đó**. Trang này đi xa hơn một bước vì nó phải vẽ ra **tên nhân sự**:
 * {@see self::threadEntries()} trả về một mảng **chuỗi đã chọn sẵn**, không phải model, và tên
 * người được lấy bằng `pluck('name', 'id')` — tức email, số điện thoại và `bar_number` của luật
 * sư **không bao giờ rời khỏi cơ sở dữ liệu**. `$reply->author` là một `MorphTo` không scope trả
 * về nguyên hàng `users`; đưa nó cho một view là mở đúng một trong năm bề mặt không scope mà
 * `PortalIsolationSweepTest` nêu tên.
 */
class MyRequests extends Page
{
    protected string $view = 'filament.portal.pages.my-requests';

    /**
     * Đường dẫn tiếng Việt không dấu: khách thỉnh thoảng đọc URL ra qua điện thoại cho trợ lý.
     * Cùng lý lẽ đã chọn `ho-so` cho {@see MatterProgress}.
     */
    protected static ?string $slug = 'yeu-cau';

    /**
     * Không vào thanh điều hướng: trang này luôn nói về MỘT hồ sơ, nên một mục menu không có
     * tham số thì không trỏ đi đâu cả. Lối vào là khối 7 của trang chi tiết hồ sơ.
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Tham số trên URL — id hồ sơ. Không dùng model binding, cố ý: xem
     * {@see self::resolveMatter()}.
     *
     * **`#[Locked]`, và đây là một điều kiện đo được chứ không phải một lớp sơn.** Rà soát Task 4
     * đo trên HTTP thật rằng một thuộc tính công khai KHÔNG khoá nhận được giá trị mới từ trình
     * duyệt qua `updates:{"record": …}` của Livewire — trên `MatterProgress` điều đó cho một
     * khách tự đổi sang một hồ sơ KHÁC của chính mình giữa chừng và lấy về mảnh trang của hồ sơ
     * đó. Cách ly giữa các khách hàng vẫn đứng (hồ sơ của khách khác 404 ở cả hai đường), nhưng
     * "trình duyệt đặt lại được tham số định danh" là một điều kiện không ai muốn phải chứng minh
     * lại ở từng nhánh. Ở đây trình duyệt KHÔNG bao giờ cần đặt nó: nó được đặt đúng một lần ở
     * {@see self::mount()}, từ URL.
     *
     * Khoá KHÔNG thay cho việc gác: mỗi request vẫn đọc lại hồ sơ và hỏi lại `Gate`
     * ({@see self::resolveMatter()}), và mỗi id yêu cầu đi vào {@see self::submitReply()} vẫn
     * được giải quyết lại từ đầu. Hai thứ đó là tầng thật; `#[Locked]` chỉ cắt bớt một bề mặt.
     */
    #[Locked]
    public int|string $record;

    /** Ô "gửi một yêu cầu mới". Trình duyệt ĐƯỢC đặt hai ô này — đó là việc của chúng. */
    public string $subject = '';

    public string $content = '';

    /**
     * Ô "viết thêm", một ô cho mỗi luồng, khoá theo id yêu cầu.
     *
     * @var array<int|string, string>
     */
    public array $replies = [];

    private ?Matter $resolvedMatter = null;

    /** @var Collection<int, ClientRequest>|null */
    private ?Collection $resolvedThreads = null;

    /**
     * `{record}` nối vào đường dẫn ở đây chứ không ở `$slug`: `getRelativeRouteName()` dựng tên
     * route từ chính `getSlug()`, nên nhét tham số vào slug sẽ sinh ra một tên route mang dấu
     * ngoặc nhọn. Cùng hình dạng với {@see MatterProgress::getRoutePath()}.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{record}';
    }

    /**
     * Cổng của cả TRANG. Điều kiện `instanceof ClientUser` không thừa bên cạnh lời gọi `Gate`:
     * `ClientRequestPolicy::viewAny` cũng trả `true` cho nhân sự, và hai panel dùng chung cookie
     * phiên, nên không có nó thì một nhân sự đang mở /admin đi thẳng vào được màn hình khách
     * hàng — nơi mọi truy vấn phía dưới lại giả định có một `ClientUser`.
     */
    public static function canAccess(): bool
    {
        $clientUser = Filament::auth()->user();

        return $clientUser instanceof ClientUser
            && Gate::forUser($clientUser)->allows('viewAny', ClientRequest::class);
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
        return __('requests.portal.title', ['code' => $this->matter()->code]);
    }

    public function getHeading(): string|Htmlable
    {
        return __('requests.portal.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('requests.portal.subheading', [
            'code' => $this->matter()->code,
            'title' => $this->matter()->title,
        ]);
    }

    public function matter(): Matter
    {
        return $this->resolvedMatter ??= $this->resolveMatter();
    }

    /** Lối quay lại trang chi tiết hồ sơ — khối 7 của SPEC §8.3 là nơi khách vừa đi ra. */
    public function backUrl(): string
    {
        return MatterProgress::getUrl(['record' => $this->matter()->getKey()]);
    }

    /**
     * Các cuộc trao đổi của hồ sơ này, HOẠT ĐỘNG GẦN NHẤT trên cùng.
     *
     * **Cùng cột, cùng chiều với hộp thư của văn phòng (fix round 1, ruling).** Bản trước sắp
     * theo `created_at` — lúc khách GỬI — trong khi tab nội bộ
     * ({@see ClientRequestsRelationManager})
     * đã đổi sang `last_activity_at` ở chính milestone này (Task 18, REQ-2). Hai màn hình của
     * MỘT cuộc trao đổi sắp khác nhau là một cách âm thầm nói hai câu chuyện khác nhau về "luồng
     * nào đang cần chú ý" — khách thấy luồng cũ đã có hồi âm mới nằm dưới một luồng mới nhưng im
     * lặng, đúng lúc văn phòng (nhìn hộp thư của mình) thấy nó nằm trên. Ranh giới chính xác của
     * "hoạt động" — bốn nguồn, và `assign()` chỉ tính khi cũng đổi trạng thái — nằm ở docblock
     * migration `add_last_activity_at_to_client_requests_table`.
     *
     * Hai tầng: truy vấn (`ClientPortalScope` cắt theo khách đang đăng nhập — không một dòng nào
     * ở đây viết `where('client_id', ...)`), rồi `Gate::allows('view', ...)` trên **từng** bản
     * ghi.
     *
     * **Tầng thứ hai giữ cái gì, nói bằng phép đo chứ không bằng một lời hứa.** Tập hợp xuất phát
     * từ `$this->matter()->clientRequests()`, tức đã bị giới hạn theo đúng hồ sơ mà
     * {@see self::resolveMatter()} vừa gác — nên một yêu cầu của khách hàng khác, trên một hồ sơ
     * khác, **không có đường nào** vào đây để mà bị từ chối. Thứ lần lọc `Gate` này thật sự giữ
     * là những điều kiện mà `ClientRequestPolicy::view()` phát biểu bằng THUỘC TÍNH trên chính
     * bản ghi — hôm nay là `! $request->trashed()` — tức những câu không chung một lệnh nào với
     * chuỗi `where` của scope. Đo được ở `MyRequestsTest`, test "keeps a retracted thread off the
     * page even with both scopes emptied": làm rỗng CẢ `ClientPortalScope` LẪN `SoftDeletingScope`
     * thì hàng đã rút lọt vào quan hệ, và xoá dòng `filter()` dưới đây làm test đó ĐỎ.
     *
     * (Bản đầu của test ấy thay `SoftDeletingScope` bằng một scope mang tên KHÁC, nên nó chưa bao
     * giờ chạm tới điều kiện nó nêu tên và lần lọc này sống sót một mutation probe. Ghi lại vì
     * đó là đúng hình dạng "fixture đoản mạch" mà kế hoạch M5 lên án.)
     *
     * @return Collection<int, ClientRequest>
     */
    public function threads(): Collection
    {
        if ($this->resolvedThreads !== null) {
            return $this->resolvedThreads;
        }

        $viewer = $this->viewer();

        return $this->resolvedThreads = $this->matter()->clientRequests()
            ->with(['replies'])
            ->orderByDesc('last_activity_at')
            ->get()
            ->filter(fn (ClientRequest $request): bool => Gate::forUser($viewer)->allows('view', $request))
            ->values();
    }

    /**
     * **Trạng thái bằng TIẾNG NGƯỜI, không bao giờ bằng tên enum.**
     *
     * `lang/vi/enums.php` đã có nhãn ngắn cho bốn trạng thái ("Mới", "Đang xử lý", …) và panel
     * nội bộ dùng đúng chúng — đó là từ vựng làm việc của văn phòng. Nhưng chữ "Mới" trên màn
     * hình một khách hàng đang lo không nói được điều họ cần biết: *đã có ai nhìn thấy cái tôi
     * gửi chưa, và bao giờ tôi có câu trả lời*. Nên ở đây mỗi trạng thái là một CÂU, lấy từ
     * `requests.portal.status.*`. Không phải một bản dịch thứ hai của cùng một thứ — hai bên bàn
     * cần biết hai điều khác nhau về cùng một dòng dữ liệu.
     *
     * **`answered` có HAI câu, không một (REQ-5).** `TriageClientRequest::setStatus()` cho phép
     * đặt thẳng `answered` mà không cần viết câu trả lời nào — ca có chủ đích, "luật sư trả lời
     * qua điện thoại rồi đánh dấu thẳng Đã trả lời". Câu mặc định mời khách "xem bên dưới", và
     * khi không có lời trả lời nào viết ra thì bên dưới đó TRỐNG — một lời mời đi vào một khoảng
     * trắng. Nên câu đổi tuỳ theo `$request->replies` có dòng nào của NHÂN SỰ hay không; khách tự
     * hỏi tiếp (dòng `author_type` = `ClientUser`) không tính, vì đó không phải câu trả lời.
     *
     * **Final review C-M5: câu của văn phòng phải đứng SAU lần viết cuối của khách.** Một câu trả
     * lời có trước câu khách hỏi thêm không trả lời câu hỏi thêm đó — "xem bên dưới" khi ấy chỉ
     * khách tới một câu cũ. Thứ tự đọc theo `id` của dòng trả lời (cùng thứ tự luồng hiện ra).
     */
    public function statusLine(ClientRequest $request): string
    {
        if ($request->status === ClientRequestStatus::Answered && ! $this->hasStaffReplyAfterClientsLastEntry($request)) {
            return __('requests.portal.status.answered_by_phone');
        }

        return __('requests.portal.status.'.$request->status->value);
    }

    /**
     * @see self::statusLine()
     *
     * **M6 Task 4 (`requests/REQ-4`): định nghĩa chuyển sang {@see ClientRequestActivity}.** Chỗ
     * dùng chung này phục vụ THÊM `MyMatters`/`MatterProgress` (huy hiệu "có trả lời mới") mà
     * không gọi một trang Filament từ trang khác — phán quyết controller, task-4-brief.md. Hành
     * vi không đổi: cùng một phép so sánh, chỉ đổi chỗ ở.
     */
    private function hasStaffReplyAfterClientsLastEntry(ClientRequest $request): bool
    {
        return ClientRequestActivity::hasStaffReplyAfterClientsLastEntry($request);
    }

    /**
     * Cuộc trao đổi, **đã chiếu thành chuỗi**. View không cầm một model nào.
     *
     * Ba lý do, theo thứ tự nặng dần:
     *
     *  1. `$reply->author` là một `MorphTo` **không có scope nào**, nên với một dòng do nhân sự
     *     viết nó trả về nguyên hàng `users` — kèm `email`, `phone` và `bar_number`.
     *     `PortalIsolationSweepTest` nêu đích danh bề mặt này. Tên người ở đây lấy bằng
     *     `pluck('name', 'id')`, tức ba cột kia **không rời khỏi cơ sở dữ liệu**, chứ không phải
     *     "có nạp nhưng không in ra".
     *  2. Một mảng chuỗi không có gì để `->internal_note` bám vào — luật "không màn hình cổng nào
     *     nhắc tới cột đó" (Task 2) trở thành đúng về CẤU TRÚC chứ không nhờ trí nhớ người viết
     *     view. Cùng thiết bị `MyMatters::getCards()` dùng.
     *  3. Tên tài khoản khách cũng đi qua một truy vấn **giới hạn theo `client_id` của người
     *     đang đọc**, không phải qua quan hệ: `ClientUser` là bề mặt không scope thứ nhất trong
     *     danh sách của Task 2 ("rò rỉ tên/email/điện thoại của mọi người liên hệ ở tầng truy
     *     vấn"). Một id lọt ra ngoài phạm vi đó không được đi tìm một hàng nó không được đọc: nó
     *     rơi về một nhãn chung — `requests.portal.history.from_client` ("Anh/chị viết") cho một
     *     tài khoản khách, `…unknown_author` ("Văn phòng") cho một nhân sự đã biến mất.
     *
     * Một luật sư đã nghỉ việc (`User` xoá mềm) vẫn phải hiện tên: câu họ viết cho khách đã được
     * gửi đi rồi, và một dòng "không rõ ai" trong lịch sử trao đổi là một dòng làm người đọc mất
     * tin. Nên truy vấn dùng `withTrashed()`.
     *
     * **`role` có BA giá trị, không hai (REQ-8).** Phán quyết 19/09/2026 cho hai tài khoản portal
     * của cùng một khách hàng đọc VÀ VIẾT chung một luồng (xem docblock lớp), nhưng trước bản sửa
     * này mọi dòng do một `ClientUser` viết đều mang `role: 'client'`, và blade gắn nhãn "Anh/chị
     * viết" cho MỌI dòng đó — kể cả dòng do người NHÀ KHÁC của cùng khách hàng viết. Người đang
     * xem trang thấy câu của người kia dưới nhãn "Anh/chị viết", như thể chính họ đã gõ ra câu đó.
     * Nên ở đây `role` phân biệt `'client_self'` (đúng người đang xem — `author_id` trùng
     * `$this->viewer()->getKey()`) và `'client_sibling'` (một tài khoản KHÁC của cùng khách hàng).
     * Nhãn "Anh/chị viết" chỉ gắn cho `'client_self'`; view mang tên người viết cho
     * `'client_sibling'`, không mượn nhãn đó — xem `my-requests.blade.php`.
     *
     * @return list<array{author: string, role: string, content: string, at: string}>
     */
    public function threadEntries(ClientRequest $request): array
    {
        $names = $this->authorNames($request);
        $viewerId = $this->viewer()->getKey();

        $entries = [$this->clientEntry(
            $request->client_user_id,
            (string) $request->content,
            $request->created_at->format('H:i d/m/Y'),
            $names,
            $viewerId,
        )];

        foreach ($request->replies as $reply) {
            $fromOffice = $reply->author_type === (new User)->getMorphClass();

            $entries[] = $fromOffice
                ? [
                    'author' => $names['staff'][$reply->author_id] ?? __('requests.portal.history.unknown_author'),
                    'role' => 'office',
                    'content' => (string) $reply->content,
                    'at' => $reply->created_at->format('H:i d/m/Y'),
                ]
                : $this->clientEntry(
                    $reply->author_id,
                    (string) $reply->content,
                    $reply->created_at->format('H:i d/m/Y'),
                    $names,
                    $viewerId,
                );
        }

        return $entries;
    }

    /**
     * Một dòng do một `ClientUser` viết — của chính người đang xem, hay của người nhà. Tách riêng
     * vì {@see self::threadEntries()} gọi nó ở CẢ HAI chỗ (câu hỏi đầu luồng và mọi lượt viết
     * tiếp), và hai lần chép tay phép so sánh id là hai chỗ để nó lệch nhau.
     *
     * **Tên dự phòng KHÔNG BAO GIỜ là `history.from_client` ("Anh/chị viết") — fix round 1,
     * minor.** Bản trước dùng chung một khoá cho cả HAI việc khác nhau: (1) nhãn "Anh/chị viết"
     * đứng TRƯỚC tên trong blade, cho vai `client_self`; (2) tên dự phòng khi
     * `$names['client'][$authorId]` không tìm thấy gì — một id lọt ra ngoài phạm vi
     * `where('client_id', ...)` của {@see self::authorNames()} (dữ liệu hỏng: `author_id` trỏ
     * sang một khách hàng khác). Dùng chung nghĩa là một dòng `client_sibling` với tên KHÔNG giải
     * quyết được sẽ hiện literal "Anh/chị viết" như thể đó là TÊN của người nhà — đúng câu REQ-8
     * cấm, chỉ đổi chỗ. Nay tên dự phòng là một khoá RIÊNG, trung lập
     * (`history.unknown_client_author`, "Người cùng khách hàng"), không mượn nhãn của vai
     * `client_self`.
     *
     * @param  array{staff: Collection<int, string>, client: Collection<int, string>}  $names
     * @return array{author: string, role: string, content: string, at: string}
     */
    private function clientEntry(?int $authorId, string $content, string $at, array $names, int|string $viewerId): array
    {
        return [
            'author' => $names['client'][$authorId] ?? __('requests.portal.history.unknown_client_author'),
            'role' => $authorId !== null && (string) $authorId === (string) $viewerId
                ? 'client_self'
                : 'client_sibling',
            'content' => $content,
            'at' => $at,
        ];
    }

    /**
     * Ô "viết thêm" chỉ hiện khi cuộc trao đổi còn nhận chữ.
     *
     * Hai câu hỏi rời nhau, cố ý — cùng thành ngữ `ChecklistRelationManager` dùng cho ba nút của
     * nó: `Gate` hỏi về QUYỀN, trạng thái `closed` là một câu về BẢN GHI. Không cổng nào ở đây là
     * cổng thật; {@see ReplyToClientRequest} hỏi lại cả hai và không tin màn hình đã lọc.
     */
    public function canReplyTo(ClientRequest $request): bool
    {
        return ClientRequestNotOpen::accepts($request->status)
            && Gate::forUser($this->viewer())->allows('create', [ClientRequestReply::class, $request]);
    }

    // -------------------------------------------------------------------------------------
    // Ghi — mọi lần ghi đi qua một Action, không một dòng nghiệp vụ nào ở đây
    // -------------------------------------------------------------------------------------

    public function submitRequest(): void
    {
        $this->runAction(function (): void {
            app(OpenClientRequest::class)->handle(
                $this->matter(),
                $this->viewer(),
                $this->subject,
                $this->content,
            );

            $this->subject = '';
            $this->content = '';
            $this->resolvedThreads = null;

            Notification::make()->title(__('requests.portal.new.sent'))->success()->send();
        });
    }

    /**
     * `$requestId` đến từ một thuộc tính công khai của Livewire, tức từ người dùng. Nó được giải
     * quyết lại qua {@see self::threads()} — truy vấn đã có scope **cộng** một lần hỏi `Gate` cho
     * từng bản ghi — trước khi chạm tới Action, và Action lại hỏi lại tất cả một lần nữa trên bản
     * đọc lại của chính nó. Một id của khách hàng khác đi ra bằng **404**, giống hệt một id không
     * tồn tại (SPEC §10.10).
     *
     * **Hai tầng, và phép đo nói rõ tầng nào đang giữ cái gì.** Thay lần giải quyết ở đây bằng
     * một truy vấn KHÔNG scope mà vẫn giữ `abort_if` thì bộ test vẫn xanh — vì Action từ chối và
     * {@see self::runAction()} đổi lời từ chối đó thành đúng 404 ấy. Xoá hẳn `abort_if` thì test
     * ĐỎ, và nó đỏ vì một `TypeError` (lỗi 500) chứ không vì một lời từ chối: đó chính là lý do
     * câu lệnh này tồn tại — một 500 và một 404 nói hai điều khác nhau cho người đứng ngoài đếm.
     */
    public function submitReply(int|string $requestId): void
    {
        $thread = $this->threads()->first(
            fn (ClientRequest $request): bool => (string) $request->getKey() === (string) $requestId,
        );

        abort_if($thread === null, 404);

        $this->runAction(function () use ($thread, $requestId): void {
            app(ReplyToClientRequest::class)->handle(
                $thread,
                $this->viewer(),
                $this->replies[$requestId] ?? '',
            );

            unset($this->replies[$requestId]);
            $this->resolvedThreads = null;

            Notification::make()->title(__('requests.portal.reply.sent'))->success()->send();
        }, (string) $requestId);
    }

    /**
     * **Mọi lời từ chối của Action phải đến được mắt khách hàng, bằng tiếng Việt.** Bài học M3
     * Task 9, viết lại cho cổng: một `DomainException` mà màn hình không bắt riêng là một lỗi
     * **500 trên màn hình một khách hàng đang lo về vụ việc của mình**.
     *
     * Ba họ, ba đường, và chúng KHÔNG thay thế được cho nhau:
     *
     *  - `ValidationException` — Action nói về một Ô NHẬP SAI và đã gắn sẵn tên ô (`subject`,
     *    `content`). Tên đó trùng tên thuộc tính Livewire, nên ném lại nguyên vẹn là đủ: câu lỗi
     *    hiện ngay dưới đúng cái ô, và Livewire giữ nguyên chữ khách đã gõ. Với ô "viết thêm",
     *    khoá được đổi thành `replies.<id>` — state path thật của ô đó.
     *  - `AuthorizationException` — **404**, không phải một thông báo. SPEC §10.10: không có
     *    quyền và không tồn tại là cùng một câu trả lời, và một thông báo màu đỏ nói "không mở
     *    được" trên một trang vẫn hiện ra là một câu trả lời KHÁC với 404.
     *  - `DomainException` còn lại (hôm nay chỉ có {@see ClientRequestNotOpen})
     *    — Action nói về TRẠNG THÁI của một bản ghi, không về một ô nào. Ra bằng một
     *    `Notification` `persistent()`: câu này dài (nó nói ra việc cần làm tiếp theo, SPEC §8.4)
     *    và một thông báo tự tắt sau vài giây là một câu không ai đọc hết.
     *
     * Bất cứ thứ gì ngoài ba họ trên vẫn thoát ra thành lỗi 500, cố ý: một `TypeError` hay một
     * lỗi hạ tầng không phải một câu để nói với khách hàng, và nuốt nó ở đây biến một sự cố thật
     * thành một dòng đỏ không ai đi điều tra. Cùng phán quyết với `ReportsActionFailures`.
     *
     * @param  Closure(): void  $callback
     */
    private function runAction(Closure $callback, ?string $replyKey = null): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            if ($replyKey === null) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'replies.'.$replyKey => $exception->errors()['content'] ?? [$exception->getMessage()],
            ]);
        } catch (AuthorizationException) {
            abort(404);
        } catch (DomainException $exception) {
            Notification::make()
                ->title(__('actions.failed_title'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    // -------------------------------------------------------------------------------------

    /**
     * Tên người viết, **chỉ tên**, lấy bằng hai truy vấn chiếu cột.
     *
     * @return array{staff: Collection<int, string>, client: Collection<int, string>}
     */
    private function authorNames(ClientRequest $request): array
    {
        $staffMorph = (new User)->getMorphClass();

        $staffIds = $request->replies
            ->where('author_type', $staffMorph)
            ->pluck('author_id')
            ->filter()
            ->unique();

        $clientIds = $request->replies
            ->where('author_type', '!=', $staffMorph)
            ->pluck('author_id')
            ->push($request->client_user_id)
            ->filter()
            ->unique();

        return [
            // `withTrashed()`: một luật sư đã nghỉ việc vẫn phải hiện tên — câu họ viết đã được
            // gửi cho khách rồi. `pluck('name', 'id')` chiếu đúng hai cột, nên `email`, `phone`
            // và `bar_number` không rời khỏi cơ sở dữ liệu.
            'staff' => $staffIds->isEmpty()
                ? collect()
                : User::withTrashed()->whereKey($staffIds)->pluck('name', 'id'),

            // Giới hạn theo `client_id` của NGƯỜI ĐANG ĐỌC: `ClientUser` không có global scope
            // nào (bề mặt được nêu tên ở `PortalIsolationSweepTest`), nên điều kiện đó phải được
            // viết ra ở đây. Nó không nới gì cả — mọi dòng trả lời trong `$request` đã thuộc một
            // cuộc trao đổi người này đọc được — nhưng nó bảo đảm một dữ liệu hỏng (một
            // `author_id` trỏ sai) không biến thành một lần đọc tên người của khách hàng khác.
            'client' => $clientIds->isEmpty()
                ? collect()
                : ClientUser::withTrashed()
                    ->where('client_id', $this->viewer()->client_id)
                    ->whereKey($clientIds)
                    ->pluck('name', 'id'),
        ];
    }

    /**
     * Đọc lại hồ sơ qua truy vấn đã có scope, rồi hỏi `Gate` một lần nữa — hai tầng, hai câu
     * lệnh khác nhau, không chung một điều kiện nào. Cùng hình dạng với
     * {@see MatterProgress::resolveMatter()}, cố ý: hai trang cùng một tham số URL phải từ chối
     * giống hệt nhau, nếu không thì hiệu số giữa chúng là một máy dò sự tồn tại.
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
