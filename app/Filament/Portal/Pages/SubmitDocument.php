<?php

namespace App\Filament\Portal\Pages;

use App\Actions\Document\SubmitClientDocument;
use App\Enums\ChecklistItemStatus;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\UploadThrottle;
use DomainException;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\WithFileUploads;

/**
 * Khách hàng nộp giấy tờ từ điện thoại — SPEC §8.4, bốn bước dọc: **chọn đầu mục → chụp ảnh hoặc
 * chọn tệp → xem trước → gửi**, rồi "Đang chờ văn phòng kiểm tra".
 *
 * Đây là nửa "nộp được ảnh chụp" của tiêu chí nghiệm thu SPEC §14 mục 4, và là màn hình đầu tiên
 * trong hệ thống mà một người NGOÀI văn phòng ghi dữ liệu vào.
 *
 * # Trang này KHÔNG có nghiệp vụ nộp tệp, và đó là điều kiện để nó đúng
 *
 * Chín bước của SPEC §6.6 là của {@see SubmitClientDocument} (M4): danh sách trắng đuôi tệp, MIME
 * thật đọc bằng `finfo`, giới hạn dung lượng, quét virus, lưu qua medialibrary trên đĩa riêng,
 * chuỗi version khi nộp lại, `pending_review`, thông báo cho đội ngũ. Trang này **chỉ gọi Action
 * đó** và lo đúng ba việc mà một Action không lo được: hỏi `Gate` kèm ngữ cảnh, đổi mọi lời từ
 * chối thành thứ khách đọc được, và đếm số tệp mỗi giờ ở nơi bộ đếm thật sự chặn được.
 *
 * # Cổng quyền luôn đi KÈM đầu mục — và hỏi thiếu ngữ cảnh là tự mở lỗ
 *
 * `DocumentPolicy::create()` có hai nhánh cho khách. Nhánh **không** ngữ cảnh trả `true` **vô
 * điều kiện** cho mọi `ClientUser`: nó cố ý chỉ trả lời câu hỏi giao diện "khách nói chung có
 * nộp tệp được không". Nhánh **có** ngữ cảnh (`MatterChecklistItem`) mới là cái chặn thật. Nên ở
 * lớp này **không có một lời gọi `Gate::allows('create', Document::class)` trần nào**, và không
 * được có: một lời gọi như vậy là một cái cổng luôn mở, và nó trông y hệt một cái cổng.
 * {@see self::canAccess()} vì thế hỏi `viewAny` trên `Matter` — "người đang gõ cửa có phải một
 * tài khoản khách hàng không" — còn mọi quyết định thật đi qua {@see self::resolveItem()}.
 *
 * # Mọi lời từ chối là 404, kể cả lời từ chối bắn ra từ GIỮA Action
 *
 * SPEC §10.10: "không có quyền" và "không tồn tại" phải cùng một câu trả lời, và SPEC §14 mục 5
 * nói thẳng "kể cả sửa tham số URL". `SubmitClientDocument` đã gộp ba tình huống của nó vào một
 * `AuthorizationException` duy nhất mang đúng một câu chữ; nếu trang này đổi nó thành một thông
 * báo đỏ trong khi mọi lời từ chối khác của cổng là 404, thì **chính trang này** dựng lại cái
 * máy dò mà Action vừa dẹp — gửi một id bất kỳ, đọc HÌNH DẠNG câu trả lời, biết bản ghi có thật
 * hay không. Nên `AuthorizationException` từ Action đi ra bằng `abort(404)`, cùng hình dạng với
 * mọi lời từ chối khác ở đây. Một câu trả lời thứ tư là một lỗ hổng, không phải một tiện ích.
 *
 * `abort(404)` thẳng chứ không `abort(403)` rồi trông vào `AnswerDeniedPanelRequestsWithNotFound`:
 * middleware đó không phủ request cập nhật Livewire (Task 2 đã đọc trong vendor: middleware bền
 * chạy với một response stub 200 trước khi hydrate), và **toàn bộ màn hình này là Livewire** —
 * chọn đầu mục, chọn tệp và bấm gửi đều là request cập nhật.
 *
 * # HAI lớp trên các thuộc tính công khai: `#[Locked]`, VÀ giải lại ở mọi request
 *
 * Livewire chỉ mang thuộc tính công khai giữa hai request, và **khách sửa được chúng**: một
 * request cập nhật tự chế mang `updates: {"item": <id của người khác>}` ghi thẳng vào thuộc tính
 * mà không hề gọi `chooseItem()`. Đây không phải một khả năng lý thuyết — rà soát Task 4 vừa đo
 * đúng hình dạng đó trên `MatterProgress::$record` qua HTTP thật: một `record` giả mạo trả về
 * 200 kèm mảnh trang của hồ sơ khác (vẫn của cùng khách hàng, nên cách ly không thủng, nhưng
 * thuộc tính thì client ghi được).
 *
 * Nên mọi thuộc tính mà **trình duyệt không có việc gì phải đặt** đều `#[Locked]`: `$record`
 * (tham số route), `$item` (chỉ `chooseItem()` đặt, từ phía máy chủ), `$submitted` và
 * `$submittedItemName` (kết quả của một lần gửi). Thứ duy nhất trình duyệt được ghi là `$data`
 * — trạng thái của ô chọn tệp, thứ nó buộc phải ghi.
 *
 * **`#[Locked]` không thay được lần giải lại, và đây là lý do phải nói câu đó ra.** Khoá chặn
 * một giá trị GIẢ MẠO; nó không nói gì về một giá trị **thật nhưng đã hết hợp lệ** — hồ sơ bị
 * rút khỏi cổng, đầu mục bị xoá, tài khoản bị khoá, tất cả đều xảy ra được GIỮA lúc chọn và lúc
 * bấm gửi. Nên {@see self::resolveItem()} vẫn đọc lại bản ghi qua truy vấn đã có scope và hỏi
 * lại `Gate` ở mọi lần dùng, và không lời gọi nào ở đây tin vào giá trị đã lưu. Ai gỡ một trong
 * hai lớp vì "lớp kia đã lo rồi" đều gỡ nhầm.
 *
 * Hệ quả về HÌNH DẠNG câu trả lời, ghi ra vì nó liên quan tới SPEC §10.10: một lần ghi vào thuộc
 * tính khoá đi ra bằng exception riêng của Livewire chứ không bằng 404. Điều đó **không** dựng
 * lại một máy dò sự tồn tại — câu trả lời ấy giống hệt nhau cho mọi giá trị, kể cả một id hoàn
 * toàn bịa đặt, nên nó không nói gì về bản ghi nào cả. Nó chỉ nói rằng trình duyệt vừa làm một
 * việc trình duyệt không bao giờ làm.
 *
 * # Giới hạn 20 tệp/giờ/tài khoản đứng ở HAI cửa, và chỗ quan trọng hơn không phải nút Gửi
 *
 * SPEC §10.3. Rà soát M4 đã đo: dưới Filament, **các byte đã nằm trên đĩa TRƯỚC khi bất kỳ form
 * action nào chạy** — `_startUpload` của Livewire cấp một URL đã ký và trình duyệt tải tệp lên
 * ngay khi người dùng chọn nó. Một giới hạn chỉ đứng ở nút Gửi vì thế không bảo vệ thứ cần bảo
 * vệ (đĩa, và thời gian của clamd). Nên nó đứng ở cả hai:
 *
 *  - {@see self::_startUpload()} — cửa của BYTE. Chặn ở đây thì không có URL đã ký nào được cấp
 *    và không byte nào rời khỏi điện thoại.
 *  - {@see self::submit()} — cửa của BẢN GHI. Một client tự chế dùng lại được một URL đã ký cho
 *    nhiều lần gửi, và cái hại của việc nộp lại dồn dập nằm ở `documents` và ở thông báo gọi đội
 *    ngũ vào xem, không chỉ ở đĩa.
 *
 * Hai bộ đếm riêng, cùng một mức, cùng khoá theo **tài khoản** (SPEC §10.3 viết "theo tài khoản",
 * không phải theo IP — xem {@see self::fileLimiterKey()}). Một lượt nộp thật tốn đúng một đơn vị
 * của mỗi bên, nên mức thật vẫn là 20 lượt/giờ; gộp hai cửa vào một bộ đếm thì mỗi lượt tốn hai
 * đơn vị và mức thật tụt xuống 10.
 *
 * **Hai cửa đếm hai thứ khác nhau (final review C-M10).** Cửa của BYTE tự đếm số LẦN CHỌN tệp —
 * một lần bị từ chối ở đó vẫn đã tốn đĩa. Cửa của BẢN GHI chỉ đếm những tệp Action ĐÃ NHẬN: một
 * lần bấm Gửi bị từ chối (sai định dạng, `FileGuard`/`VirusScanner` chặn, quên chọn tệp) không
 * đưa thêm byte nào vào máy chủ và không tạo bản ghi nào, nên không tốn suất — xem
 * {@see self::submit()}.
 *
 * # CỬA THỨ BA, ở chính endpoint: `config/livewire.php`
 *
 * Hai cửa trên là hai cửa của MÀN HÌNH NÀY, và một mình chúng không đủ — câu này từng là một
 * đoạn "phạm vi, nói cho đủ" ở đây, và rà soát đã đo ra rằng nó mô tả một cái lỗ chứ không phải
 * một giới hạn. Bộ đếm ở `_startUpload` chặn việc CẤP một URL đã ký; nó không chặn việc DÙNG
 * một URL đã cấp. Đo được: **một URL đã ký nhận trọn 25/25 lần POST, tất cả 200, 50 tệp tạm
 * nằm trên đĩa, trong khi bộ đếm của trang đứng yên ở 0** — và `VirusScanner` không được hỏi
 * một lần nào về đống byte ấy. Middleware mặc định của endpoint là `throttle:60,1`, tức 3600
 * tệp/giờ: gấp 180 lần mức SPEC §10.3 cho phép.
 *
 * Nên cửa thứ ba đứng ở chính endpoint, bằng `temporary_file_upload.middleware` trong
 * `config/livewire.php`. Bản đầu là `throttle:20,60`, khoá theo `$request->user()` của guard mặc
 * định (`web`), thứ trống rỗng trên cổng khách — tức khoá theo ĐỊA CHỈ với khách, còn SPEC §10.3
 * đòi khoá theo TÀI KHOẢN; M8 Task 3 thay nó bằng `App\Http\Middleware\ThrottleUploadedFiles`, khoá
 * theo TÀI KHOẢN và đếm TỆP chứ không đếm request (xem `App\Support\UploadThrottle`). Hai cửa của
 * trang KHÔNG vì thế mà thừa. Ba cửa, ba thứ được bảo vệ: byte không rời khỏi điện thoại, byte
 * không rơi xuống đĩa, bản ghi không sinh ra.
 *
 * # Trần dung lượng: MỘT con số, ba chỗ đọc nó
 *
 * SPEC §6.6 bước 4 và §8.4 nói 20 MB, cấu hình qua `UPLOAD_MAX_MB`. Ba chỗ phải nói cùng con số
 * ấy, và trước lượt rà soát này chỉ có hai:
 *
 *  - dòng hướng dẫn dưới ô chọn tệp (`portal_submit.steps.file.help`) và luật `maxSize()` của ô
 *    — cả hai đọc {@see self::maxMegabytes()};
 *  - cổng của trang ở {@see self::_startUpload()} — cùng lời gọi đó;
 *  - **luật của endpoint tải lên** (`temporary_file_upload.rules`), thứ trước đây để trống nên
 *    `FileUploadConfiguration::rules()` trả `max:12288` — 12 MB.
 *
 * Hệ quả đo được của cái lệch ấy: 11 MB và 12 MB trả 200, còn 13 MB và 19 MB trả 422 kèm một
 * câu của framework — "data.file không được lớn hơn 12288 kilobyte" — in thẳng dưới dòng chữ
 * hứa 20 MB. Cả dải 13–20 MB hỏng, tức đúng cỡ một tấm sổ đỏ chụp bằng điện thoại đời nay
 * (~15 MB), và SPEC §14 mục 4 hỏng theo. `config/livewire.php` nay đọc cùng `UPLOAD_MAX_MB`, và
 * `tests/Feature/Config/LivewireUploadConfigTest.php` ghim ba chỗ ấy vào một con số.
 *
 * Luật `maxSize()` của ô chọn tệp vì vậy **nay với tới được**: trần của endpoint không còn thấp
 * hơn nó. Phần chạy trong trình duyệt (FilePond đọc nó làm `maxFileSize`) vẫn là phần tiết kiệm
 * cho một khách đang dùng 3G, và phần chạy ở máy chủ nay là một lớp thật.
 *
 * # Mọi lời từ chối của endpoint cũng phải bằng tiếng Việt — {@see self::_uploadErrored()}
 *
 * Nâng trần không đủ, và nửa này lẽ ra làm được từ đầu: `_uploadErrored()` là một phương thức
 * của chính component, và câu chữ nằm ở tệp ngôn ngữ của chính task này. Bản gốc của Livewire
 * lấy thân JSON của lời từ chối rồi ném thẳng ra, nên MỌI lời từ chối của endpoint — quá trần
 * thật, chạm throttle, chữ ký hết hạn, sóng đứt — đi ra bằng một câu của framework có tên thuộc
 * tính trong đó. Lớp này ghi đè nó để mọi đường ấy đổ về đúng một lối ra của trang.
 *
 * # Cái trang này cố ý KHÔNG làm
 *
 *  - **Không nhắc `internal_note` ở bất kỳ đâu**, trong lớp này lẫn trong view. Trait
 *    `HidesInternalAttributesFromPortal` chỉ chặn ở tầng serialize (Task 2), nên `$x->internal_note`
 *    trong Blade trả về chuỗi THẬT. Điều SPEC §11 hứa đúng vì không ai gọi tên cột đó.
 *  - **Không tự viết một câu từ chối nào cho tầng tệp.** `FileGuard` và `VirusScanner` đã có bộ
 *    câu riêng ở `lang/vi/documents.php`, mỗi lý do một câu nói rõ việc cần làm tiếp theo; trang
 *    này hiển thị thẳng chúng.
 *  - **Không chặn theo trạng thái đầu mục.** SPEC §8.3 chỉ vẽ nút nộp ở `missing` và `rejected`
 *    — đó là luật của KHỐI 4 trang chi tiết và nó được tôn trọng ở đó. Màn hình này nhận cả
 *    `accepted` và `pending_review`, vì nó là **đường sửa sai duy nhất** của một khách vừa nhận
 *    ra mình gửi nhầm trang: SPEC không có thao tác "bỏ duyệt", nên chặn lại là bắt họ gọi điện.
 *    Lý lẽ đầy đủ ở docblock {@see SubmitClientDocument}.
 */
class SubmitDocument extends Page
{
    /**
     * SPEC §10.3. **Một con số, BA cửa** — hai cửa của trang (xem docblock lớp) và cửa của chính
     * endpoint tải lên. Nó đọc thẳng {@see UploadThrottle::FILES_PER_HOUR} chứ không viết lại số
     * 20 ở đây: hai bản của cùng một luật là cách chắc chắn nhất để một ngày chúng lệch nhau, và
     * mốc này đã tìm thấy đúng hình dạng ấy hai lần ở chỗ khác.
     */
    public const FILES_PER_HOUR = UploadThrottle::FILES_PER_HOUR;

    private const LIMIT_WINDOW_SECONDS = 3600;

    protected string $view = 'filament.portal.pages.submit-document';

    /**
     * Đường dẫn tiếng Việt không dấu, cùng lý lẽ với `MatterProgress`: khách thỉnh thoảng đọc URL
     * ra qua điện thoại cho trợ lý. `/portal/nop-giay-to/12` nói được thành lời.
     */
    protected static ?string $slug = 'nop-giay-to';

    /**
     * Không vào thanh điều hướng: trang này luôn nói về MỘT hồ sơ. Lối vào là khối "Hồ sơ giấy
     * tờ" của trang chi tiết (SPEC §8.3 mục 4) — xem {@see self::urlForItem()}.
     */
    protected static bool $shouldRegisterNavigation = false;

    /** Tham số trên URL: khoá của hồ sơ. Không model binding — xem {@see self::resolveMatter()}. */
    #[Locked]
    public int|string $record;

    /** Đầu mục đang chọn. Chỉ {@see self::chooseItem()} đặt được — xem docblock lớp. */
    #[Locked]
    public int|string|null $item = null;

    /**
     * Trạng thái của ô chọn tệp — thuộc tính DUY NHẤT ở đây mà trình duyệt được ghi, vì nó là
     * thứ trình duyệt buộc phải ghi.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Đã gửi xong trong request trước đó — quyết định khối "Đang chờ văn phòng kiểm tra". */
    #[Locked]
    public bool $submitted = false;

    /** Tên đầu mục vừa gửi, để câu xác nhận gọi đúng tên tờ giấy khách vừa gửi. */
    #[Locked]
    public ?string $submittedItemName = null;

    private ?Matter $resolvedMatter = null;

    private ?MatterChecklistItem $resolvedItem = null;

    /** @var Collection<int, MatterChecklistItem>|null */
    private ?Collection $resolvedChoices = null;

    /**
     * `{record}` nối vào đường dẫn ở đây chứ không ở `$slug`: `getRelativeRouteName()` dựng tên
     * route từ chính `getSlug()`, nên nhét tham số vào slug sẽ sinh ra một tên route mang dấu
     * ngoặc nhọn. Cùng hình dạng `MatterProgress` đã dùng.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{record}';
    }

    /**
     * Lối vào từ khối 4 của SPEC §8.3, **định nghĩa duy nhất**: trang này sở hữu hình dạng URL
     * của chính nó, nên `MatterProgress` gọi hàm này thay vì ghép một đường dẫn bằng tay.
     *
     * `panel: 'portal'` viết thẳng: hàm này cũng được gọi từ test và từ những chỗ không có panel
     * hiện hành, nơi mặc định sẽ phân giải nhầm sang panel nội bộ.
     */
    public static function urlForItem(MatterChecklistItem $item): string
    {
        return static::getUrl([
            'record' => $item->matter_id,
            'item' => $item->getKey(),
        ], panel: 'portal');
    }

    /**
     * Cổng TĨNH của trang. Nó không nhìn thấy `{record}` (phương thức là `static`) nên nó không
     * bao giờ là câu trả lời cho "hồ sơ này có phải của anh/chị không" — câu đó ở
     * {@see self::resolveMatter()}.
     *
     * Hỏi `viewAny` trên `Matter` chứ **không** hỏi `create` trên `Document`: nhánh không ngữ
     * cảnh của `DocumentPolicy::create()` trả `true` vô điều kiện cho mọi `ClientUser`, nên một
     * lời gọi như vậy là một cái cổng luôn mở trông giống một cái cổng (xem docblock lớp).
     *
     * Điều kiện `instanceof ClientUser` không thừa bên cạnh lời gọi `Gate`: `MatterPolicy::viewAny`
     * cũng trả `true` cho một nhân sự có quyền `matter.viewAny`, và hai panel dùng chung cookie
     * phiên — không có nó thì một nhân sự đang mở /admin đi thẳng vào được màn hình của khách.
     */
    public static function canAccess(): bool
    {
        $clientUser = Auth::guard('client')->user();

        return $clientUser instanceof ClientUser
            && Gate::forUser($clientUser)->allows('viewAny', Matter::class);
    }

    /**
     * `$item` nhận từ tham số mount (test lái thẳng component) hoặc từ chuỗi truy vấn — trên một
     * request thật Livewire không có gì để truyền vào đó. Cùng thành ngữ `MyMatters::mount()`.
     */
    public function mount(int|string $record, int|string|null $item = null): void
    {
        $this->record = $record;

        // Giải NGAY ở mount: một lời từ chối phải là 404 của chính request tải trang, không phải
        // một trang dựng xong rồi vỡ ở giữa lúc render.
        $this->matter();

        $chosen = $item ?? request()->query('item');

        if (filled($chosen)) {
            $this->chooseItem($chosen);
        }

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('portal_submit.title', ['code' => $this->matter()->code]);
    }

    public function getHeading(): string|Htmlable
    {
        return __('portal_submit.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('portal_submit.subheading', ['title' => $this->matter()->title]);
    }

    public function matter(): Matter
    {
        return $this->resolvedMatter ??= $this->resolveMatter();
    }

    /** Lối quay lại hồ sơ — một màn hình nộp tệp không có đường về là một ngõ cụt. */
    public function matterUrl(): string
    {
        return MatterProgress::getUrl(['record' => $this->matter()->getKey()], panel: 'portal');
    }

    // -------------------------------------------------------------------------------------
    // Bước 1 — Chọn đầu mục
    // -------------------------------------------------------------------------------------

    /**
     * Những đầu mục khách nộp được vào, **việc còn phải làm đứng trước**: `missing` và `rejected`
     * lên đầu vì đó là thứ khách mở màn hình này để làm; phần còn lại vẫn có mặt vì màn hình này
     * là đường sửa sai duy nhất (xem docblock lớp).
     *
     * **Lần hỏi `Gate` ở đây SỐNG SÓT một lần đột biến, và câu đó phải được nói ra** — cùng hình
     * dạng đã ghi ở `MatterProgress::checklistItems()`, đo lại ở đây chứ không suy ra. Ba lần chạy:
     *
     *  - **xoá riêng lần lọc `Gate`**: toàn bộ tệp test XANH. Quan hệ `checklistItems()` đã giới hạn theo
     *    đúng hồ sơ mà {@see self::resolveMatter()} vừa gác, nên không đầu mục của người khác nào
     *    vào tới tập hợp này để mà bị từ chối.
     *  - **thay quan hệ bằng `MatterChecklistItem::query()` mà GIỮ lần lọc**: vẫn XANH — tức
     *    chính `Gate` là thứ đang giữ trong ngữ cảnh đó.
     *  - **bỏ cả hai**: test "keeps another clients item out of the chooser when the checklist
     *    scope forgets its rule" ĐỎ, và đầu mục của khách khác lên thẳng danh sách chọn.
     *
     * Hai thứ ở đây vì vậy không phải một thứ nói hai lần: quan hệ giữ PHẠM VI, `Gate` giữ QUYỀN,
     * và mỗi cái đỡ được lần quên của cái kia.
     *
     * # Vụ việc được GẮN SẴN vào từng đầu mục trước khi hỏi `Gate`
     *
     * `DocumentPolicy::create()` đọc `$item->matter` — một quan hệ LƯỜI. Không gắn sẵn thì mỗi
     * đầu mục kéo theo một truy vấn nạp lại đúng vụ việc mà {@see self::resolveMatter()} vừa
     * đọc và vừa gác xong ở đầu request. Đo được, 10 đầu mục thêm vào: **30 truy vấn thêm trước
     * khi sửa, 20 sau** — tức 3 xuống 2 cho mỗi đầu mục, trên một màn hình mà một hồ sơ đất đai
     * hai mươi đầu mục là chuyện thường.
     *
     * Gắn sẵn KHÔNG nới một điều kiện nào: đây đúng là đối tượng mà `MatterPolicy::view` vừa
     * cho qua, và hai tầng còn lại (`checklistItems()` và `Gate`) không đọc gì từ nó.
     *
     * **Hai truy vấn còn lại được nói ra chứ không giấu đi**, vì một trong hai gộp được và chỗ
     * gộp không nằm ở đây: một `EXISTS` trên `matter_checklist_items` — câu hỏi portal của RIÊNG
     * đầu mục này, tức đúng tầng mà lần hỏi `Gate` từng dòng tồn tại để hỏi — và một `EXISTS`
     * trên `matters` (`ChecksPortalVisibility::visibleToPortal()`, không đổi từ M5), thứ
     * `MatterPolicy::view` chạy lại **y hệt nhau** ở mọi vòng lặp. Cái thứ hai vẫn cần một đường
     * trong bộ nhớ ở `visibleToPortal()`; đó vẫn là tệp task này không sở hữu, nên nó vẫn được
     * báo lại chứ không sửa lén.
     *
     * **Điều Task 2, vòng sửa 1 (Important #2) đổi ở đây KHÔNG PHẢI xoá một trong hai truy vấn
     * trên** — cả hai còn nguyên, ngân sách vẫn **2**, không giảm xuống 1. Điều nó đổi là NGĂN
     * một truy vấn thứ BA mọc lên: `releasedToPortal()` (cùng `MatterPolicy::view`, nhưng khác
     * `visibleToPortal()`) giờ hỏi thêm "khách hàng chưa xoá mềm", và nếu hỏi bằng một truy vấn
     * mới cho mỗi đầu mục thì ngân sách sẽ thành 3 (đo được: bỏ `->with('client')` ở
     * {@see self::resolveMatter()} hoặc bỏ nhánh `relationLoaded()` ở `releasedToPortal()` thì
     * test ngân sách của tệp này đỏ). `{@see self::resolveMatter()}` giờ `->with('client')` một
     * lần cho `$matter`, và vì `$matter` này được gắn sẵn vào MỌI đầu mục ở trên,
     * `releasedToPortal()` đọc `relationLoaded('client')` miễn phí cho từng đầu mục — ngân sách
     * vì vậy giữ nguyên **2 mỗi đầu mục** dù có thêm một điều kiện mới. Ngân sách ấy có test ghim.
     *
     * @return Collection<int, MatterChecklistItem>
     */
    public function choosableItems(): Collection
    {
        if ($this->resolvedChoices !== null) {
            return $this->resolvedChoices;
        }

        $viewer = $this->viewer();
        $matter = $this->matter();

        return $this->resolvedChoices = $this->matter()->checklistItems()->get()
            ->each(fn (MatterChecklistItem $item) => $item->setRelation('matter', $matter))
            ->filter(fn (MatterChecklistItem $item): bool => Gate::forUser($viewer)
                ->allows('create', [Document::class, $item]))
            ->sortBy(fn (MatterChecklistItem $item): int => $this->isOutstanding($item) ? 0 : 1)
            ->values();
    }

    /** Đang chờ ở KHÁCH (SPEC §8.3 mục 4): chưa nộp, hoặc đã nộp nhưng văn phòng cần bản khác. */
    public function isOutstanding(MatterChecklistItem $item): bool
    {
        return in_array(
            $item->status,
            [ChecklistItemStatus::Missing, ChecklistItemStatus::Rejected],
            true,
        );
    }

    /**
     * Nhãn trạng thái mượn thẳng bản viết cho khách của trang chi tiết
     * (`portal_progress.checklist.status.*`). Hai màn hình cạnh nhau gọi cùng một thứ bằng hai
     * cái tên là cách chắc chắn nhất làm khách tưởng đó là hai thứ.
     */
    public function statusLabel(MatterChecklistItem $item): string
    {
        return __('portal_progress.checklist.status.'.$item->status->value);
    }

    /**
     * Chọn một đầu mục. **Giá trị đến từ người dùng**, nên nó được giải và gác ngay tại đây chứ
     * không chỉ khi bấm gửi: một id không dùng được phải dừng lại ở cú bấm, không phải sau khi
     * khách đã chụp ảnh xong.
     */
    public function chooseItem(int|string $key): void
    {
        // Bản ghi vừa giải thay luôn bản ghi đã nhớ, KHÔNG chỉ đặt lại `$this->item`. Livewire
        // mang tới 50 lời gọi trong MỘT request (`livewire.payload.max_calls`), nên hai lần
        // `chooseItem()` rồi một lần `submit()` nằm chung một request là một hình dạng có thật —
        // và `$resolvedItem` còn giữ đầu mục CŨ thì `submit()` gửi tệp vào đúng đầu mục cũ đó.
        // Không phải một đường vượt tuyến (cả hai id đều vừa qua `resolveItem()`), mà là đúng
        // cái bẫy "gửi đúng tệp vào nhầm chỗ" ở ngay dòng dưới, chỉ ở chiều ngược lại.
        $this->resolvedItem = $this->resolveItem($key);
        $this->item = $this->resolvedItem->getKey();

        // Tệp đã chọn thuộc về đầu mục CŨ. Giữ lại nó qua một lần đổi đầu mục là dọn sẵn đúng
        // cái bẫy mà bước "xem trước" của SPEC §8.4 tồn tại để tránh: gửi đúng tệp vào nhầm chỗ.
        $this->form->fill();

        $this->submitted = false;
        $this->submittedItemName = null;
    }

    /** Bỏ chọn để quay lại bước 1. Không giải gì cả — không có giá trị nào đi ra từ đây. */
    public function clearItem(): void
    {
        $this->item = null;
        $this->resolvedItem = null;
        $this->form->fill();
        $this->submitted = false;
        $this->submittedItemName = null;
    }

    /** Đầu mục đang chọn, đã giải lại và đã gác — `null` khi khách chưa chọn gì. */
    public function checklistItem(): ?MatterChecklistItem
    {
        if (blank($this->item)) {
            return null;
        }

        return $this->resolvedItem ??= $this->resolveItem($this->item);
    }

    // -------------------------------------------------------------------------------------
    // Bước 2 — Chụp ảnh hoặc chọn tệp
    // -------------------------------------------------------------------------------------

    /**
     * Một ô duy nhất, và nó **không** tự lưu tệp đi đâu cả.
     *
     * `storeFiles(false)`: trạng thái của ô ở lại là một `TemporaryUploadedFile` trên đĩa tạm
     * của Livewire, và `BaseFileUpload::getUploadedFiles()` trả thẳng `null` cho mọi
     * `TemporaryUploadedFile` — nó không bao giờ chạm tới `getDisk()`. Nên đĩa `private` (kho hồ
     * sơ thật, SPEC §10.4) không hề bị đụng cho tới khi Action gọi `addMedia()`, sau khi
     * `FileGuard` và `VirusScanner` đã cho qua. Một ô tự lưu sẽ đặt tệp vào kho trước khi có ai
     * kiểm tra nó. `SpatieMediaLibraryFileUpload` thì càng không: nó sinh URL xem trước TRÊN ĐĨA
     * của collection, tức đúng đĩa riêng ấy.
     *
     * **`capture` — SPEC §8.4 "hỗ trợ chụp ảnh trực tiếp trên điện thoại".** Thuộc tính phải nằm
     * trên chính thẻ `<input>`: FilePond đọc thuộc tính của phần tử nguồn và ánh xạ `capture`
     * thành `captureMethod` của nó (đo trong `vendor/filament/forms/dist/components/file-upload.js`),
     * nên một tuỳ chọn JavaScript viết ở chỗ khác sẽ không tới được nó. Nói cho đúng phạm vi:
     * theo đặc tả HTML thuộc tính này là một **gợi ý** — máy mở thẳng máy ảnh, máy mở bộ chọn
     * tệp kèm nút máy ảnh, và với một danh sách `accept` gồm cả PDF lẫn tệp Word thì phần lớn
     * trình duyệt chọn vế thứ hai. Câu hướng dẫn ở `portal_submit.steps.file.help` vì thế nói ra
     * cả hai đường, và một lần đi bộ trên điện thoại thật (Task 7) là chỗ duy nhất trả lời được
     * máy nào làm gì.
     *
     * **`acceptedFileTypes()` và `maxSize()` KHÔNG phải cổng an ninh**, và câu này quan trọng vì
     * bài học M4 nằm đúng ở đây: hai luật ấy đọc `Content-Type` do client gửi lên, thứ SPEC §6.6
     * bước 3 nói thẳng là không được tin. Cổng thật là `FileGuard`, thứ đọc MIME bằng `finfo`
     * trên nội dung tệp. Chúng vẫn ở đây vì hai lý do khác: `accept` là thứ làm `capture` có
     * nghĩa trên điện thoại, và một lần từ chối ngay tại ô rẻ hơn nhiều cho một khách đang dùng
     * 3G so với một lần tải lên 20 MB rồi mới biết.
     *
     * Hệ quả phải nói ra: vì chúng chạy TRƯỚC, `FileGuard` không bao giờ được hỏi về một tệp mà
     * chúng đã loại — nên câu chữ của chúng cũng phải là câu viết cho khách, không phải câu mặc
     * định của framework. Đó là toàn bộ việc của `validationMessages()` bên dưới, và
     * `too_large` mượn thẳng câu của `FileGuard` để khách đọc **cùng một câu** dù lời từ chối
     * đến từ cửa nào.
     *
     * Nói cho đúng phần nào của hai luật ấy đang chạy ở đâu: luật `mimetypes` của
     * `acceptedFileTypes()` chạy ở tầng máy chủ và có test đứng sau. Luật `max` của `maxSize()`
     * chạy ở HAI nơi — trong trình duyệt (FilePond đọc nó làm `maxFileSize` và từ chối trước khi
     * tải lên, đó là chỗ "rẻ hơn cho một khách đang dùng 3G" nói đến), và ở tầng máy chủ khi
     * bấm Gửi. Trước lượt rà soát này vế thứ hai **không bao giờ được hỏi**, vì trần 12 MB của
     * endpoint Livewire thấp hơn nó và đứng trước nó; nay `config/livewire.php` đọc cùng một con
     * số nên nó với tới được. Cái cổng sớm nhất cho kích thước vẫn là {@see self::_startUpload()},
     * nơi câu của SPEC §8.4 tới được khách trước khi một byte nào rời khỏi điện thoại.
     */
    public function form(Schema $schema): Schema
    {
        $max = static::maxMegabytes();

        return $schema
            ->components([
                // R10 (M6.5 Task 17, checklist-03): `->multiple()` — một lần nộp gồm nhiều tệp
                // (CCCD hai mặt, sổ đỏ bốn trang…), tất cả cùng một version ở tầng Action
                // ({@see SubmitClientDocument}). `maxSize()` vẫn áp CHO TỪNG tệp (Filament, không
                // phải tổng cả lô) — đúng câu hướng dẫn "mỗi tệp tối đa :max MB".
                //
                // `maxFiles(self::FILES_PER_HOUR)` (vòng sửa 1, Minor): trần một lô KHÔNG THỂ quá
                // 20 dù sao — SPEC §10.3 đã chặn 20 tệp/giờ/tài khoản ở `guardRate()`, và một lô
                // 21 tệp trở lên gõ vào `submit()` bị chặn SỚM HƠN, bởi một cổng RIÊNG (đọc dưới)
                // — không phải bởi luật `max:count` mà dòng này thêm vào. Vẫn thêm dòng này vì nó
                // cho FilePond một trần THẬT ở TRÌNH DUYỆT (khoá nút "chọn thêm tệp" tại 20, không
                // để khách chọn 30 tệp rồi mới biết bị từ chối); phần server-side của chính luật
                // `max:count` mà nó sinh ra vì vậy không bao giờ chạy tới trong `submit()` — xem
                // lý do KHÔNG gán `validationMessages(['max' => ...])` một câu riêng cho nó ở
                // đoạn dưới.
                //
                // **Vì sao KHÔNG có một câu tiếng Việt riêng cho `max:count` qua
                // `validationMessages()`.** Field này đã có `'max' => …too_large…` cho `maxSize()`
                // (kích thước MỖI tệp) — và Filament dùng CHUNG một khoá `'max'` cho MỌI luật tên
                // `max`, dù nó là luật kích thước (tệp) hay luật số lượng (mảng): cả hai đọc từ
                // đúng một mảng `$this->getValidationMessages()`
                // (`vendor/filament/forms/src/Components/BaseFileUpload.php`, so `getValidationRules()`
                // với `Concerns/CanBeValidated::dehydrateValidationMessages()`). Thêm một câu
                // "quá nhiều tệp" vào đúng khoá `'max'` sẽ ĐÈ LÊN câu "tệp quá :max MB" hiện có
                // (hoặc ngược lại) — không cách nào tách hai câu qua API công khai của field này.
                // Cổng thật cho số lượng vì thế KHÔNG nằm ở field, mà ở {@see self::submit()},
                // nơi ném `ValidationException` bằng tay qua `failOnFile()` — đúng cơ chế
                // `guardRate()`/`_startUpload()` ở dưới đã dùng, và không đi qua mảng
                // `validationMessages()` chung nên không đụng luật `max` của kích thước.
                FileUpload::make('file')
                    ->label(__('portal_submit.steps.file.label'))
                    ->helperText(__('portal_submit.steps.file.help', ['max' => $max]))
                    ->storeFiles(false)
                    ->multiple()
                    ->maxFiles(self::FILES_PER_HOUR)
                    ->acceptedFileTypes(static::acceptedMimeTypes())
                    ->maxSize($max * 1024)
                    ->required()
                    ->extraInputAttributes([
                        'capture' => 'environment',
                        'style' => 'min-height: 44px',
                    ])
                    ->validationMessages([
                        'required' => __('portal_submit.errors.file_required'),
                        'mimetypes' => __('portal_submit.errors.file_type'),
                        'max' => __('documents.file_guard.too_large', ['max' => $max]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Danh sách MIME cho thuộc tính `accept` của trình duyệt VÀ cho luật `mimetypes` mà
     * `acceptedFileTypes()` gắn vào chính ô này — **không phải một ranh giới an ninh**, nhưng
     * KHÔNG phải vì lý do bản trước của đoạn này viết (vòng sửa 1, finding I3: đoạn đó sai sự
     * thật, sửa lại ở đây).
     *
     * Thuộc tính `accept` trên `<input>` thì đúng là một gợi ý phía trình duyệt, và trình duyệt
     * đoán Content-Type từ đuôi tệp — cái đó không tin được, và SPEC §6.6 bước 3 nói đúng về NÓ.
     * Nhưng luật `mimetypes` của Livewire/Filament thì KHÔNG chạy trên Content-Type do client
     * khai. Ô này dùng `->multiple()` với `->storeFiles(false)`, nên đối tượng được validate là
     * một `Livewire\Features\SupportFileUploads\TemporaryUploadedFile` — và
     * `TemporaryUploadedFile::getMimeType()` (đọc mã nguồn `vendor/livewire/livewire`) gọi
     * `detectMimeTypeFromContents()`, mở NỘI DUNG tệp đã nằm trên đĩa tạm và chạy qua
     * `FinfoMimeTypeDetector` — đúng cơ chế `finfo` mà `FileGuard` cũng dùng. Giá trị client khai
     * chỉ được đọc khi `app()->runningUnitTests()` là `true` (từ `metaFileData()` mà
     * `UploadedFile::fake()->create(..., mimeType: ...)` của bộ test ghi vào) — một nhánh CHỈ
     * chạy dưới Pest, không bao giờ chạy trên máy chủ thật.
     *
     * Vậy vì sao danh sách này vẫn KHÔNG phải ranh giới an ninh, nếu nó đã đọc byte thật? Vì nó
     * dừng ở ĐÚNG MỘT byte-signature chung cho cả họ định dạng (`libmagic` không phân biệt nổi
     * một `.docx` thật với một `.zip` bất kỳ — cả hai đều là "một gói ZIP", đúng những gì
     * `finfo` báo cho CẢ HAI), còn `FileGuard::ALLOWED` đi xa hơn: nó ánh xạ từng ĐUÔI tới đúng
     * tập MIME hợp lệ CHO ĐUÔI đó, và với riêng `docx`/`xlsx`/`doc`/`xls` còn mở gói ra kiểm mục
     * bắt buộc bên trong (`FileGuard::verifyOfficePackage()`) — thứ ô này không làm và không nên
     * tự làm lại. Ranh giới thật vẫn là `FileGuard`; ô này chỉ là bộ lọc thô ở tầng màn hình, đủ
     * thật để không chặn oan một tệp Office hợp lệ trước khi `FileGuard` kịp mở gói ra kiểm.
     *
     * Hệ quả cho việc đọc test: `tests/Feature/Portal/SubmitDocumentTest.php` (bộ test của CHÍNH
     * trang này) đi qua nhánh `runningUnitTests()` — tệp giả `UploadedFile::fake()` khai MIME
     * tường minh — nên nó đo được đúng MỘT nửa: "ô này có DANH SÁCH đúng không". Nửa kia — "byte
     * thật của một `.docx`/`.doc` thật có được `finfo` production báo đúng MIME nằm trong danh
     * sách đó không" — là việc `tests/Feature/Support/FileGuardTest.php` đo, bằng những gói
     * ZIP/OLE2 dựng thủ công có ruột thật.
     *
     * **Phải mang đúng những MIME "đội lốt" mà `FileGuard::ALLOWED` chấp nhận cho `docx`/`xls`,
     * không chỉ MIME "sạch" của từng đuôi.** Bài học M6.5 Task 17 — vòng sửa 2 viết lại đoạn này
     * cho khớp với đoạn I3 phía trên (bản trước còn nói "trình duyệt khai", đúng cái đoạn trên
     * vừa bác bỏ): đây KHÔNG phải chuyện client đoán sai, mà là chính `finfo`/`libmagic` — thứ
     * đang đọc NỘI DUNG THẬT của tệp ở production (xem đoạn I3 phía trên) — báo `application/zip`
     * cho một `.docx`/`.xlsx` THẬT, vì OOXML VỀ MẶT CONTAINER đúng là một gói ZIP; `libmagic`
     * không mở sâu hơn để phân biệt "một gói ZIP mang cấu trúc Office" với "một ZIP bất kỳ". Cùng
     * lý lẽ đó cho `.doc`/`.xls` THẬT: chúng là gói OLE2/CFB, và `libmagic` báo
     * `application/x-ole-storage`/`application/x-cfb`/`application/CDFV2` — đúng chữ ký byte thật
     * của container đó, không phải một suy đoán. Thiếu các MIME ấy ở đây, luật `mimetypes` của
     * CHÍNH Ô NÀY chặn một tệp thật trước khi `FileGuard` có cơ hội mở gói ra kiểm tra ruột — tức
     * màn hình tự dựng lại đúng cái cổng mà `FileGuard::verifyOfficePackage()` tồn tại để làm
     * ĐÚNG hơn (mở gói, đòi mục bắt buộc), chỉ khác là nó làm SAI, bằng cách từ chối trước khi
     * kịp mở.
     *
     * Viết tay thay vì suy ra từ `FileGuard::ALLOWED` vì hai danh sách trả lời hai câu hỏi khác
     * nhau ở HÌNH DẠNG: bảng kia là "đuôi → tập MIME hợp lệ CHO ĐÚNG đuôi đó" (một cấu trúc lồng,
     * `docx` không nhận `image/jpeg`), còn danh sách này là một tập MIME phẳng dùng chung cho
     * MỌI đuôi được liệt trong `accept` — hai câu hỏi khác nhau nên một accessor chung sẽ phải
     * làm phẳng cấu trúc kia ở đâu đó, và chỗ đó không nên là một task không sở hữu `FileGuard`.
     *
     * `DocumentsRelationManager` (panel nội bộ) giữ một bản cùng vai trò, và **hai bản KHÔNG còn
     * bảo đảm bằng nhau tại một thời điểm bất kỳ** — mỗi bên tự sửa theo đúng phát hiện của lượt
     * rà soát chạm tới nó, và không bên nào suy ra từ bên kia. Cả hai đều không phải cổng an
     * ninh, nên một lần lệch chỉ làm bộ chọn tệp của MỘT panel gợi ý sai/chặn oan một tệp mà
     * `FileGuard` lẽ ra chấp nhận — khó chịu cho người dùng, không phải một lỗ hổng. Gộp cả hai
     * thành một accessor chung trên `FileGuard` vẫn là việc nên làm, và nó đã được báo lại thay
     * vì làm lén ở một task không sở hữu tệp đó.
     *
     * @return list<string>
     */
    private static function acceptedMimeTypes(): array
    {
        return [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Các MIME "đội lốt" mà `FileGuard::ALLOWED` cũng chấp nhận cho `doc`/`xls` (gói
            // OLE2 cũ) và `docx`/`xlsx` (gói OOXML) — `libmagic` (finfo) báo đúng những MIME này
            // cho NỘI DUNG THẬT của các gói đó, không phải một suy đoán của trình duyệt/hệ điều
            // hành — xem đoạn "Phải mang đúng những MIME đội lốt" ở trên.
            'application/x-ole-storage',
            'application/x-cfb',
            'application/CDFV2',
            'application/zip',
        ];
    }

    /**
     * SPEC §6.6 bước 4, cấu hình qua `.env`. Cùng giá trị mà `FileGuard` đọc, và cùng bước lùi
     * khi cấu hình thiếu hoặc không phải số dương — `(int) null === 0` thì fail-closed nhưng vô
     * nghĩa: mọi tệp đều "vượt quá 0 MB" và không ai hiểu nổi câu đó.
     */
    private static function maxMegabytes(): int
    {
        $configured = config('vkcrm.upload_max_mb');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 20;
    }

    // -------------------------------------------------------------------------------------
    // Bước 3 — Xem trước
    // -------------------------------------------------------------------------------------

    /**
     * Các tệp đang chờ gửi, đọc từ trạng thái thô của ô — rỗng khi chưa có gì.
     *
     * Vẽ trên MÁY CHỦ chứ không chỉ dựa vào ảnh thu nhỏ của FilePond, vì hai lý do: ảnh thu nhỏ
     * chỉ có với ảnh (một tệp PDF chụp từ máy quét thì không có), và nó phụ thuộc vào JavaScript
     * chạy trên máy khách. Một danh sách "Tệp sẽ gửi: …" do máy chủ vẽ ra thì luôn có, và nó là
     * thứ test đo được.
     *
     * `(array) data_get(...)` — R10 (M6.5 Task 17): với `multiple()`, trạng thái của ô là một
     * MẢNG các `TemporaryUploadedFile` (khoá theo UUID nội bộ của Filament, không phải chỉ số
     * liên tục), nên `(array)` là một no-op an toàn ở đây — khác hẳn thời `pendingFile()` (số ít,
     * đã xoá) khi ô còn nhận một tệp trần và `(array)` phải ép một OBJECT thành mảng THUỘC TÍNH
     * của nó rồi lọc lại bằng `instanceof`. Giữ nguyên phép lọc `instanceof` đó vì lý do KHÁC vẫn
     * còn nguyên: trạng thái thô có thể mang cả giá trị không phải tệp (một chuỗi rỗng khi ô mới
     * mount, chẳng hạn), và chỉ có `UploadedFile` mới đáng vẽ ra.
     *
     * @return list<array{name: string, size: string}>
     */
    public function pendingFiles(): array
    {
        return collect((array) data_get($this->data, 'file'))
            ->filter(fn (mixed $value): bool => $value instanceof UploadedFile)
            ->map(fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(),
                'size' => Number::fileSize((int) $file->getSize(), maxPrecision: 1),
            ])
            ->values()
            ->all();
    }

    // -------------------------------------------------------------------------------------
    // Bước 4 — Gửi
    // -------------------------------------------------------------------------------------

    /**
     * Màn hình có được vẽ nút Gửi không.
     *
     * Hỏi `is_active` **ngoài** policy, và đó là một việc mang sang từ rà soát M4: nhánh khách
     * của `DocumentPolicy::create()` không hỏi cột đó, nên một màn hình chỉ hỏi policy vẫn vẽ
     * nút "Gửi" cho một tài khoản vừa bị khoá — bấm vào thì Action từ chối. Không sai về an toàn;
     * sai ở chỗ mời người ta bấm vào một lời từ chối. Khi không vẽ nút, khối 4 nói ra con đường
     * CÓ THẬT: số điện thoại văn phòng — người đang không dùng được tài khoản cần một đường
     * không đi qua tài khoản.
     *
     * `trashed()` đứng cạnh `is_active` vì xoá mềm một tài khoản KHÔNG hạ cờ đó; cùng cặp điều
     * kiện mà `ChecksAccountActive` dùng ở tầng Action.
     */
    public function canSubmit(): bool
    {
        $viewer = $this->viewer();

        return (bool) $viewer->is_active && ! $viewer->trashed();
    }

    /**
     * Gửi tệp — **toàn bộ nghiệp vụ nằm trong một lời gọi Action**, phần còn lại của hàm này là
     * ba cái cổng và hai đích đến cho một lời từ chối.
     *
     * Thứ tự có lý do:
     *
     *  1. **Đầu mục trước.** Chưa chọn gì thì đó không phải một lời từ chối, chỉ là chưa đủ thông
     *     tin — một câu chỉ về bước 1, không phải 404. Đã chọn nhưng id không dùng được thì 404,
     *     và nó phải xảy ra trước khi hệ thống bỏ công đọc một tệp 20 MB.
     *  2. **Bộ đếm — chỉ HỎI** ở đây (lô này có vượt trần không), trước luật của ô và Action.
     *  3. **Luật của ô chọn tệp** (`getState()`), vì một tệp thiếu hoặc sai định dạng trả lời
     *     được mà không cần chạm tới đĩa.
     *  4. **Action**, và mọi lời từ chối của nó được đổi thành thứ khách đọc được.
     *  5. **Bộ đếm — GHI**, đúng số tệp Action vừa nhận.
     *
     * **Final review C-M10 — lật lại thứ tự "ghi trước luật của ô" của vòng sửa trước.** Bản đó
     * tính MỌI lần bấm Gửi, kể cả lần bị từ chối (quên chọn tệp, sai định dạng, `FileGuard` chặn),
     * với lý lẽ "trần an ninh không được đi vòng bằng cách cố ý gửi sai". Phán quyết lượt rà soát
     * cuối: trần an ninh trên BYTE đã nằm ở cửa thứ nhất (`_startUpload`, đếm lúc CHỌN tệp) và ở
     * `UploadThrottle` của endpoint tải lên — một lần bấm Gửi không đưa thêm byte nào vào máy chủ.
     * Cửa thứ hai vì vậy chỉ đếm những tệp thật sự vào hồ sơ, và một khách gửi sai định dạng hai
     * lần không bị trừ hai suất của 20 tệp/giờ mà họ cần cho giấy tờ thật.
     *
     * **Hai đích đến, và sự khác nhau nằm ở ĐÍCH chứ không ở họ exception:**
     *
     *  - **Ô chọn tệp** nhận `DomainException` (gồm `FileRejected`) và `ValidationException`.
     *    Màn hình này có đúng MỘT ô, nên mọi câu nói với khách đều dán vào đó — kể cả câu nói
     *    về trạng thái một bản ghi chứ không về cái tệp. Đó là điểm khác có chủ đích so với
     *    `ReportsActionFailures` ở panel nội bộ, nơi một `Notification` trôi nổi là chỗ đúng vì
     *    modal có nhiều ô; ở đây một thông báo tự tắt sau vài giây trên màn hình 375px là một
     *    câu không ai đọc hết. Sự khác nhau giữa hai `catch` bên dưới chỉ là cách LẤY câu chữ
     *    ra khỏi exception, và mỗi nhánh tự nói ra phạm vi thật của mình.
     *  - **404** cho `AuthorizationException`, xem docblock lớp. Đây là chỗ duy nhất trong dự án mà
     *    một lời từ chối của `Gate` bên trong Action được đổi thành một mã HTTP, và lý do là
     *    ngược với lý do `ReportsActionFailures` KHÔNG làm vậy ở panel nội bộ: ở đó các cổng
     *    trạng thái chạy trước `Gate` nên một mã riêng sẽ phân biệt được "tồn tại nhưng sai trạng
     *    thái" với "không có bản ghi nào như vậy"; ở đây Action đã gộp cả ba tình huống vào một
     *    lời từ chối duy nhất, và 404 là hình dạng mà mọi lời từ chối khác của trang này đã dùng.
     *
     * **`$selectedFileCount` — số tệp khách đang thấy trong ô, do nút Gửi mang theo** (sửa sau khi
     * gộp M6.5). Xem {@see self::refuseIfUploadsUnfinished()}.
     */
    public function submit(?int $selectedFileCount = null): void
    {
        $item = $this->requireChosenItem();

        // Trước MỌI cổng khác: một lô chưa tới đủ thì hỏi trần lô, bộ đếm hay luật của ô đều là
        // hỏi về một lô SAI — và nó không được tốn suất nào.
        $this->refuseIfUploadsUnfinished($selectedFileCount);

        // R10: đếm theo SỐ TỆP thật trong lô, không theo lượt bấm — xem docblock `guardRate()`.
        // Đọc trạng thái THÔ (`$this->data`, chưa qua `getState()`/validate) để HỎI trước xem cả
        // lô có vượt trần không; suất chỉ bị trừ sau khi Action nhận lô (final review C-M10).
        $rawFileCount = max(1, count((array) data_get($this->data, 'file')));

        // Vòng sửa 1 (Minor): trần MỘT LÔ, đọc TRƯỚC `guardRate()` — một lô quá khổ không đáng
        // tốn một suất trong bộ đếm giờ (cùng lý lẽ với việc `_startUpload()` hỏi kích thước
        // TRƯỚC bộ đếm). Ném bằng tay qua `failOnFile()`, KHÔNG qua `validationMessages()` của
        // field: xem đoạn giải thích dài ở `form()`, mục `maxFiles()` — hai luật `max` (kích
        // thước MỖI tệp, số lượng CẢ lô) đọc CHUNG một khoá `'max'` trong Filament nên không thể
        // mang hai câu khác nhau qua field đó; đây là câu THẬT của cổng này. Không chặn ở đây,
        // một lô 21 tệp vẫn bị chặn — nhưng bằng câu SAI: `guardRate()` ngay dưới đọc nó là "đã
        // dùng hết 20 suất trong giờ" (đúng cổng, sai lý do — khách chưa dùng suất nào, họ chỉ
        // chọn quá nhiều tệp trong MỘT lần). Đo bằng mutation: bỏ khối này, test "refuses a
        // batch of more than twenty files..." đỏ với đúng câu `rate_limited` thay vì câu của
        // cổng này.
        if ($rawFileCount > self::FILES_PER_HOUR) {
            $this->failOnFile(__('portal_submit.errors.too_many_files_per_submission', [
                'limit' => self::FILES_PER_HOUR,
            ]));
        }

        // Final review C-M10: cửa này chỉ HỎI ở đây; nó chỉ GHI sau khi Action đã nhận lô (mọi
        // tệp qua luật của ô, `FileGuard` và `VirusScanner`) — xem `chargeRate()` ở cuối hàm. Một
        // lần gửi bị từ chối (quên chọn tệp, sai định dạng, tệp bị chặn) không tốn suất nào nữa:
        // trần AN NINH trên số byte đã có cửa thứ nhất (`_startUpload`, đếm lúc CHỌN tệp) và
        // `UploadThrottle` ở endpoint tải lên, nên cửa này chỉ còn đếm những gì thật sự vào hồ sơ.
        $this->refuseIfOverRate(
            self::submissionLimiterKey($this->viewer()),
            'portal_submit.errors.rate_limited',
            count: $rawFileCount,
        );

        /** @var array{file: mixed} $state */
        $state = $this->form->getState();

        // R10 (M6.5 Task 17): `$state['file']` là một MẢNG khi ô mang `multiple()`. Lọc lại bằng
        // `instanceof` thay vì tin nguyên mảng, cùng lý do bản một-tệp trước đây kiểm
        // `instanceof UploadedFile`: `getState()` trả về `mixed`, và một phần tử không phải
        // `UploadedFile` lọt vào `SubmitClientDocument::handle()` sẽ là một `TypeError` bên
        // trong Action — tức một lỗi 500 trên màn hình khách.
        $files = collect((array) ($state['file'] ?? []))
            ->filter(fn (mixed $value): bool => $value instanceof UploadedFile)
            ->values()
            ->all();

        if ($files === []) {
            // Không với tới được qua giao diện: `required()` đã từ chối một ô trống ở dòng trên.
            $this->failOnFile(__('portal_submit.errors.file_required'));
        }

        try {
            app(SubmitClientDocument::class)->handle(
                checklistItem: $item,
                actor: $this->viewer(),
                files: $files,
            );
        } catch (ValidationException $exception) {
            // KHÔNG đường nào hôm nay tới được nhánh này, và câu đó được viết ra thay vì để
            // nhánh trông như một lớp bảo vệ: `SubmitClientDocument` chỉ ném `FileRejected` và
            // `AuthorizationException` (đọc lại cả Action lẫn `FileGuard`/`VirusScanner`), nên
            // một mutation probe xoá nhánh này để cả bộ test XANH. Nó được giữ vì hậu quả nếu
            // một ngày Action mọc thêm một `ValidationException`: khoá của nó là khoá TRẦN
            // (`file`, `status`), không khớp state path nào của form này, nên Livewire ghi câu
            // ấy vào một khoá không ô nào đọc và màn hình **không hiện gì cả** — một lần bấm
            // Gửi im lặng không kết quả, thứ hỏng tệ hơn một lời từ chối.
            $this->failOnFile(implode("\n", array_merge(...array_values($exception->errors()))));
        } catch (DomainException $exception) {
            // `FileRejected` là con của `DomainException` và **cố ý không có nhánh riêng ở đây**.
            // `ReportsActionFailures` (panel nội bộ) tách chúng vì ở đó một modal có nhiều ô và
            // chỉ `FileRejected` mới biết chắc mình nói về ô tệp. Màn hình này có ĐÚNG MỘT ô,
            // nên hai nhánh sẽ làm y hệt nhau — và hai nhánh giống hệt nhau là một lời khẳng
            // định sai rằng chúng khác nhau (đo được: tách ra thì không probe nào phân biệt nổi).
            $this->failOnFile($exception->getMessage());
        } catch (AuthorizationException) {
            abort(404);
        }

        // Final review C-M10: chỉ những tệp Action vừa nhận mới tốn suất ở cửa này.
        $this->chargeRate(self::submissionLimiterKey($this->viewer()), count($files));

        $this->submitted = true;
        $this->submittedItemName = $item->name;
        $this->resolvedItem = null;
        $this->resolvedChoices = null;

        $this->form->fill();
    }

    /**
     * Không bao giờ nói "Chúng tôi đã nhận được" về một lô chưa tới đủ.
     *
     * Với `multiple()`, FilePond tải TỪNG tệp lên riêng (`$wire.upload('data.file.{uuid}', …)`), và
     * một tệp chỉ vào `$this->data` khi `_finishUpload` của nó chạy xong. Bấm Gửi giữa chừng thì
     * lô ở máy chủ thiếu đúng tệp đang tải: trước lần sửa này nó bị bỏ lặng lẽ, Action nhận phần
     * còn lại, và khối 4 báo đã nhận.
     *
     * Lớp chặn thứ nhất ở trình duyệt: nút Gửi tắt trong lúc Filament báo `form-processing-*`
     * (xem view). Lớp này là lớp thứ hai, cho mọi trường hợp lớp kia không bắt được — sự kiện
     * đến trễ, một tệp tải lỗi vẫn nằm trong ô, hay một lần bấm dấu × mà lần gỡ chưa tới máy chủ:
     * nút Gửi mang theo `pond.getFiles().length`, và nếu con số ấy lệch với số tệp đã tới thì cả
     * lô bị từ chối — không bản ghi, không byte vào kho, không tốn suất — với một câu bảo khách
     * chờ tải xong rồi gửi lại. Lệch theo CHIỀU NÀO cũng từ chối: nhiều hơn là còn tệp đang tải,
     * ít hơn là máy chủ còn giữ một tệp khách vừa bỏ đi.
     *
     * **`null` nghĩa là "không biết", và không biết thì không chặn.** Con số đến từ trình duyệt,
     * nên đây không phải một cổng an ninh (một client tự chế nói gì cũng được, và nó chỉ lừa
     * được chính nó); nó bảo vệ một khách THẬT khỏi một cuộc đua thời gian. Khi đoạn JavaScript
     * không tìm thấy FilePond (nó chưa nạp xong, hoặc một bản Filament mới đổi dấu hiệu), nó gửi
     * `null` thay vì một số sai: chặn MỌI lần gửi vì chính mã keo của trang hỏng thì tệ hơn cái
     * lỗi đang sửa. Test "wires the send button…" ghim các dấu hiệu ấy để lần hỏng đó đỏ ở CI.
     */
    private function refuseIfUploadsUnfinished(?int $selectedFileCount): void
    {
        if ($selectedFileCount === null) {
            return;
        }

        if ($selectedFileCount !== count($this->pendingFiles())) {
            $this->failOnFile(__('portal_submit.errors.upload_incomplete'));
        }
    }

    // -------------------------------------------------------------------------------------
    // Giới hạn 20 tệp / giờ / tài khoản — SPEC §10.3
    // -------------------------------------------------------------------------------------

    /**
     * **Khoá theo TÀI KHOẢN.** SPEC §10.3 viết "20 tệp / giờ / tài khoản", không "theo IP" —
     * khác hẳn giới hạn đăng nhập của §10.3, thứ đòi cả hai chiều. Khác biệt đó có lý do: một
     * lần đăng nhập sai là một người LẠ đang thử, nên chiều IP có nghĩa; một lần nộp tệp là một
     * người đã đăng nhập, và danh tính chắc chắn nhất về họ là tài khoản. Khoá theo IP ở đây sẽ
     * khoá cả một quán cà phê vì một người gửi nhiều ảnh, và không khoá được gì khi cùng một
     * người đổi từ wifi sang 4G.
     *
     * Hai tài khoản của CÙNG một khách hàng (SPEC §4.3 nêu ví dụ hai vợ chồng) có hai bộ đếm
     * riêng: mỗi người gửi giấy tờ của mình, và mức của người này không bị người kia tiêu mất.
     */
    public static function fileLimiterKey(ClientUser $actor): string
    {
        return 'portal-submit-file:'.$actor->getKey();
    }

    /** Cửa thứ hai — xem docblock lớp. Bộ đếm riêng, cùng mức, cùng cách khoá. */
    public static function submissionLimiterKey(ClientUser $actor): string
    {
        return 'portal-submit-send:'.$actor->getKey();
    }

    /**
     * Cửa của BYTE.
     *
     * `_startUpload` là phương thức Livewire gọi khi người dùng vừa chọn xong một tệp: nó cấp
     * một URL đã ký, rồi trình duyệt tải tệp lên NGAY — trước khi bất kỳ form action nào chạy.
     * Từ chối ở đây nghĩa là không có URL nào được cấp và không byte nào rời khỏi điện thoại;
     * một giới hạn đặt ở chỗ khác chỉ dọn dẹp sau khi đĩa đã bị ghi.
     *
     * Lời từ chối đi ra bằng `ValidationException` mang đúng khoá `$name` mà Livewire vừa truyền
     * vào — tức đúng ô mà khách đang nhìn.
     *
     * **Kích thước cũng được hỏi ở đây, và đó là chỗ DUY NHẤT câu của SPEC §8.4 tới được khách.**
     * `$fileInfo` mang kích thước tệp do trình duyệt khai, trước khi một byte nào được gửi đi.
     * Một khách chụp ảnh HDR 25 MB trên 3G mà phải tải hết 25 MB rồi mới nghe "tệp quá lớn" là
     * đúng thứ SPEC §8.4 viết câu mẫu để tránh. Nói thẳng phạm vi: con số này do client khai nên
     * nó **không phải một cổng an ninh** — cổng thật là `FileGuard`, thứ đo tệp đã nằm trên đĩa.
     * Ở đây nó chỉ tiết kiệm cho khách một lần tải lên vô ích, và mang đúng câu chữ.
     *
     * `#[Renderless]` giữ nguyên hành vi của bản gốc: lần gọi này không làm trang vẽ lại.
     *
     * @param  array<int, array<string, mixed>>  $fileInfo
     */
    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple) // @phpstan-ignore-line — chữ ký của Livewire
    {
        $max = static::maxMegabytes();

        // Kích thước TRƯỚC bộ đếm, cố ý: lời từ chối này không tốn của hệ thống một byte nào,
        // nên tính nó vào mức 20 tệp/giờ là phạt một khách vì cái điện thoại của họ chụp ảnh
        // nặng. Bộ đếm canh những lần tải lên mà hệ thống đã ĐỒNG Ý nhận. Ở cửa thứ hai
        // ({@see self::submit()}) thì ngược lại và cũng vì cùng một lý lẽ: tới đó tệp đã nằm
        // trên đĩa và đã được đọc, nên một lần bị từ chối ở đó vẫn là một lần thử có giá.
        foreach ($fileInfo as $file) {
            if ((int) ($file['size'] ?? 0) > $max * 1024 * 1024) {
                $this->failOnFile(__('documents.file_guard.too_large', ['max' => $max]), $name);
            }
        }

        // R10: `$fileInfo` mang MỌI tệp mà trình duyệt chọn trong lượt này (có thể nhiều hơn một
        // khi ô mang `multiple()`) — xem docblock `guardRate()` cho lý do đếm theo tệp.
        $this->guardRate(
            self::fileLimiterKey($this->viewer()),
            'portal_submit.errors.rate_limited_upload',
            $name,
            count: max(1, count($fileInfo)),
        );

        return parent::_startUpload($name, $fileInfo, $isMultiple);
    }

    /**
     * Cửa RA của endpoint tải lên — nơi mọi lời từ chối của framework đổi thành câu của văn phòng.
     *
     * Livewire gọi phương thức này khi POST lên `livewire.upload-file` trả về một mã lỗi. Bản
     * gốc ({@see WithFileUploads::_uploadErrored()}) lấy
     * thân JSON của lời từ chối, đổi `files.0` thành tên thuộc tính rồi ném thẳng ra — khách
     * đọc "data.file không được lớn hơn 20480 kilobyte" ngay bên dưới dòng chữ hứa 20 MB. SPEC
     * §8.4 cấm đích danh kiểu thông điệp đó, và **nó cấm đúng ở màn hình này**.
     *
     * Đây là nửa còn lại của việc publish `config/livewire.php`, và nửa này ở ngay trong lớp:
     * trần đã nâng lên 20 MB thì dải 13–20 MB hết đi qua đây, nhưng endpoint còn từ chối vì
     * những lý do khác — quá trần thật (một client tự chế khai sai kích thước ở `fileInfo` để
     * đi vòng qua {@see self::_startUpload()}), chạm bộ đếm 20 tệp/giờ của endpoint
     * ({@see UploadThrottle}), chữ ký hết hạn giữa chừng, sóng đứt. Mọi lý do đó đi ra bằng MỘT
     * đường, và đường đó là đường của trang.
     *
     * # MỘT lý do được hỏi riêng, và chỉ một
     *
     * Với một lời từ chối 429 của bộ đếm giờ, câu chung `upload_failed` nói SAI: nó nêu dung
     * lượng và sóng, trong khi tệp 5 MB và sóng tốt — và người đọc sẽ đi chụp lại ảnh rồi thử
     * lại suốt một tiếng. Câu đúng đã có sẵn trong cùng tệp ngôn ngữ
     * (`portal_submit.errors.rate_limited_upload`) và trước vòng sửa này không có đường nào tới
     * được nó trên nhánh này.
     *
     * **Vì sao phải HỎI LẠI bộ đếm thay vì đọc lý do từ response.** JS của Livewire gọi
     * `_uploadErrored` với `errors` là `null` cho MỌI mã khác 422. Đã đọc trong bản đang cài
     * (`vendor/livewire/livewire/dist/livewire.esm.js`), nguyên văn:
     * `let errors = null; if (request.status === 422) { errors = request.response; }`. Nên với
     * một 429 thì component không có gì để đọc: không thân, không mã.
     * Nó hỏi `RateLimiter` trên ĐÚNG khoá mà middleware vừa ghi
     * ({@see UploadThrottle::cacheKeyFor()}), và khoá đó được ghim bằng một test đi qua HTTP thật
     * chứ không được tin.
     *
     * **Còn lại thì KHÔNG đoán lý do**, vì tới đó không còn gì để đọc ngoài một thân JSON đã
     * dịch: câu `portal_submit.errors.upload_failed` nêu hai khả năng có thật kèm việc phải làm
     * cho mỗi khả năng, và kết bằng số điện thoại văn phòng.
     *
     * `dispatch('upload:errored')` giữ nguyên của bản gốc và **phải** giữ: FilePond nghe sự kiện
     * đó để gỡ vòng quay tải lên. Bỏ nó đi thì ô chọn tệp quay mãi bên cạnh một câu từ chối.
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple) // @phpstan-ignore-line — chữ ký của Livewire
    {
        $this->dispatch('upload:errored', name: $name)->self();

        $throttleKey = UploadThrottle::keyFor(request());
        $endpointKey = UploadThrottle::cacheKeyFor($throttleKey);

        // M8 Task 3: hai dấu hiệu, vì endpoint nay đếm TỆP và từ chối CẢ lô nếu lô làm vượt trần mà
        // KHÔNG tăng bộ đếm — bộ đếm mới ở 19/20 trong khi một lô 2 tệp bị từ chối. "Đã đầy"
        // (`tooManyAttempts`) vẫn đúng cho trường hợp cũ; "vừa bị từ chối"
        // (`ThrottleUploadedFiles` đánh dấu, sống 60 giây) đúng cho lô. Dấu này có thể sống dai
        // hơn lần từ chối của nó tới 60 giây: một lỗi tệp KHÁC trong khoảng đó bị đọc là hết suất —
        // cái giá chấp nhận được, vì câu `rate_limited_upload` nói cả số phút phải chờ thật.
        if (
            RateLimiter::tooManyAttempts($endpointKey, UploadThrottle::FILES_PER_HOUR)
            || UploadThrottle::wasRecentlyRefused($throttleKey)
        ) {
            $this->failOnFile(__('portal_submit.errors.rate_limited_upload', [
                'limit' => UploadThrottle::FILES_PER_HOUR,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($endpointKey) / 60)),
                'hotline' => config('vkcrm.brand.hotline'),
            ]), $name);
        }

        $this->failOnFile(__('portal_submit.errors.upload_failed', [
            'max' => static::maxMegabytes(),
            'hotline' => config('vkcrm.brand.hotline'),
        ]), $name);
    }

    /**
     * Hỏi bộ đếm rồi GHI — `hit()` sau `tooManyAttempts()` nên lần thử thứ 21 không tự cộng thêm
     * vào bộ đếm của chính nó và kéo dài lần khoá.
     *
     * Câu từ chối nói ra cả con số, cả số phút phải chờ, cả số điện thoại văn phòng: người gặp nó
     * thường đang gửi một xấp giấy tờ thật chứ không phải đang phá hệ thống, và một câu chỉ nói
     * "quá giới hạn" để họ đứng im giữa sân uỷ ban phường.
     *
     * **`$message` là tham số vì hai cửa đếm hai việc khác nhau**, và một câu dùng chung nói dối
     * ở một trong hai: cửa của byte tiêu một suất khi khách mới CHỌN tệp, nên câu "anh/chị đã
     * gửi 20 tệp" ở đó nói về một việc chưa xảy ra. Con số thì vẫn là một: `:limit` đọc thẳng
     * {@see self::FILES_PER_HOUR}, không tệp ngôn ngữ nào viết nó ra bằng chữ số.
     *
     * **`$count` — R10 (M6.5 Task 17).** SPEC §10.3 viết "20 TỆP/giờ", không "20 lượt bấm/giờ".
     * Trước `multiple()`, một lượt CHỌN tệp và một lượt BẤM GỬI luôn đúng một tệp, nên "một đơn vị
     * mỗi lượt" và "một đơn vị mỗi tệp" là cùng một con số — không ai phải chọn. `multiple()` tách
     * hai câu đó ra: khách chọn hai mặt CCCD trong MỘT lượt (`_startUpload` gọi MỘT LẦN với
     * `$fileInfo` chứa hai phần tử — xem docblock của nó) và gửi cả lô trong MỘT lượt bấm
     * (`submit()` gọi Action với một MẢNG tệp). Đếm theo LƯỢT sẽ để một khách gửi 20 lô × 2 tệp
     * lọt qua đúng 40 tệp — gấp đôi trần SPEC đặt ra — nên cả hai cửa đếm theo SỐ TỆP thật trong
     * lô, không theo số lần gọi.
     *
     * **Từ chối CẢ LÔ nếu lô sẽ vượt trần, không nhận một phần rồi cắt phần còn lại.** Kiểm
     * `RateLimiter::attempts($key) + $count > FILES_PER_HOUR` — không phải `tooManyAttempts()`
     * (thứ chỉ hỏi "ĐÃ vượt chưa", đúng cho lượt-một-tệp nhưng sai cho lô: một khách đang ở mức 19
     * và chọn lô 2 tệp sẽ được `tooManyAttempts()` cho qua, rồi `hit()` hai lần đẩy bộ đếm lên 21
     * — vượt trần TRONG một lần cho qua). Cả lô cùng vào hoặc cả lô cùng bị chặn: một CCCD hai mặt
     * mà chỉ mặt trước lọt qua trần là một hồ sơ dở dang không ai muốn.
     */
    private function guardRate(string $key, string $message, ?string $field = null, int $count = 1): void
    {
        $this->refuseIfOverRate($key, $message, $field, $count);
        $this->chargeRate($key, $count);
    }

    /** Nửa "hỏi" của {@see self::guardRate()} — từ chối nếu `$count` tệp nữa sẽ vượt trần. */
    private function refuseIfOverRate(string $key, string $message, ?string $field = null, int $count = 1): void
    {
        if (RateLimiter::attempts($key) + $count > self::FILES_PER_HOUR) {
            $this->failOnFile(__($message, [
                'limit' => self::FILES_PER_HOUR,
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60)),
                'hotline' => config('vkcrm.brand.hotline'),
            ]), $field);
        }
    }

    /** Nửa "ghi" của {@see self::guardRate()}. */
    private function chargeRate(string $key, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            RateLimiter::hit($key, self::LIMIT_WINDOW_SECONDS);
        }
    }

    // -------------------------------------------------------------------------------------

    /**
     * Đọc lại hồ sơ qua truy vấn đã có scope, rồi hỏi `Gate` một lần nữa — hai tầng, hai câu lệnh
     * khác nhau, không chung một điều kiện nào. Cùng hình dạng `MatterProgress::resolveMatter()`,
     * và cố ý cùng hình dạng: hai trang mang cùng một tham số phải từ chối giống hệt nhau, nếu
     * không thì chính cặp câu trả lời khác nhau đó là một máy dò.
     */
    private function resolveMatter(): Matter
    {
        $viewer = $this->viewer();

        // Task 2, vòng sửa 1 (Important #2): `->with('client')` — `$matter` này được gắn sẵn vào
        // MỌI đầu mục qua `setRelation('matter', ...)` ở `choosableItems()`, nên nạp `client` một
        // lần ở đây cho `MatterPolicy::releasedToPortal()` đọc miễn phí qua `relationLoaded()` ở
        // MỌI lần hỏi `Gate` của từng đầu mục, thay vì một EXISTS mới cho mỗi đầu mục — đúng chỗ
        // "đường trong bộ nhớ" mà docblock của `choosableItems()` báo là thiếu, nay đã có.
        $matter = Matter::query()->with('client')->whereKey($this->record)->first();

        abort_if($matter === null, 404);
        abort_unless(Gate::forUser($viewer)->allows('view', $matter), 404);

        return $matter;
    }

    /**
     * Đọc lại một đầu mục và gác nó — **ba điều kiện, và điều kiện thứ ba không nằm ở policy nào**.
     *
     *  1. Truy vấn đã có `ClientPortalScope`: đầu mục của khách khác trả `null` y hệt một id bịa.
     *  2. **Đầu mục phải thuộc đúng hồ sơ đang mở.** `DocumentPolicy::create()` cho qua một đầu
     *     mục thuộc hồ sơ KHÁC của CHÍNH khách này (cả hai điều kiện của nó đều đúng), nên không
     *     có nó thì một tệp gửi từ màn hình của hồ sơ A hạ cánh xuống danh mục hồ sơ B: vẫn là
     *     giấy tờ của khách, nhưng sai hồ sơ, sai chuỗi version, và đội ngũ nhận thông báo về
     *     một hồ sơ họ không mở. Có test riêng cho điều kiện này, kèm khẳng định rằng policy
     *     KHÔNG chặn — nếu policy chặn thì test đang đo một điều kiện khác.
     *  3. `Gate` **kèm ngữ cảnh** (xem docblock lớp).
     *
     * Cả ba trả lời bằng **404**, cùng một câu trả lời cho "không tồn tại" và "không phải của
     * anh/chị" (SPEC §10.10).
     *
     * # Lần hỏi `Gate` SỐNG SÓT một lần đột biến, và câu đó phải được nói ra
     *
     * Đo được, ba lần chạy, đúng hình dạng mà `MatterProgress::checklistItems()` đã ghi lại:
     *
     *  - **xoá riêng điều kiện 3**: toàn bộ tệp test XANH. Khi hai tầng kia còn nguyên, không đầu mục
     *    nào vào tới đây mà `Gate` còn có gì để từ chối — điều kiện 1 lọc theo khách, điều kiện 2
     *    lọc theo hồ sơ, và hồ sơ ấy vừa đi qua `MatterPolicy::view` ở {@see self::resolveMatter()}.
     *  - **xoá riêng điều kiện 2**: đỏ đúng một test ("an item of another matter of the same
     *    client"). Test cách ly giữa hai khách vẫn XANH — tức **chính `Gate` là thứ đang giữ nó**
     *    trong ngữ cảnh đó.
     *  - **xoá cả hai**: đỏ thêm test "still answers another clients item with 404 when the
     *    checklist scope forgets its rule", và một đầu mục của KHÁCH KHÁC đi thẳng vào màn hình.
     *
     * Nên hai điều kiện này không phải một thứ nói hai lần: điều kiện 2 giữ PHẠM VI, `Gate` giữ
     * QUYỀN, và mỗi cái đỡ được lần quên của cái kia. Xoá `Gate` vì "không test nào đỏ" là gỡ
     * đúng cái lưới sẽ đỡ lần sửa sau — và M7 (`client_access_until`) là lần sửa đó: khi
     * `MatterChecklistItem` có điều kiện portal của riêng nó, `Gate` thành tầng duy nhất đọc nó.
     */
    private function resolveItem(int|string $key): MatterChecklistItem
    {
        $viewer = $this->viewer();

        $item = MatterChecklistItem::query()->whereKey($key)->first();

        abort_if($item === null, 404);
        abort_unless($item->matter_id === $this->matter()->getKey(), 404);
        abort_unless(Gate::forUser($viewer)->allows('create', [Document::class, $item]), 404);

        return $item;
    }

    /**
     * Đầu mục để gửi, hoặc một câu chỉ về bước 1.
     *
     * "Chưa chọn" KHÔNG phải một lời từ chối và vì thế KHÔNG phải 404: không có bản ghi nào bị
     * giấu đi ở đây, chỉ có một bước chưa làm. Trả 404 cho nó sẽ là một màn hình biến mất dưới
     * tay một người vừa quên bấm một nút.
     */
    private function requireChosenItem(): MatterChecklistItem
    {
        $item = $this->checklistItem();

        if ($item === null) {
            throw ValidationException::withMessages([
                'item' => __('portal_submit.errors.no_item'),
            ]);
        }

        return $item;
    }

    /**
     * Mọi lời từ chối của màn hình này bám vào ô chọn tệp — chỗ duy nhất khách đang nhìn.
     *
     * @return never
     */
    private function failOnFile(string $message, ?string $field = null): void
    {
        throw ValidationException::withMessages([
            $field ?? 'data.file' => $message,
        ]);
    }

    /**
     * Khách đang đọc. `Filament::auth()` là guard của panel hiện hành (`client`), không phải guard
     * mặc định của ứng dụng — trên cổng này guard `web` trống, và một lần hỏi qua nó trả lời về
     * `null` chứ không về người đang gõ cửa.
     */
    private function viewer(): ClientUser
    {
        $user = Filament::auth()->user();

        abort_unless($user instanceof ClientUser, 404);

        return $user;
    }
}
