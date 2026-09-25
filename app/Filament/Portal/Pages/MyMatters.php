<?php

namespace App\Filament\Portal\Pages;

use App\Actions\Document\ChecklistProgress;
use App\Enums\ChecklistItemStatus;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Danh sách hồ sơ của khách hàng — SPEC §8.2. Màn hình ĐẦU TIÊN khách thấy sau khi đăng nhập.
 *
 * Mỗi hồ sơ là một **thẻ**, không phải một dòng bảng: một bảng cuộn ngang trên màn hình 375px là
 * một bảng không dùng được, và tài liệu bộ công cụ §4 đặt điện thoại làm khổ thiết kế gốc của
 * cổng này.
 *
 * # Trang tự hỏi `Gate`, cho TỪNG bản ghi nó vẽ
 *
 * `Filament\Pages\Concerns\CanAuthorizeAccess::canAccess()` mặc định trả **`true`** cho trang
 * tuỳ biến (đo được, ghi nhận ở Task 2 của M5). `AnswerDeniedPanelRequestsWithNotFound` vì thế
 * chỉ đổi HÌNH DẠNG của một lần từ chối — 403 thành 404 — chứ không bao giờ SINH RA một lần từ
 * chối. Hệ quả: một trang cổng chỉ dựa vào global scope không có tầng phòng thủ thứ hai nào cả,
 * nó chỉ có tầng truy vấn được đếm hai lần.
 *
 * Nên trang này hỏi `Gate` ở hai chỗ, và cả hai đều có test — nhưng hai chỗ ấy **không mạnh
 * ngang nhau**, và docblock của từng chỗ nói ra chính xác nó chặn được gì:
 *
 *  - {@see self::canAccess()} hỏi `viewAny` trước khi ai đó vào được trang. Đọc docblock của nó
 *    trước khi tin vào nó: hôm nay điều kiện DUY NHẤT nó từ chối được là "không có ai đăng nhập
 *    trên guard `client`";
 *  - {@see self::getCards()} hỏi `view` cho **từng** vụ việc trước khi vẽ nó, kể cả khi truy vấn
 *    đã trả nó về. `MatterPolicy::view` nhánh khách phát biểu lại ba điều kiện của
 *    `Matter::applyClientPortalConstraints()` bằng thuộc tính (`releasedToPortal()`) rồi hỏi lại
 *    scope thật qua `ClientPortalScope::actingAs()`, nên hai tầng không chung một câu lệnh nào.
 *    `MyMattersTest` đo đúng điều đó bằng cách thay global scope của `Matter` bằng một scope
 *    rỗng: tầng truy vấn thủng, trang vẫn không vẽ hồ sơ của khách khác.
 *
 * # `internal_note` và mọi cột nội bộ khác không với tới được từ đây
 *
 * Task 2 đã ghi thành luật: `internal_note` chỉ được canh ở **tầng serialize**, nên
 * `$log->internal_note` trong một view Blade vẫn trả về chuỗi thật và không màn hình cổng nào
 * được phép nhắc tới cột đó. Trang này không nhắc — và nó còn đi xa hơn một bước để câu ấy đúng
 * về mặt CẤU TRÚC chứ không nhờ trí nhớ của người viết view: {@see self::getCards()} trả về
 * một mảng **giá trị đã chọn sẵn**, không phải model. View không cầm `Matter` nào trong tay, nên
 * không có gì để `->internal_note` hay `->description_internal` bám vào.
 *
 * # `X/Y` không được tính ở đây
 *
 * Luật đếm của SPEC §4.10 (kèm đính chính 2026-09-16) sống ở {@see ChecklistProgress}. M4 dời nó
 * vào một Action đúng để M5 khỏi viết lần thứ hai, và vòng hợp nhất M4 đã đo rằng bản cũ trả
 * **hai con số khác nhau** cho cùng một hồ sơ tuỳ guard nào đang mở — sai ở đúng phía người đọc
 * nó, tức phía khách hàng. Trang này gọi Action và in ra; nó không biết công thức.
 *
 * Một ngoại lệ, nói ra chứ không giấu: {@see self::countedByProgress()} nói lại **một** dòng của
 * luật ấy — điều kiện một đầu mục có nằm trong `Y` hay không — vì `ChecklistProgress::handle()`
 * trả về hai số nguyên chứ không trả về các dòng, và huy hiệu đỏ phải đếm trên đúng tập dòng ấy
 * để hai câu trên cùng một tấm thẻ không ngược nhau. Lý do đầy đủ và cách nó được ghim nằm ở
 * docblock của chính phương thức đó.
 *
 * # Đường dẫn gốc của panel
 *
 * `getRoutePath()` trả `/`, nên trang này là `/portal`: SPEC §8.2 mô tả nó như màn hình sau khi
 * đăng nhập, và `Filament\Auth\Pages\Login` chuyển hướng về `Filament::getUrl()` — tức
 * `url('/portal')`, vì panel không đăng ký route tên `home`. `PortalPanelProvider` vì thế
 * **không đăng ký `Filament\Pages\Dashboard`** nữa; lý do đầy đủ ở docblock cạnh `->pages([])`
 * trong provider, và nó không chỉ là chuyện thẩm mỹ: hai trang cùng ở `/` thì route của trang
 * này bị ghi đè mất, mục điều hướng của nó gọi `route()` lên một tên không tồn tại, và MỌI
 * trang cổng đã xác thực vỡ khi vẽ thanh bên.
 *
 * # Một hồ sơ thì không bắt ai bấm thêm một lần
 *
 * Phần lớn khách của văn phòng chỉ có MỘT hồ sơ. Với họ, danh sách này là một màn hình hiện đúng
 * một thẻ rồi đợi họ chạm vào nó — nên {@see self::mount()} chuyển thẳng sang trang tiến độ.
 * Hai điều kiện ràng buộc cách viết nó, và cả hai đều có test:
 *
 *  - hồ sơ được chuyển tới phải đến từ {@see self::getCards()} — tức đã đi qua `Gate` — chứ
 *    không bao giờ từ một `Matter::first()`;
 *  - và nó **không được nổ** khi khách cố ý quay lại danh sách từ trang chi tiết, nếu không
 *    một khách có đúng một hồ sơ sẽ không bao giờ mở được màn hình này nữa. Lối quay lại đi
 *    qua {@see self::getAllUrl()}, một URL mang cờ `?tat-ca=1` — và cờ ấy phải có một NƠI GỌI
 *    thật, không chỉ một phương thức đẹp đẽ. Nơi gọi là {@see self::getNavigationUrl()}, tức
 *    chính mục "Hồ sơ của tôi" trên thanh bên; bản đầu để mặc định của Filament trỏ vào
 *    `/portal` trần và mục ấy chết với đúng người tính năng này được thiết kế cho.
 *    Lối quay lại thứ hai — một nút trong chính trang chi tiết — thuộc về `MatterProgress`
 *    và vòng sửa của Task 4, không về tệp này.
 *
 * `RecordStageLogView` **không** được gọi ở đây. Bảng đó là bằng chứng khách ĐÃ ĐỌC một dòng
 * tiến độ, và một lần chuyển hướng thì chưa ai đọc gì cả; `MatterProgress` ghi nó khi thật sự
 * vẽ dòng đó ra (phán quyết 19/09/2026 của người điều phối).
 */
class MyMatters extends Page
{
    protected string $view = 'filament.portal.pages.my-matters';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    /**
     * Nhỏ hơn `Filament\Pages\Dashboard` (-2), để mục đầu tiên trong thanh điều hướng của khách
     * luôn là hồ sơ của chính họ — kể cả nếu một ngày nào đó có ai đăng ký lại Dashboard trên
     * panel này. `Panel::getRedirectUrl()` cũng đọc mục điều hướng ĐẦU TIÊN, nên con số này
     * không chỉ là thứ tự hiển thị.
     */
    protected static ?int $navigationSort = -3;

    /** Đỏ — còn giấy tờ khách phải nộp (SPEC §8.2 "huy hiệu đỏ nếu còn giấy tờ cần nộp"). */
    public const TONE_OUTSTANDING = 'outstanding';

    /** Vàng — khách đã nộp, đang chờ văn phòng kiểm tra. */
    public const TONE_WAITING = 'waiting';

    /** Xanh — không còn gì để chờ ở cả hai phía. */
    public const TONE_SETTLED = 'settled';

    /**
     * Cờ trên URL nói "khách CỐ Ý muốn xem cả danh sách", tức tắt lần chuyển hướng một-hồ-sơ.
     *
     * Tiếng Việt không dấu vì khách thỉnh thoảng đọc URL ra qua điện thoại cho trợ lý — cùng lý
     * lẽ đã chọn `ho-so` cho `MatterProgress`.
     */
    public const SHOW_ALL_PARAMETER = 'tat-ca';

    /**
     * Ghi nhớ trong phạm vi một request: {@see self::mount()} cần biết có bao nhiêu thẻ, và
     * {@see self::getCards()} lại được view gọi lần nữa. Không có chỗ nhớ này thì mỗi lần tải
     * trang chạy toàn bộ phép đếm hai lần, tức gấp đôi `2N + 3` mà `MyMattersTest` đang ghim.
     *
     * `private` là cố ý: Livewire chỉ serialize thuộc tính `public`, nên chỗ nhớ này không đi
     * theo sang request cập nhật và không bao giờ trả lại một danh sách cũ.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $cards = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    /**
     * URL của chính trang này kèm cờ "xem tất cả". Trang chi tiết dùng nó cho lối quay lại, và
     * đó là đường DUY NHẤT để một khách có đúng một hồ sơ nhìn thấy màn hình này.
     */
    public static function getAllUrl(): string
    {
        return static::getUrl([self::SHOW_ALL_PARAMETER => 1]);
    }

    /**
     * Mục "Hồ sơ của tôi" trên thanh bên trỏ vào {@see self::getAllUrl()}, không vào `/portal` trần.
     *
     * Đây là phương thức Filament THẬT SỰ dựng mục điều hướng từ đó — đã đọc trong bản đang cài,
     * không viết từ trí nhớ: `Filament\Pages\Page::getNavigationItems()` gọi
     * `NavigationItem::make(...)->url(static::getNavigationUrl())`, và bản mặc định
     * `Page::getNavigationUrl()` chỉ trả `static::getUrl()`.
     *
     * Vì sao phải đổi: `getUrl()` là `/portal`, và `/portal` với một khách có đúng MỘT hồ sơ là
     * một lần chuyển hướng ngược về trang chi tiết họ vừa đứng ({@see self::mount()}). Tức mục
     * điều hướng chết với đúng người mà tính năng này được thiết kế cho — phần lớn khách của văn
     * phòng chỉ có một hồ sơ. Đo được ở vòng rà soát Task 3: `GET /portal` → 302, `GET
     * /portal?tat-ca=1` → 200.
     *
     * `isActiveWhen` **không** bị ảnh hưởng: `getNavigationItems()` dựng nó từ
     * `original_request()->routeIs(static::getNavigationItemActiveRoutePattern())`, tức từ TÊN
     * route chứ không từ URL, nên cờ trên chuỗi truy vấn không làm mục điều hướng mất trạng thái
     * "đang mở".
     */
    public static function getNavigationUrl(): string
    {
        return static::getAllUrl();
    }

    /**
     * `$showAll` là tham số `mount()` để test lái thẳng component; trên một request thật Livewire
     * không có gì để truyền vào đó, nên giá trị rơi về chuỗi truy vấn.
     *
     * Điều kiện là `=== 1`, không phải `<= 1`: không hồ sơ nào thì màn hình này CÓ việc để làm —
     * nó nói ra câu hướng dẫn và hai cách gọi văn phòng (tài liệu bộ công cụ §4), nên chuyển
     * hướng một khách như vậy đi đâu đó là lấy mất đúng thứ họ cần.
     *
     * `MatterProgress` được gọi bằng lớp chứ không bằng một đường dẫn viết tay: trang đó sở hữu
     * `$slug` và hình dạng `{record}` của chính nó, nên một ngày nó đổi thì lời gọi này đi theo.
     */
    public function mount(?bool $showAll = null): void
    {
        if ($showAll ?? request()->boolean(self::SHOW_ALL_PARAMETER)) {
            return;
        }

        $cards = $this->getCards();

        if (count($cards) === 1) {
            $this->redirect(MatterProgress::getUrl(['record' => $cards[0]['id']]), navigate: false);
        }
    }

    /**
     * SPEC §10.10: không có quyền và không tồn tại đều là 404, nên câu trả lời ở đây chỉ là
     * `true`/`false` và `AnswerDeniedPanelRequestsWithNotFound` lo phần hình dạng — **nhưng chỉ
     * trên request tải trang**. Rà soát M4 đã chứng minh trong vendor rằng middleware persistent
     * chạy với một response stub 200 trước khi hydrate, nên `abort(403)` từ
     * `hydrateCanAuthorizeAccess` thoát ra ngoài và một request cập nhật Livewire bị từ chối vẫn
     * là 403. Filament 5 không có seam để phủ đường đó (ghi nhận, không sửa lén ở Task 3). Nói ra
     * ở đây vì ba docblock của M4 từng hứa ngược lại, và vì toàn bộ cổng này là Livewire.
     *
     * # Nói đúng phương thức này bảo đảm được gì, vì bản đầu đã hứa nhiều hơn
     *
     * Bản đầu viết rằng nếu bỏ `instanceof ClientUser` thì "một nhân sự đang mở /admin đi thẳng
     * vào được màn hình khách hàng". **Câu ấy sai**, và rà soát Task 3 chứng minh bằng ba lần đột
     * biến sống sót: thay cả thân hàm bằng một phép kiểm tra "có ai đăng nhập không", bỏ lời gọi
     * `Gate`, và nới `instanceof ClientUser` thành `!== null` — cả ba đều để bộ test xanh. Lý do
     * là cấu hình, không phải may mắn: guard `client` giải về provider `client_users`
     * (`config/auth.php`), nên `Auth::guard('client')->user()` chỉ có thể là một `ClientUser` hoặc
     * `null`; nhân sự trong test bị từ chối vì guard `client` RỖNG, không vì phép so kiểu. Và
     * `MatterPolicy::viewAny` trả `true` vô điều kiện cho mọi `ClientUser`, nên lời gọi `Gate`
     * cũng chưa bao giờ từ chối được ai.
     *
     * Vậy hôm nay phương thức này bảo đảm **đúng một điều**: có một tài khoản khách hàng đang
     * đăng nhập trên guard `client`. Điều kiện ấy có thật và có việc để làm — hai panel dùng
     * chung cookie phiên (xem `ClientPortalScope`), nên một nhân sự đang mở /admin trên cùng
     * trình duyệt KHÔNG mở được màn hình này, và `MyMattersTest` dựng đúng phiên hai-guard ấy để
     * ghim câu trả lời đến từ guard nào.
     *
     * Hai vế còn lại được giữ như **phòng thủ theo tầng, không phải điều kiện đang có hiệu lực**,
     * và chúng được gọi đúng tên ấy ở đây chứ không được kể như một tầng đang chặn ai đó:
     * `instanceof` là thứ thu kiểu cho `Gate::forUser()` và là cái chốt nếu một ngày
     * provider của guard đổi; lời gọi `Gate` là chỗ một điều kiện tương lai của
     * `MatterPolicy::viewAny` (ví dụ `is_active`) tự động có hiệu lực ở đây.
     *
     * Hệ quả cho người rà soát tiếp theo, viết ra để không ai mất một vòng đo lại: hai lần đột
     * biến P11 và P12 của vòng sửa này (`return $clientUser !== null;` và bỏ lời gọi `Gate`)
     * **sống sót có chủ ý**. Chúng không phải một lỗ hổng trong bộ test; chúng là chính câu
     * docblock này, đo được.
     */
    public static function canAccess(): bool
    {
        $clientUser = Auth::guard('client')->user();

        return $clientUser instanceof ClientUser
            && Gate::forUser($clientUser)->allows('viewAny', Matter::class);
    }

    public static function getNavigationLabel(): string
    {
        return __('portal_matters.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('portal_matters.title');
    }

    public function getHeading(): string|Htmlable
    {
        return __('portal_matters.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('portal_matters.subheading');
    }

    /**
     * Một thẻ cho mỗi hồ sơ, đã rút về những giá trị khách được đọc.
     *
     * Không có một câu `where('client_id', ...)` nào ở đây, và đó là luật của kế hoạch M5:
     * điều kiện "của chính mình" nằm trong `Matter::applyClientPortalConstraints()` qua
     * `ClientPortalScope`, một chỗ duy nhất. Một màn hình tự viết lại điều kiện ấy là một chỗ
     * nữa có thể quên nó.
     *
     * Sắp xếp theo `last_client_update_at` giảm dần: hồ sơ vừa có tin lên trên cùng, và hồ sơ
     * chưa từng có tin (`null`) xuống cuối — MariaDB xếp `NULL` cuối cùng ở chiều giảm dần. `id`
     * là tiêu chí phụ để thứ tự luôn xác định khi hai hồ sơ cùng một dấu thời gian. Cột này là
     * thứ SPEC §4.6 gọi là "lần cuối công bố tiến độ cho khách", nên nó trả lời đúng câu §8.2
     * hỏi ("ngày cập nhật gần nhất") mà không tốn một truy vấn nào thêm; và nó KHÔNG rỗng một
     * cách bất ngờ, vì `TransitionMatterStage` chặn mọi lần công bố trên một hồ sơ chưa lên cổng
     * (`MatterNotPublishedToPortal`), nên mỗi dòng tiến độ đã công bố đều đi kèm một lần ghi cột
     * này.
     *
     * # Huy hiệu "còn giấy tờ cần nộp": cùng một tập dòng với thanh tiến độ
     *
     * Bản đầu đếm huy hiệu bằng hai `withCount` riêng chạy trên **mọi** dòng danh mục, trong khi
     * thanh tiến độ chỉ nói về tập `Y` mà {@see ChecklistProgress} chọn ra. Hai tập khác nhau
     * trên cùng một tấm thẻ, nên tấm thẻ nói được hai câu ngược nhau cùng lúc: với `Y` rỗng nó in
     * "hồ sơ này chưa có giấy tờ nào anh/chị cần nộp" VÀ một huy hiệu đỏ đòi một tờ giấy. Rà soát
     * Task 3 đo được đúng kết xuất ấy.
     *
     * Nên danh mục được nạp sẵn **một lần, kèm đúng bộ đếm tài liệu của Action**
     * ({@see ChecklistProgress::countClientSubmittedDocuments()}, một seam công khai có sẵn vì bảng
     * ở tab "Danh mục hồ sơ" cần cùng con số), và huy hiệu đếm bên TRONG `Y`
     * ({@see self::countedByProgress()}). Một tập dòng, hai câu chữ, không mâu thuẫn nào dựng
     * được nữa.
     *
     * Bên trong `Y`, luật đếm giữ nguyên chủ ý cũ và nó vẫn **không** phải `Y − X`:
     *
     *  - `pending_review` cũng là một đầu mục chưa xong, nhưng nó đang chờ VĂN PHÒNG, không chờ
     *    khách. Đếm nó vào huy hiệu đỏ là đòi khách làm một việc họ vừa làm xong.
     *  - một đầu mục KHÔNG bắt buộc, đang `missing`, chưa có tài liệu nào thì chưa bao giờ nằm
     *    trong danh sách giấy tờ khách phải nộp — đúng lý lẽ của đính chính SPEC §4.10 về mẫu số.
     *    Đếm nó là bịa thêm một việc cho khách. (Nó cũng không nằm trong `Y`, nên nay hai lý lẽ
     *    ấy là một.)
     *  - `rejected` thì tính **kể cả khi không bắt buộc**: khách đã nộp một thứ và văn phòng đã
     *    trả lại kèm lý do, nên đó là một việc đang chờ họ bất kể đầu mục ấy có bắt buộc hay không.
     *    Nói thẳng chỗ thay đổi so với bản đầu: một đầu mục không bắt buộc bị đánh dấu `rejected`
     *    mà **không còn một tài liệu nào ngoài nhóm D** thì nay KHÔNG nhắc nữa — nó nằm ngoài `Y`,
     *    và chính "khách đã nộp một thứ" là tiền đề mà một đầu mục như vậy không có.
     *
     * # `public` là bắt buộc, và nó có nghĩa là trình duyệt gọi được — nói ra chứ không bỏ lửng
     *
     * View Blade gọi `$this->getCards()`, và Blade được biên dịch ra ngoài phạm vi lớp, nên
     * phương thức này **không thể** là `protected`. Hệ quả của Livewire: mọi phương thức `public`
     * là một hành động gọi được từ trình duyệt, nên một khách gọi thẳng nó và nhận về mảng thẻ.
     * Đã cân nhắc và để nguyên: mảng ấy chỉ chứa dữ liệu của CHÍNH người gọi — vòng `Gate` từng
     * bản ghi ở {@see self::buildCards()} chạy y hệt trên đường đó — và từng giá trị trong đó đã
     * có mặt trên màn hình họ vừa xem. Không có gì đọc thêm được ở đây so với việc mở trang.
     *
     * @return array<int, array{id: int|string, url: string, code: string, title: string, stage_label: ?string, updated_at: ?string, submitted: int, total: int, percent: int, outstanding: int, tone: ?string, status: ?string}>
     */
    public function getCards(): array
    {
        return $this->cards ??= $this->buildCards();
    }

    /** @return array<int, array<string, mixed>> */
    private function buildCards(): array
    {
        $clientUser = Auth::guard('client')->user();

        if (! $clientUser instanceof ClientUser) {
            return [];
        }

        $progress = app(ChecklistProgress::class);

        return Matter::query()
            ->with([
                'matterType.stages',
                // Một truy vấn cố định, không một truy vấn nào thêm cho mỗi thẻ: các dòng danh
                // mục của cả trang về cùng lúc, mang sẵn bí danh đếm tài liệu mà `Y` được định
                // nghĩa bằng. Không có `withCount` thứ hai nào viết lại luật đếm ở đây.
                'checklistItems' => fn (Relation $items) => ChecklistProgress::countClientSubmittedDocuments($items->getQuery()),
                // Task 2, vòng sửa 1 (Important #2): nạp sẵn để `MatterPolicy::releasedToPortal()`
                // đọc miễn phí qua `relationLoaded('client')` thay vì một EXISTS cho mỗi thẻ — xem
                // docblock của hàm đó. Một truy vấn CỐ ĐỊNH nữa cho cả trang, không một truy vấn
                // nào thêm cho mỗi thẻ; `MyMattersTest` đo đúng độ dốc này.
                'client',
            ])
            ->orderByDesc('last_client_update_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Matter $matter): bool => Gate::forUser($clientUser)->allows('view', $matter))
            ->map(fn (Matter $matter): array => $this->toCard($matter, $progress))
            ->values()
            ->all();
    }

    /**
     * Dòng danh mục này có nằm trong tập `Y` của SPEC §4.10 hay không — tức thanh tiến độ có nói
     * về nó hay không.
     *
     * **Đây KHÔNG còn là một câu nói lại.** Bản trước viết lại điều kiện thành viên của `Y` ngay
     * tại đây, kèm một docblock giải thích vì sao nó phải được nói lại (`handle()` trả về hai số
     * nguyên chứ không trả về các dòng). Lý do đó đúng, nhưng lời giải đúng hơn là mở một seam ở
     * chính Action — và vòng sửa hợp nhất phải mở nó, vì khối "việc anh/chị cần làm" ở
     * `MatterProgress` cần đúng câu hỏi ấy và nếu không thì bản nói lại thứ HAI ra đời. Nay luật
     * ở một chỗ ({@see ChecklistProgress::countedInTotal()}) và ba màn hình đọc nó.
     *
     * Hàm này ở lại vì nó còn nói một điều mà Action không nói: bộ đếm tài liệu phải đã được nạp
     * trên dòng — đó là việc của `getCards()` ngay trên, và {@see self::toCard()} gọi nó trên tập
     * đã nạp.
     *
     * Ghim thì không đổi: `MyMattersTest` đo thẻ ĐẦY ĐỦ — huy hiệu VÀ thanh tiến độ, trong cùng
     * một khẳng định — ở bốn tình huống mà hai tập từng lệch nhau.
     */
    private static function countedByProgress(MatterChecklistItem $item): bool
    {
        return ChecklistProgress::countedInTotal($item);
    }

    /**
     * Một `Matter` thành một mảng giá trị khách đọc được — và KHÔNG có gì khác. Xem docblock lớp:
     * việc view không bao giờ cầm một model là thứ giữ cho `internal_note` và
     * `description_internal` không với tới được từ một màn hình cổng.
     *
     * Nhãn giai đoạn đọc qua `matterType?->stage()` chứ không qua `Matter::currentStage()`: hàm
     * kia gọi thẳng `$this->matterType->stage(...)` và sẽ là một lỗi 500 khi loại vụ việc đã bị
     * xoá mềm — trên màn hình đầu tiên của một khách hàng. Ở đây trường hợp đó rơi về câu
     * `stage_unknown`, và `client_label` vẫn là cột duy nhất được đọc: cột nội bộ
     * `matter_type_stages.label` không được đọc ở tệp này lẫn ở view, và
     * `MyMattersTest` ghim điều đó bằng một giai đoạn có hai nhãn khác hẳn nhau.
     *
     * @return array{id: int|string, url: string, code: string, title: string, stage_label: ?string, updated_at: ?string, submitted: int, total: int, percent: int, outstanding: int, tone: ?string, status: ?string}
     */
    private function toCard(Matter $matter, ChecklistProgress $progress): array
    {
        ['submitted' => $submitted, 'total' => $total] = $progress->handle($matter);

        // Đúng tập dòng mà `$total` đếm — xem docblock của {@see self::getCards()}.
        $wanted = $matter->checklistItems->filter(self::countedByProgress(...));

        $outstanding = $wanted
            ->filter(fn (MatterChecklistItem $item): bool => $item->status === ChecklistItemStatus::Rejected
                || ($item->status === ChecklistItemStatus::Missing && $item->is_required))
            ->count();

        $pendingReview = $wanted
            ->filter(fn (MatterChecklistItem $item): bool => $item->status === ChecklistItemStatus::PendingReview)
            ->count();

        $tone = match (true) {
            $outstanding > 0 => self::TONE_OUTSTANDING,
            $pendingReview > 0 => self::TONE_WAITING,
            $total > 0 => self::TONE_SETTLED,
            default => null,
        };

        return [
            // `id` và `url` là hai giá trị của chính hồ sơ, không phải một cột nội bộ: `id` là
            // thứ {@see self::mount()} chuyển hướng tới, `url` là lối vào trang tiến độ từ thẻ.
            'id' => $matter->getKey(),
            'url' => MatterProgress::getUrl(['record' => $matter->getKey()]),
            'code' => (string) $matter->code,
            'title' => (string) $matter->title,
            'stage_label' => $matter->matterType?->stage((string) $matter->stage)?->client_label,
            'updated_at' => $matter->last_client_update_at?->format('d/m/Y'),
            'submitted' => $submitted,
            'total' => $total,
            'percent' => $total > 0 ? (int) round($submitted / $total * 100) : 0,
            'outstanding' => $outstanding,
            'tone' => $tone,
            'status' => match ($tone) {
                self::TONE_OUTSTANDING => __('portal_matters.status.outstanding', ['count' => $outstanding]),
                self::TONE_WAITING => __('portal_matters.status.waiting_office'),
                self::TONE_SETTLED => __('portal_matters.status.settled'),
                default => null,
            },
        ];
    }

    /**
     * Biến màu Filament cho từng sắc thái. Ba màu có nghĩa cố định trên khắp cổng, và **mỗi màu
     * luôn đi kèm câu chữ của `status`** — màu không bao giờ là kênh thông tin duy nhất (tài liệu
     * bộ công cụ §4).
     *
     * **Một khác biệt so với tài liệu bộ công cụ, nói ra chứ không lặng lẽ.** §4 viết "xanh xong,
     * vàng chờ khách, đỏ quá hạn". SPEC §8.2 lại gọi tên màu cho đúng huy hiệu này: "huy hiệu đỏ
     * nếu còn giấy tờ cần nộp". SPEC là nguồn sự thật và nó nói về chính phần tử này, nên đỏ ở
     * đây là "còn giấy tờ anh/chị cần nộp". Chỗ trống mà đỏ để lại — "quá hạn" — không thuộc màn
     * hình này: mốc thời hạn là khối 6 của SPEC §8.3, tức trang chi tiết. Ba màu vì thế vẫn giữ
     * đúng một nghĩa mỗi màu **bên trong cổng khách hàng**: xanh xong, vàng đang chờ văn phòng,
     * đỏ đang chờ anh/chị.
     */
    public static function toneColour(?string $tone): ?string
    {
        return match ($tone) {
            self::TONE_OUTSTANDING => 'var(--danger-600)',
            self::TONE_WAITING => 'var(--warning-600)',
            self::TONE_SETTLED => 'var(--success-600)',
            default => null,
        };
    }
}
