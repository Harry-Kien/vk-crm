<?php

namespace App\Http\Controllers;

use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use App\Support\Files\FileGuard;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đường DUY NHẤT tới một tệp hồ sơ (SPEC §10.4). Disk `private` nằm ngoài document root, không
 * đặt `serve`, không có `storage:link`, và `config/filesystems.php` đã tách gốc của nó khỏi mọi
 * disk khác — nên không có route nào của framework, của Livewire hay của Filament chạm tới
 * `storage/app/private`. `tests/Feature/Storage/PrivateDiskTest.php` kiểm tiền đề đó bằng hành vi,
 * và `DocumentDownloadTest` kiểm nốt phần còn lại: disk MẶC ĐỊNH của ứng dụng không được là
 * `private`, vì hai route phục vụ tệp có sẵn (xem trước tệp tải lên của Livewire
 * `livewire/preview-file/{filename}`, tải bản xuất của Filament `filament/exports/{export}/download`)
 * rơi về disk mặc định khi không có `config/livewire.php`/`config/filament.php` riêng — và cả hai
 * chỉ đòi một chữ ký, không hỏi `DocumentPolicy` và không ghi một dòng `document_downloads` nào.
 *
 * **Chữ ký và policy chặn hai thứ khác nhau, nên phải có cả hai (SPEC §10.4 nói thẳng).**
 *
 * - Chữ ký trả lời "đường dẫn này có phải do hệ thống phát ra, cho người này, và còn hạn không".
 *   Nó chặn thứ policy không thấy: một id đoán mò, một tham số bị sửa, một đường dẫn cũ được phát
 *   lại sau 5 phút. Nó được kiểm bởi middleware `signed`, TRƯỚC khi controller chạy, và một chữ ký
 *   hỏng/hết hạn trả **403**. 403 ở đây đúng vì nó nói về ĐƯỜNG DẪN chứ không về một bản ghi:
 *   không có bản ghi nào được đọc trước khi nó được trả, nên nó không phân biệt được "tài liệu
 *   này có thật" với "tài liệu này không có". `AnswerDeniedPanelRequestsWithNotFound` cố ý chỉ
 *   phủ hai panel và docblock của nó nêu đích danh route này là chỗ 403 phải được giữ.
 * - Phần kiểm tra trong controller trả lời "người đang cầm đường dẫn này, LÚC NÀY, có được tải tài
 *   liệu này không". Nó chặn thứ chữ ký không thấy: chữ ký là một ảnh chụp của quá khứ và không
 *   bao giờ đổi ý. Trong 5 phút đó một luật sư có thể bị gỡ khỏi đội ngũ, một tài liệu có thể bị
 *   chuyển sang nhóm D, bị thu hồi (xoá mềm), hay bị tắt cờ cho khách tải — chữ ký vẫn hợp lệ
 *   nguyên vẹn. Ba tầng trả lời khác đi, và chúng KHÔNG phải một: `DocumentPolicy::download()` lo
 *   phần quyền, `SoftDeletingScope` trong {@see self::resolve()} lo phần tài liệu đã bị thu hồi,
 *   và {@see self::actor()} lo phần tài khoản đã bị vô hiệu (SPEC §10.9 — `canAccessPanel()` chỉ
 *   canh hai panel, route này nằm ngoài chúng). Mọi từ chối ở tầng này trả **404** (SPEC §10.10:
 *   không có quyền và không tồn tại trả cùng một mã).
 *
 * **Chữ ký còn nói đường dẫn được phát cho NGƯỜI NÀO.** `Document::downloadUrlFor()` ký kèm một mã người
 * nhận, và controller đòi mã đó khớp người đang đăng nhập. Không có ràng buộc này, một URL đã ký
 * là một tấm vé vô danh trong 5 phút: ai đọc được nó (lịch sử trình duyệt trên máy dùng chung,
 * một màn hình đang chia sẻ, một dòng log proxy) đều dùng được nếu họ tình cờ cũng có quyền — và
 * `document_downloads`, thứ SPEC §4.12 dựng lên để trả lời "khách đã mở tài liệu đó chưa", sẽ ghi
 * đúng tên người đã bấm chứ không phải người được trao tài liệu. Ràng buộc này không NỚI gì:
 * policy vẫn chạy sau nó, và một người khác muốn tải thì tự mở trang của mình mà lấy đường dẫn
 * của chính họ.
 *
 * Hệ quả cho người dựng đường dẫn, nói thẳng vì nó im lặng: một đường dẫn ký THIẾU tham số
 * `recipient` có chữ ký hoàn toàn hợp lệ và trả 404 mãi mãi, không một dòng lỗi nào. `Document
 * ::downloadUrlFor()` là chỗ duy nhất nên dùng để dựng; `URL::temporarySignedRoute('documents
 * .download', …)` gọi thẳng thì tự chịu. Có test ghim hành vi 404 đó để nó là một quyết định chứ
 * không phải một điều ngẫu nhiên.
 *
 * **Giới hạn {@see self::DOWNLOADS_PER_MINUTE} lượt/phút/tài khoản** — xem hằng số đó cho con số
 * và lý do chọn nó.
 */
final class DocumentDownloadController extends Controller
{
    /**
     * Số lượt tải tối đa mỗi phút cho MỘT tài khoản.
     *
     * **Đây là một quyết định của dự án, không phải một điều khoản SPEC.** SPEC §10.3 đặt giới
     * hạn cho việc NỘP tệp (20 tệp/giờ/tài khoản) và không nói gì về việc tải; con số dưới đây do
     * vòng rà soát cuối M4 chọn, và nó được ghi ra đây để lần sau không ai đi tìm nó trong SPEC.
     *
     * **Vì sao là một giới hạn tần suất chứ không phải một nonce.** Một mã dùng một lần đóng được
     * đúng chuyện này và mở ra một chuyện tệ hơn: nó TỪ CHỐI lần bấm lại sau một lượt tải đứt
     * giữa chừng — đúng lúc mà một cuốn sổ chứng cứ không được phép từ chối. `document_downloads`
     * ghi "hệ thống đã trao tệp", không ghi "người dùng đã nhận đủ" (xem
     * {@see self::recordDownload()}), nên một kết nối rớt để lại một dòng đã ghi và một người
     * chưa có tệp. Giới hạn tần suất không đụng tới lần bấm lại đó.
     *
     * **Vì sao 60.** Việc thật lớn nhất mà văn phòng làm ở đây là tải cả tập hồ sơ của một vụ
     * việc về để in — hàng chục tệp, bấm liên tiếp. 60 lượt/phút để việc đó trôi mà không ai
     * chạm trần, trong khi một vòng lặp quét cả cuốn sổ chứng cứ thì dừng lại ngay. Nếu văn
     * phòng thật sự cần tải hơn 60 tệp trong một phút thì chỗ sửa là một thao tác "tải cả tập"
     * (một tệp nén, một lượt), không phải nâng con số này.
     *
     * **Đếm theo TÀI KHOẢN, không theo IP.** Cả văn phòng ngồi sau một đường truyền, nên một
     * giới hạn theo IP là một người làm khoá tất cả. Khoá đếm lấy từ
     * {@see Document::recipientToken()} nên hai guard không bao giờ đụng nhau.
     *
     * Một lần chạm trần trả **429**, không phải 404. Nó không mâu thuẫn với luật 404 của SPEC
     * §10.10: 429 được trả TRƯỚC khi đọc bất kỳ bản ghi nào và cho mọi id như nhau, nên nó không
     * phân biệt được "tài liệu này có thật" với "tài liệu này không có".
     */
    public const DOWNLOADS_PER_MINUTE = 60;

    /**
     * `$document` là một chuỗi id thô, KHÔNG phải một tham số route model binding. Cố ý: binding
     * ngầm giải bản ghi qua `Document::query()`, tức qua global scope của guard đang mở, nên câu
     * trả lời sẽ đổi theo việc ai tình cờ đang đăng nhập ở tab khác — và một bản ghi bị scope
     * loại sẽ thành 404 TRƯỚC khi policy kịp nói gì, làm cho tầng policy không còn chịu tải.
     * Ở đây bản ghi được giải một lần, không phụ thuộc guard, rồi policy quyết định tất cả.
     */
    public function __invoke(Request $request, string $document): Response
    {
        $actor = $this->actor();
        $record = $this->resolve($document);

        // Nhánh `$record === null` KHÔNG có test phân biệt được, và mutation probe đã chứng minh:
        // xoá nó đi bộ test vẫn xanh, vì `Gate::allows('download', null)` không giải được policy
        // nào và trả `false`, tức cũng ra 404. Giữ lại vì hai lý do: phụ thuộc vào cách Gate xử
        // một đối số `null` là phụ thuộc vào một chi tiết không ai viết ra hợp đồng, và một bản
        // Laravel sau đổi nó thành `TypeError` sẽ biến một 404 thành một 500 mà không test nào
        // kêu. Ở đây nó là một câu lệnh thừa có chủ đích, không phải một lớp bảo vệ.
        if ($actor === null || $record === null) {
            abort(404);
        }

        if (! $this->wasIssuedTo($request, $actor)) {
            abort(404);
        }

        if (! Gate::forUser($actor)->allows('download', $record)) {
            abort(404);
        }

        // Một `Document` không có tệp tồn tại được (nó bị `PublishDocument` từ chối, không bị
        // cấm tạo ra), và nó phải là 404 chứ không phải một phản hồi rỗng.
        $media = $record->getFirstMedia('file');

        if ($media === null) {
            abort(404);
        }

        $disk = Storage::disk($media->disk);

        // Một dòng `media` mà tệp không còn trên đĩa: đến từ một lần dọn thư mục hay một lần
        // khôi phục sao lưu lệch (chiều ngược lại — tệp mồ côi không có dòng `media` — là thứ
        // `StoresDocumentFile` chấp nhận có chủ đích). Kiểm TRƯỚC khi ghi nhật ký, vì
        // `StreamedResponse` gửi header xong mới đọc đĩa: không có lần kiểm này, một tệp thiếu
        // sẽ để lại một dòng `document_downloads` nói rằng khách đã nhận được thứ họ chưa bao
        // giờ nhận.
        if (! $disk->exists($media->getPathRelativeToRoot())) {
            abort(404);
        }

        // HEAD không mang theo một byte nội dung nào (Symfony bỏ body ở `Response::prepare()`),
        // nên nó không phải một lượt tải và không được ghi một dòng chứng cứ nói rằng nó là.
        // Đây là nhánh TRẢ TỆP mà không ghi nhật ký duy nhất, và nó rẽ theo PHƯƠNG THỨC HTTP chứ
        // không theo một header do client đặt: một header kiểu `Purpose: prefetch` là thứ người
        // tải tự khai, nên rẽ nhánh theo nó sẽ biến nó thành công tắc tắt chứng cứ — một người
        // trong văn phòng muốn tải mà không để lại dấu vết chỉ cần thêm một dòng header.
        //
        // Cái giá của lựa chọn đó, nói thẳng: một lần trình duyệt tự prefetch một liên kết tải
        // VẪN sinh một dòng `document_downloads`, tức cuốn sổ đếm thừa. Đếm thừa là phía an toàn
        // cho một cuốn sổ chứng cứ (thiếu một dòng thì câu trả lời "khách chưa mở" là một lời
        // nói dối; thừa một dòng thì nó chỉ là một lần mở không ai chủ ý). `Cache-Control:
        // no-store` giữ cho một lần prefetch không biến thành nhiều lần đọc lại từ bộ đệm.
        if (! $request->isMethod('GET')) {
            return $this->fileResponse($record, $actor, $disk, $media);
        }

        $this->recordDownload($record, $actor, $request);

        return $this->fileResponse($record, $actor, $disk, $media);
    }

    /**
     * Cùng thứ tự ưu tiên với `Audit::record()` và với nhánh ngoài-panel của `ClientPortalScope`:
     * nhân sự thắng khi cả hai guard cùng có phiên (hai panel dùng chung cookie phiên).
     *
     * Không đăng nhập thì KHÔNG chuyển hướng về trang đăng nhập: đây không phải một trang, và
     * một lần chuyển hướng sẽ cất nguyên URL đã ký (kèm chữ ký) vào session `url.intended`. Trả
     * 404 như mọi từ chối khác.
     *
     * **`is_active` phải được kiểm Ở ĐÂY, và đây là một lỗ hổng thật đã được vá, không phải một
     * lớp phòng thủ thêm cho vui.** SPEC §10.9: một tài khoản bị vô hiệu phải mất hiệu lực "ngay
     * ở request kế tiếp". Trong ứng dụng này, điều đó được thi hành bởi ĐÚNG MỘT chỗ —
     * `User::canAccessPanel()` / `ClientUser::canAccessPanel()`, thứ mà `Filament\Http\Middleware\
     * Authenticate` gọi — và route này không thuộc panel nào, nên nó không đi qua chỗ đó. Không
     * có dòng dưới đây, một tài khoản portal bị vô hiệu vẫn tải được tệp bằng một đường dẫn ký
     * trước lúc bị vô hiệu, suốt phần còn lại của 5 phút: policy không hỏi `is_active` (nó hỏi về
     * tài liệu), và global scope portal cũng không (điều kiện của nó là `clients` và
     * `is_published_to_portal`).
     *
     * **Cùng lý lẽ đó, Task 2 vòng sửa 1 (Important #2) thêm điều kiện "khách hàng chưa xoá
     * mềm".** `ClientUser::canAccessPanel()` đã có điều kiện này từ vòng sửa Task 2 đầu tiên
     * (`portal/portal-3`), nhưng — CÙNG một câu ở trên — `canAccessPanel()` chỉ chạy cho hai
     * panel, và route tải tệp này nằm ngoài chúng. Không có dòng dưới đây, xoá mềm một `Client`
     * không rút được quyền tải tệp của tài khoản cổng thuộc khách hàng đó qua một đường dẫn đã ký
     * từ trước, dù `MatterPolicy::releasedToPortal()` (đã sửa cùng vòng này) đã đóng đường đó lại
     * ở TẦNG POLICY — hai tầng độc lập, đúng cấu trúc "Ba tầng trả lời khác đi" mà class này nói
     * ngay ở đầu tệp, giờ thành bốn cho riêng khách hàng đã xoá mềm.
     *
     * **Và Task 2 của M8b (§10.7) thêm điều kiện "nhân sự đã cài 2FA"** — xem chú thích ở nhánh đó
     * trong thân hàm.
     */
    private function actor(): User|ClientUser|null
    {
        $actor = self::authenticated();

        if ($actor?->is_active !== true) {
            return null;
        }

        if ($actor instanceof ClientUser && $actor->client === null) {
            return null;
        }

        // §10.7 (M8 Task 2, R2): nhân sự CHƯA có 2FA (vừa bị "Đặt lại 2FA", hay chưa cài lần đầu)
        // không tải được tệp. Cổng `EnsureMultiFactorAuthenticationIsEnabled` của Filament chỉ đứng
        // trước route của trang panel — route này nằm ngoài chúng, nên một đường dẫn ký còn hạn
        // (5 phút) cộng một phiên đăng nhập mới bằng mật khẩu sẽ đi vòng qua 2FA nếu thiếu dòng này.
        if ($actor instanceof User && blank($actor->getAppAuthenticationSecret())) {
            return null;
        }

        return $actor;
    }

    /**
     * Ai đang cầm request này, theo đúng thứ tự ưu tiên của {@see self::actor()} — và KHÔNG hỏi
     * `is_active`. Tách ra vì {@see self::rateLimitKey()} chạy ở middleware, trước controller, và
     * nó cần danh tính chứ không cần hiệu lực: một tài khoản vừa bị vô hiệu vẫn phải bị đếm theo
     * tên nó thay vì rơi chung vào rổ theo IP của cả văn phòng.
     */
    private static function authenticated(): User|ClientUser|null
    {
        return auth('web')->user() ?? auth('client')->user();
    }

    /**
     * Khoá đếm của `throttle:document-download` — xem {@see self::DOWNLOADS_PER_MINUTE}.
     *
     * `recipientToken()` chứ không phải id trần: hai guard có thể cùng có một tài khoản mang id
     * 12, và gộp chúng vào một rổ đếm sẽ cho một khách hàng khoá được một luật sư.
     *
     * Nhánh chưa đăng nhập đếm theo IP. Nó không có ai để đếm theo, và bỏ hẳn giới hạn ở nhánh đó
     * sẽ biến chính nó thành đường vòng: không đăng nhập thì controller trả 404, nhưng 404 đó vẫn
     * tốn một lần đọc cơ sở dữ liệu cho mỗi request.
     */
    public static function rateLimitKey(Request $request): string
    {
        $actor = self::authenticated();

        return $actor === null
            ? 'ip:'.$request->ip()
            : Document::recipientToken($actor);
    }

    /**
     * Giải bản ghi KHÔNG qua `ClientPortalScope` — policy là tầng chịu tải, và nhánh khách của
     * `DocumentPolicy::view()` chạy lại đúng scope đó qua `visibleToPortal()` với actor tường
     * minh, nên bỏ nó ở đây không nới thêm một dòng nào cho khách; nó chỉ làm câu trả lời không
     * còn phụ thuộc vào guard nào tình cờ đang mở.
     *
     * `SoftDeletes` thì GIỮ: một tài liệu đã xoá mềm không được tải về bằng một đường dẫn ký trước
     * đó. (Xoá mềm từng là đường thu hồi trên thực tế; từ M7 Task 7 đường thu hồi là
     * `RetractDocument`, và tài liệu đã rút bị policy từ chối với khách vì nó không còn
     * `published` + cờ xem.) Vì vậy phải là `withoutGlobalScope(ClientPortalScope::class)` chứ tuyệt đối
     * không phải `withoutGlobalScopes()`, thứ sẽ gỡ luôn cả `SoftDeletingScope` — mutation probe
     * đổi đúng một chữ đó làm đỏ test tài liệu đã xoá mềm.
     *
     * **Nói thẳng phần KHÔNG có test: bỏ hẳn `withoutGlobalScope()` thì bộ test vẫn xanh** (một
     * mutation probe đã chạy và sống sót). Không có test nào phân biệt được, và điều đó ĐÚNG:
     * mọi trường hợp scope portal loại một tài liệu cũng là trường hợp policy từ chối nó, nên
     * hai đường cho cùng một mã 404. Điều kiện này không phải một hàng rào thứ hai — nó chỉ làm
     * cho câu trả lời không đổi theo việc guard nào tình cờ đang mở, và làm cho tầng policy là
     * tầng thật sự chịu tải thay vì được một global scope che phía trước. Đây là một lựa chọn về
     * chỗ đặt trách nhiệm, không phải một lớp bảo vệ đang được chứng minh bằng test.
     *
     * Quan hệ `matter` được nạp sẵn bằng một truy vấn cũng không có scope portal: policy đọc
     * `$document->matter`, và một lần nạp LƯỜI sẽ chạy dưới guard đang mở chứ không dưới actor —
     * đúng lỗi mà `SubmitClientDocument` đã vấp ở Task 4 (một lần nộp hợp lệ bị từ chối). Kèm
     * `withTrashed()` vì `MatterPolicy::view()` cũng tra bằng `withTrashed()`: điều kiện "vụ việc
     * chưa xoá mềm" thuộc về `MatterPolicy::update`, một chỗ duy nhất, không phải về chỗ này.
     *
     * Và cùng một lời thú nhận như đoạn trên: **bỏ hẳn `with()` thì bộ test vẫn xanh** (mutation
     * probe đã chạy). Lý do giống hệt — mọi trường hợp scope portal làm `matter` thành `null`
     * cũng là trường hợp policy từ chối, nên cả hai ra 404. Nó ở đây để câu trả lời không phụ
     * thuộc guard, không phải vì có một lỗ hổng nào đang bị nó bịt.
     */
    private function resolve(string $document): ?Document
    {
        return Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->with(['matter' => fn ($query) => $query->withTrashed()->withoutGlobalScope(ClientPortalScope::class)])
            ->whereKey($document)
            ->first();
    }

    /**
     * Một phép so sánh chuỗi thường, và nói rõ vì sao nó không cần hơn: mã người nhận KHÔNG phải
     * bí mật — nó nằm nguyên văn trong query string, ai cầm đường dẫn cũng đọc được — nên ở đây
     * không có gì để một phép so sánh theo thời gian làm rò rỉ. Thứ làm cho lần kiểm này có giá
     * trị là CHỮ KÝ phủ lên tham số đó: không ai sửa được mã người nhận mà chữ ký còn đúng, nên
     * một đường dẫn chỉ dùng được bởi đúng người nó được phát cho.
     */
    private function wasIssuedTo(Request $request, User|ClientUser $actor): bool
    {
        return $request->query(Document::DOWNLOAD_RECIPIENT_PARAMETER) === Document::recipientToken($actor);
    }

    /**
     * Hai dòng cho một lượt tải, vì chúng trả lời hai câu hỏi khác nhau:
     *
     * - `document_downloads` (SPEC §4.12) là cuốn sổ văn phòng tra khi khách nói "tôi chưa nhận
     *   được bản đó": một bảng hẹp, có chỉ số theo tài liệu và theo người tải, đếm và lọc được.
     * - Một dòng `document_downloaded` trong activity log, vì SPEC §10.6 liệt kê "tải tài liệu"
     *   trong danh sách BẮT BUỘC ghi nhật ký, và nhật ký đó là nơi người rà soát đọc mọi loại sự
     *   kiện cạnh nhau. Thuộc tính chép đúng bộ mà `PublishDocument` dùng (`client_id` và
     *   `version` nằm đó vì `matters.client_id` sửa được, nên suy ra "của khách nào" qua vụ việc
     *   không ổn định theo thời gian).
     *
     * Ghi TRƯỚC khi phản hồi được gửi, vì không có chỗ nào khác để ghi: thân `StreamedResponse`
     * chạy sau khi header đã bay đi. Hệ quả phải nói thẳng: dòng này nghĩa là "hệ thống đã trao
     * tệp", không phải "người dùng đã nhận đủ tệp" — một kết nối đứt giữa chừng không rút lại
     * được dòng nào, và không máy chủ nào biết được điều đó.
     *
     * Không có khử trùng lặp. Bấm tải hai lần là hai dòng, và đó là câu trả lời đúng: chúng là
     * hai lần mở. Cũng không có phản hồi 206 nào để phân vân — phản hồi ở đây là một
     * `StreamedResponse` không quảng cáo `Accept-Ranges` và không xử lý `Range`, nên mọi lần
     * tải thành công đều là trọn tệp.
     */
    private function recordDownload(Document $document, User|ClientUser $actor, Request $request): void
    {
        DocumentDownload::create([
            'document_id' => $document->getKey(),
            'downloader_type' => $actor->getMorphClass(),
            'downloader_id' => $actor->getKey(),
            'ip' => $this->fitColumn($request->ip(), 45),
            'user_agent' => $this->fitColumn($request->userAgent(), 500),
            'downloaded_at' => now(),
        ]);

        Audit::record('document_downloaded', $document, [
            'matter_id' => $document->matter_id,
            'client_id' => $document->matter?->client_id,
            'group' => $document->group->value,
            'version' => $document->version,
        ], $actor);

        // M7 Task 4 (SPEC §10.6 "xuất dữ liệu"): gói bàn giao là tính năng xuất dữ liệu đầu tiên của
        // hệ thống, nên mỗi lượt tải nó ghi THÊM một dòng `data_exported` — cạnh
        // `document_downloaded` ở trên chứ không thay nó (sổ `document_downloads` và dòng nhật ký
        // tải tệp áp cho mọi tài liệu, kể cả gói).
        if (MatterArchive::isHandoverDocument($document)) {
            Audit::record('data_exported', $document, [
                'matter_id' => $document->matter_id,
                'client_id' => $document->matter?->client_id,
                'kind' => 'handover_package',
                'action' => 'downloaded',
                'version' => $document->version,
            ], $actor);
        }
    }

    /**
     * `user_agent` là chuỗi do người tải hoàn toàn kiểm soát và cột là `varchar(500)` utf8mb4.
     *
     * **Đo trên chính container MariaDB của dự án, không suy đoán** (`@@sql_mode` có
     * `STRICT_TRANS_TABLES`): chèn 501 ký tự vào `varchar(500)` → `ERROR 1406 Data too long`;
     * chèn byte `0x80` → `ERROR 1366 Incorrect string value`. Cả hai làm câu INSERT thất bại, tức
     * làm MẤT chính dòng chứng cứ và biến một lượt tải hợp lệ thành lỗi 500. Bộ test chạy SQLite,
     * thứ nhận cả hai không một lời nào, nên đây là một luật mà bộ test KHÔNG kiểm được trực
     * tiếp; test chỉ ghim được kết quả sau khi cắt (500 ký tự, UTF-8 hợp lệ).
     *
     * Cắt theo KÝ TỰ chứ không theo byte, vì `varchar(500)` của MariaDB đếm ký tự: 500 ký tự
     * tiếng Việt vừa đủ, còn `substr()` 500 byte sẽ cắt vào giữa một ký tự nhiều byte và sinh ra
     * đúng loại chuỗi mà lỗi 1366 nói tới.
     *
     * **`mb_convert_encoding()` hôm nay là THỪA, và nói ra chứ không giả vờ ngược lại.** Một
     * mutation probe xoá nó đi và bộ test vẫn xanh; lý do đã đo được: chính `mb_substr()` cũng
     * thay byte hỏng bằng `mbstring.substitute_character` (`"Mozilla/5.0 \x80\xFE (dt)"` ra
     * `"Mozilla/5.0 ?? (dt)"`, `mb_check_encoding` từ `false` thành `true`). Không test nào phân
     * biệt được hai bản, nên đây không phải một lỗ hổng độ phủ mà là một dòng tương đương. Nó
     * được giữ vì dựa vào một hàm CẮT để kiêm luôn việc làm sạch là dựa vào một hiệu ứng phụ:
     * ngày ai đó đổi `mb_substr` sang một cách cắt khác, việc làm sạch sẽ biến mất mà không một
     * dòng đỏ nào báo.
     */
    private function fitColumn(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    /**
     * **`Content-Disposition` do Laravel dựng, không bao giờ nối chuỗi bằng tay.**
     * `Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition()` — thứ một đoạn mã tự dựng
     * header sẽ gọi tới — ném `InvalidArgumentException` với MỌI tên tệp tiếng Việt có dấu ("The
     * filename fallback must only contain ASCII characters") và với bất kỳ dấu `%` nào; đã chạy
     * thử, không phải suy đoán. `FilesystemAdapter::download()` tự tính bản dự phòng ASCII
     * (`Str::ascii()` rồi bỏ `%`) và truyền vào làm `$filenameFallback`, nên header ra đúng cả hai
     * phần RFC 6266 đòi: `filename=` cho phần ASCII và `filename*=UTF-8''…` cho bản có dấu.
     *
     * Tên truyền vào phụ thuộc AI đang tải — xem {@see self::downloadName()} cho luật đầy đủ
     * (ruling vòng sửa 1) và cho việc nó đi qua `FileGuard::safeName()`, cùng cổng mà mọi tên đi
     * vào một header `Content-Disposition` phải đi qua.
     */
    private function fileResponse(Document $document, User|ClientUser $actor, FilesystemAdapter $disk, Media $media): Response
    {
        return $disk->download($media->getPathRelativeToRoot(), $this->downloadName($document, $actor, $media), [
            // Tệp hồ sơ không được nằm lại trong bất kỳ bộ nhớ đệm chung nào, và một phản hồi
            // không lưu lại cũng là thứ khiến một lần "quay lại" trong trình duyệt phải đi lại
            // qua chữ ký và policy thay vì đọc bản cũ.
            'Cache-Control' => 'private, no-store, max-age=0',
            // `Content-Disposition: attachment` đã ngăn trình duyệt hiển thị tệp, nhưng nosniff
            // là hàng rào thứ hai cho đúng trường hợp header kia bị bỏ qua (SPEC §10.2).
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * **Tên tải về phụ thuộc AI đang tải — ruling vòng sửa 1 Task 16, sửa lại phần "chỉ dùng
     * `title`" của `docs/docs-5`.** Nhân sự văn phòng (`User`) vẫn nhận tên tệp GỐC do chính họ
     * (hoặc đồng nghiệp) đặt lúc nộp — `media.name`, y hệt hành vi trước Task 16: họ cần nhận ra
     * đúng bản trong đống tệp làm việc của mình ("Đơn KK – bản 3 – ý kiến LS chưa duyệt.pdf" là
     * một cái tên CÓ ÍCH cho chính người đang giữ hồ sơ đó, dù vô nghĩa với khách). Chỉ khách
     * (`ClientUser`, tải qua cổng) nhận tên theo `Document::title` đã công bố — đúng sự thật
     * `docs/docs-5` chỉ ra: khách chỉ từng thấy `title` trên portal, không bao giờ thấy tên tệp
     * nội bộ, nên tên tải về phải khớp cái họ đã thấy.
     *
     * Hai nhánh, hai hàm riêng — {@see self::staffDownloadName()} và
     * {@see self::portalDownloadName()} — vì chúng không chia sẻ luật: nhánh nhân sự "mượn đuôi
     * KHI THIẾU" (hành vi gốc, `media.name` hầu như luôn tự mang đuôi thật); nhánh khách nối đuôi
     * VÔ ĐIỀU KIỆN (`title` không phải tên tệp, gần như không bao giờ tự mang đuôi — xem docblock
     * `portalDownloadName()`). Gộp chung một nhánh "mượn khi thiếu" cho cả `title` sẽ tái hiện lỗi
     * vòng sửa 1 sửa: một tiêu đề có dấu chấm bên trong (ví dụ ngày tháng) bị hiểu nhầm đã có đuôi.
     */
    private function downloadName(Document $document, User|ClientUser $actor, Media $media): string
    {
        return $actor instanceof ClientUser
            ? $this->portalDownloadName($document, $media)
            : $this->staffDownloadName($media);
    }

    /**
     * **Nhân sự: tên tệp GỐC do người nộp đặt, y hệt hành vi trước Task 16.** `media.name` đã qua
     * `FileGuard::safeName()` một lần lúc nộp (`StoresDocumentFile::storeFile()`); ở đây nó đi qua
     * LẦN NỮA vì đây là một giá trị đọc ra từ cơ sở dữ liệu — có thể do một bản mã cũ, một lần sửa
     * tay — và docblock `FileGuard` nói thẳng mọi tên đi vào `Content-Disposition` phải qua hàm đó
     * trước, không có ngoại lệ cho "chắc nó đã sạch rồi".
     *
     * **Đuôi chỉ MƯỢN KHI THIẾU** — khác nhánh khách. `media.name` gần như luôn tự mang đuôi thật
     * (nó LÀ một tên tệp), nhưng mặc định của medialibrary khi gọi `addMedia()` trơn (không qua
     * `StoresDocumentFile`) là tên BỎ đuôi, nên một dòng tạo bằng đường khác — một lần nhập dữ
     * liệu, một bản mã cũ — có thể có tên hiển thị không đuôi. Đuôi mượn về từ `media.file_name`
     * (tên NẰM TRÊN ĐĨA) cũng đi qua `safeName()` một lần nữa trước khi nối, cùng lý do ở trên.
     *
     * Tên cuối cùng PHẢI còn đuôi — xem đoạn cuối docblock {@see self::portalDownloadName()} cho
     * lý do đầy đủ (`HeaderUtils::makeDisposition()` ném lỗi với bản dự phòng ASCII rỗng); không
     * còn đuôi thì trả tên dự phòng, thứ đã là ASCII thuần.
     */
    private function staffDownloadName(Media $media): string
    {
        $name = FileGuard::safeName((string) $media->name);

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = pathinfo((string) $media->file_name, PATHINFO_EXTENSION);

            if ($extension !== '') {
                $name = FileGuard::safeName($name.'.'.$extension);
            }
        }

        return pathinfo($name, PATHINFO_EXTENSION) === ''
            ? __('documents.fallback_file_name')
            : $name;
    }

    /**
     * **Khách: `title` đã làm sạch, cộng đuôi tệp gốc — `docs/docs-5`.** Gợi ý ở ô "Tên tài liệu"
     * (`documents.tab.fields.title_help`) nói với nhân sự rằng tiêu đề là thứ DUY NHẤT khách
     * thấy — đúng cho nhánh này: khách chỉ từng đọc `title` trên portal, chưa bao giờ thấy tên tệp
     * nội bộ, nên tên tải về phải khớp cái họ đã thấy trước khi bấm tải.
     *
     * **Đuôi lấy từ `media.file_name` (tên NẰM TRÊN ĐĨA), nối vào MỘT CÁCH VÔ ĐIỀU KIỆN** — khác
     * nhánh nhân sự. `title` không phải một tên tệp, nó gần như không bao giờ tự mang đuôi, nên
     * "mượn khi thiếu" luôn đúng cho nhánh này — nhưng viết `pathinfo($title, PATHINFO_EXTENSION)`
     * để QUYẾT ĐỊNH có mượn hay không là một cái bẫy: một tiêu đề có dấu chấm bên trong (ví dụ
     * "Biên bản ngày 20.03.2026") bị hiểu nhầm đã có đuôi ("2026"), và đuôi THẬT trên đĩa (`.pdf`)
     * không bao giờ được gắn vào. Nối vô điều kiện rồi để `safeName()` tách đuôi từ DẤU CHẤM CUỐI
     * CÙNG (luôn là đuôi mới nối) tránh được bẫy đó.
     *
     * **`title` không phải một đường dẫn, và không được xử lý như một cái.** Tiêu đề văn bản
     * tiếng Việt mang dấu `/` một cách hoàn toàn hợp lệ (ví dụ "Quyết định số 42/2026"), nhưng
     * `FileGuard::safeName()` coi `/` là dấu phân cách thư mục (`basename(str_replace('\\','/',
     * $name))`, viết cho tên TỆP). Gọi thẳng `safeName($title)` sẽ CẮT MẤT mọi thứ trước dấu `/`
     * CUỐI CÙNG — "Quyết định số 42/2026" biến thành "2026", một lỗi mất dữ liệu âm thầm còn tệ
     * hơn một lần từ chối. `/` và `\` vì vậy được thay bằng `-` TRƯỚC khi tiêu đề đi vào
     * `safeName()`.
     *
     * **Và tên cuối cùng PHẢI còn một đuôi, nếu không thì lượt tải này là một lỗi 500.**
     * `FilesystemAdapter::download()` tự tính bản dự phòng ASCII bằng `Str::ascii()` rồi bỏ `%`,
     * còn `HeaderUtils::makeDisposition()` ném `InvalidArgumentException` khi bản dự phòng đó
     * RỖNG. Một tiêu đề toàn chữ Hán hay emoji cho đúng chuỗi rỗng ấy (`Str::ascii('日本語')` →
     * `''`), và khi `media.file_name` cũng không có đuôi nào thì không còn byte ASCII nào sống
     * sót. Dấu chấm của phần đuôi luôn qua được `Str::ascii()`, nên "còn đuôi" ĐỒNG NGHĨA với
     * "bản dự phòng không rỗng" — một câu kiểm đuôi ở cuối đóng được cả lớp lỗi này. Không còn
     * đuôi thì trả về tên dự phòng, thứ đã là ASCII thuần.
     */
    private function portalDownloadName(Document $document, Media $media): string
    {
        $extension = pathinfo((string) $media->file_name, PATHINFO_EXTENSION);
        $stem = str_replace(['/', '\\'], '-', $document->title);
        $name = FileGuard::safeName($extension === '' ? $stem : $stem.'.'.$extension);

        return pathinfo($name, PATHINFO_EXTENSION) === ''
            ? __('documents.fallback_file_name')
            : $name;
    }
}
