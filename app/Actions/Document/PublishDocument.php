<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Events\DocumentPublished;
use App\Exceptions\DocumentNotPublishable;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Đưa một tài liệu ra tới khách hàng — năm bước SPEC §6.5. Đây là một thao tác KHÁCH NHÌN THẤY,
 * cùng hạng với việc công bố một dòng tiến độ ở `TransitionMatterStage`: sau khi nó chạy, có một
 * người ngoài văn phòng đã đọc được một thứ mà trước đó họ không đọc được. Mọi quyết định dưới đây
 * đều đi ra từ nhận xét đó.
 *
 * **Bản ghi được ĐỌC LẠI trong transaction, và mọi quyết định dựa trên bản đọc lại đó.**
 * `$document` mà caller đưa vào chỉ được dùng cho đúng một việc: lấy khoá chính. Một đối tượng
 * Eloquent là thứ ai cũng gán thuộc tính được (`$document->group = DocumentGroup::Issued;` không
 * cần lưu và không để lại dấu vết), nên tin nó là biến "nhóm D bị chặn tuyệt đối" thành "nhóm D bị
 * chặn nếu caller không nói dối". Đọc lại kèm `lockForUpdate()` cũng đóng khoảng chạy đua giữa
 * "đổi nhóm sang D" và "công bố" trên MariaDB — hai lệnh trên cùng một dòng nối đuôi nhau thay vì
 * chồng lên nhau. Bộ test chạy SQLite, nơi `lockForUpdate()` không sinh ra khoá nào, nên phần
 * KHOÁ của câu này không có test chứng minh; phần ĐỌC LẠI thì có (xem `PublishDocumentTest`, hai
 * test về nhóm trong cơ sở dữ liệu thắng nhóm caller cầm trong tay).
 *
 * **Thứ tự các cổng, và vì sao nhóm D đứng trước cả kiểm tra quyền.** Ba cổng đầu (nhóm D, tài
 * liệu đã xoá mềm, vụ việc đã xoá mềm) nói về TRẠNG THÁI của bản ghi chứ không về người hỏi: câu
 * trả lời y hệt nhau cho quản trị viên và cho người vừa bị thu hồi quyền, nên chúng không phân
 * biệt được ai với ai và không rò rỉ gì về phân quyền. Đặt chúng trước `Gate` là cách làm cho
 * "chặn tuyệt đối" (SPEC §6.5 bước 1) đúng nghĩa tuyệt đối: nó không phụ thuộc vào việc
 * `DocumentPolicy::publish` hôm nay nói gì, hay ngày mai ai sửa gì trong đó. Một Action không được
 * uỷ thác một bất biến dữ liệu cho tầng phân quyền.
 * Hệ quả phải để ý ở Task 6: `DocumentNotPublishable` là lỗi trên form của một trang mà người dùng
 * đã mở được, không bao giờ được dịch thành một mã HTTP khác với 404 của các đường khác (SPEC
 * §10.10).
 *
 * **Không có đường thu hồi.** Gọi lại Action với `clientCanView = false` bị từ chối chứ không âm
 * thầm ẩn tài liệu đi. Lý do không phải là kỹ thuật: khách có thể đã mở, đã tải, đã in tài liệu
 * đó; một cái nút làm nó biến mất khỏi danh sách của họ không lấy lại được gì, nhưng lại để văn
 * phòng tin là đã lấy lại được. Và ở tầng dữ liệu, `status = published` cộng `client_can_view =
 * false` là hai nguồn sự thật nói ngược nhau — đúng loại trạng thái mà vòng đời nhóm B ở SPEC
 * §4.11 sinh ra để ngăn. Rút quyền TẢI thì được (`clientCanDownload = false`, khách vẫn thấy tài
 * liệu tồn tại), vì đó là một lựa chọn SPEC §6.5 bước 3 nói thẳng là hợp lệ. Thu hồi thật sự — nếu
 * văn phòng cần — là một thao tác riêng, có lý do bắt buộc và có dấu vết cho khách, và nó chưa
 * được đặc tả.
 *
 * **Công bố lại không tua lại lịch sử.** `published_at`/`published_by` chỉ ghi ở LẦN ĐẦU. Hai cột
 * đó trả lời "tài liệu này tới tay khách lúc nào, do ai đưa ra" — một sự kiện đã xảy ra. Một lần
 * gọi lại để đổi cờ tải mà ghi đè chúng sẽ xoá mất thời điểm duy nhất có thể đối chiếu với
 * `document_downloads` (SPEC §4.12) và với hộp thư của khách.
 *
 * **Cổng vòng đời nhóm B chặn cú NHẢY VÀO `published`, không chặn một lần đổi cờ trên tài liệu
 * đã ở đó.** Đây là phân biệt mà bản đầu của Action không có, và nó biến "rút quyền tải, giữ
 * quyền xem" (SPEC §6.5 bước 3) thành một việc không làm nổi với nhóm B — xem bình luận tại chỗ.
 *
 * **Form cũ bị từ chối (kiểm tra optimistic) — vòng sửa 1, MỞ RỘNG ở vòng sửa 2 (N1).**
 * `$expectedClientCanView`/`$expectedClientCanDownload`/`$expectedIsReleased` là ảnh chụp trạng
 * thái công bố LÚC MÀN HÌNH MỞ RA (`fillForm()` của `DocumentsRelationManager::publishAction()`),
 * truyền song song với `$clientCanView`/`$clientCanDownload` — hai thứ sau là GIÁ TRỊ MUỐN ĐẶT,
 * không phải giá trị đang có, nên không thể dùng chúng để dò xem có ai sửa tài liệu này ở nơi
 * khác. Không có ảnh chụp riêng, hai tab cùng mở một tài liệu ĐÃ công bố sẽ đè lên nhau trong im
 * lặng: tab 1 thấy "xem+tải", tab 2 (hay chính `PublishDocument` gọi từ một nơi khác) tắt "tải"
 * trước; tab 1 xác nhận KHÔNG SỬA GÌ vẫn gửi lại đúng "xem+tải" nó đã thấy — và vì đó là giá trị
 * MUỐN ĐẶT hợp lệ như mọi lần khác, Action mở lại quyền tải mà tab 1 không hề chủ ý bật nó lên.
 *
 * **Vòng sửa 1 chỉ so khi tài liệu ĐÃ công bố LÚC ĐANG XÉT (`$wasAlreadyReleased`), và đó là lỗ
 * hổng `task-16-fix2-findings.md` N1 ghi lại.** Bản đó bỏ lọt đúng hai tình huống mà một cổng
 * optimistic tồn tại để bắt — cả hai đều là lúc TRẠNG THÁI CÔNG BỐ đổi giữa lúc mount và lúc xác
 * nhận, không phải lúc nó đứng yên:
 *  - **Hai tab, lần công bố ĐẦU.** Tab 1 mount khi CHƯA công bố (`expectedClientCanView = null`,
 *    cách màn hình cũ nói "đây là lần đầu"). Tab 2 công bố trước. Tab 1 xác nhận SAU: lúc đó
 *    `$wasAlreadyReleased` đã là `true`, nhưng điều kiện `$expectedClientCanView !== null` SAI
 *    (nó vẫn là `null`, ảnh chụp từ lúc CHƯA công bố) — cổng tắt, tab 1 ghi đè tab 2.
 *  - **Thu hồi rồi trả lại.** Ảnh chụp lấy lúc ĐÃ công bố. Giữa đó và lúc xác nhận, tài liệu bị
 *    rút vào nhóm D rồi trả về nhóm cũ (cờ khách không tự phục hồi — xem hook `saving` của
 *    `Document`). Lúc xác nhận, `$wasAlreadyReleased` HIỆN TẠI là `false` — điều kiện đầu của cổng
 *    (`$wasAlreadyReleased &&`) sai, cổng tắt, ảnh chụp cũ không hề bị so.
 *
 * **Sửa: so sánh LUÔN chạy, trên hai vế.** `$expectedIsReleased` là ảnh chụp của chính
 * `$wasAlreadyReleased` lúc mount — `fillForm()` giờ LUÔN gửi giá trị THẬT của nó (không còn
 * `null` làm dấu hiệu "lần đầu"). Cổng từ chối khi:
 *  1. `$expectedIsReleased !== $wasAlreadyReleased` (HIỆN TẠI) — trạng thái công bố đã đổi dưới
 *     chân form, bất kể đổi theo chiều nào (chưa→đã, hay đã→chưa qua một vòng khứ hồi); hoặc
 *  2. tài liệu ĐANG công bố (`$wasAlreadyReleased`) và hai cờ khách đã đổi so với ảnh chụp.
 * Nhánh (1) một mình đã phủ cả hai kịch bản trên; nhánh (2) bắt thêm trường hợp trạng thái công
 * bố KHÔNG đổi (vẫn đang công bố cả hai lần) nhưng ai đó đổi CỜ TẢI ở giữa — đúng phạm vi vòng sửa
 * 1 đã bắt. Không còn `null` nào trong ba tham số này: một lần công bố ĐẦU TIÊN gửi
 * `expectedIsReleased = false` (khớp `$wasAlreadyReleased` hiện tại, cũng `false`, nếu không ai
 * chen ngang) thay vì bỏ qua cổng bằng một giá trị đặc biệt.
 */
class PublishDocument
{
    use ReadsWithoutPortalScope;

    public function handle(
        Document $document,
        User $actor,
        bool $clientCanView,
        bool $clientCanDownload,
        bool $expectedClientCanView,
        bool $expectedClientCanDownload,
        bool $expectedIsReleased,
    ): Document {
        return DB::transaction(function () use (
            $document, $actor, $clientCanView, $clientCanDownload,
            $expectedClientCanView, $expectedClientCanDownload, $expectedIsReleased,
        ): Document {
            // Đọc lại bản ghi thật. `withTrashed()` để một tài liệu đã xoá mềm nhận được câu trả
            // lời riêng của nó thay vì lẫn vào "không tồn tại".
            // `scopelessly()`: lần đọc lại này KHÔNG được phụ thuộc vào guard nào đang mở. Dưới
            // guard `client` — M5 mở portal, và một nhân sự đăng nhập cả hai panel đã có cả hai
            // guard cùng xác thực — `ClientPortalScope` cắt truy vấn xuống "đã công bố và khách
            // được xem", nên một tài liệu nhóm C còn nháp sẽ đọc ra `null` và Action trả lời
            // "không có bản ghi nào như vậy" về một bản ghi đang nằm đó. Xem docblock
            // `ReadsWithoutPortalScope`.
            $fresh = $this->scopelessly(Document::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($document->getKey());

            if ($fresh === null) {
                throw DocumentNotPublishable::missing();
            }

            // Bước 1 (SPEC §6.5): nhóm D, chặn tuyệt đối.
            if ($fresh->group->isInternal()) {
                throw DocumentNotPublishable::internalGroup($fresh);
            }

            if ($fresh->trashed()) {
                throw DocumentNotPublishable::trashed($fresh);
            }

            // `belongsTo` của một model có SoftDeletes trả null khi vụ việc đã bị xoá mềm, nên
            // `withTrashed()` là cách duy nhất phân biệt "vụ việc đã xoá" với "khoá ngoại hỏng".
            // Cả hai đều chặn, nhưng chỉ một trong hai có câu để nói với người dùng.
            $matter = $this->scopelessly($fresh->matter()->withTrashed()->getQuery())->first();

            if ($matter === null || $matter->trashed()) {
                throw DocumentNotPublishable::matterUnavailable($fresh);
            }

            // Cổng trạng thái thứ tư, cùng hạng với ba cổng trên và cùng lý do đứng trước
            // `Gate`: câu trả lời không phụ thuộc vào người hỏi. Công bố một bản ghi chưa có
            // tệp đẩy sang khách một dòng trong danh sách mà bấm vào không tải được gì — một
            // lời hứa rỗng, và với một văn bản toà thì là một lời hứa rỗng đúng lúc khách cần
            // nó nhất. Nó xảy ra thật: `UploadStaffDocument` tạo bản ghi rồi mới gắn tệp, nên
            // giữa hai bước đó (hoặc sau một lần rollback nửa vời) tồn tại một `Document`
            // không tệp.
            if ($fresh->getMedia('file')->isEmpty()) {
                throw DocumentNotPublishable::withoutFile($fresh);
            }

            // Action tự kiểm tra quyền, không tin caller đã kiểm tra. Hỏi trên `$fresh` chứ không
            // trên `$document`: policy đọc `group` và `matter` của đối tượng được đưa vào, nên một
            // đối tượng bị sửa thuộc tính trong bộ nhớ sẽ trả lời thay cho dòng dữ liệu thật.
            Gate::forUser($actor)->authorize('publish', $fresh);

            // Bước 3, phần bị từ chối — xem docblock lớp.
            if (! $clientCanView) {
                throw DocumentNotPublishable::withoutClientView($fresh);
            }

            // "Tài liệu này ĐÃ tới tay khách chưa" — đọc trước cổng vòng đời, vì chính cổng đó
            // cần câu trả lời. Không dùng riêng `status = published`: một lệnh ghi thẳng vào cột
            // `status` (sửa tay, một màn hình quên đi qua Action) không phải một lần tài liệu ra
            // tới khách, và nhận nó là "đã công bố" sẽ biến nó thành lối tắt qua vòng đời nhóm B.
            // `Document::wasPublishedToClient()` là MỘT chỗ định nghĩa duy nhất cho câu hỏi này —
            // `RegroupDocument` cũng gọi đúng hàm đó, xem docblock của nó (vòng sửa 1: hai Action
            // từng tính hai biểu thức khác nhau cho cùng câu hỏi).
            $wasAlreadyReleased = $fresh->wasPublishedToClient();

            // Kiểm tra optimistic (vòng sửa 1, MỞ RỘNG vòng sửa 2 — xem docblock lớp cho lý do
            // đầy đủ). Đứng NGAY SAU khi `$wasAlreadyReleased` có giá trị, vì nhánh thứ hai cần
            // nó. So sánh LUÔN chạy — không còn nhánh "bỏ qua vì đây là lần đầu" (đúng lỗ hổng N1
            // vòng sửa 2 ghi lại: chính cái bỏ qua đó là chỗ hai kịch bản lọt qua).
            if ($expectedIsReleased !== $wasAlreadyReleased
                || ($wasAlreadyReleased
                    && ($expectedClientCanView !== $fresh->client_can_view
                        || $expectedClientCanDownload !== $fresh->client_can_download))
            ) {
                throw DocumentNotPublishable::staleForm($fresh);
            }

            // Bước 2: vòng đời nhóm B (SPEC §4.11). Chỉ nhóm B — nhóm C là văn bản do cơ quan nhà
            // nước ban hành, văn phòng không soạn và không ký nên không có gì để trình duyệt.
            //
            // Cổng này chặn CÚ NHẢY VÀO `published`, không chặn một lần đổi cờ trên tài liệu đã
            // ở đó. Thiếu `! $wasAlreadyReleased`, một tài liệu nhóm B vừa công bố xong không
            // bao giờ công bố lại được: lần gọi thứ hai đọc ra `status = published`, thấy nó
            // khác `signed_filed` và từ chối — bằng một câu nói rằng văn bản chưa được ký và
            // nộp, trong khi nó đã ký, đã nộp và đang nằm trong cổng của khách. Hậu quả là SPEC
            // §6.5 bước 3 ("cho xem mà chưa cho tải") không với tới được nhóm B, đúng nhóm mà
            // SPEC dựng cả một vòng đời để canh.
            if (! $wasAlreadyReleased
                && $fresh->group === DocumentGroup::Issued
                && $fresh->status !== DocumentStatus::SignedFiled
            ) {
                throw DocumentNotPublishable::notSignedAndFiled($fresh);
            }

            // Bước 3 và 4.
            $fresh->update([
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => $clientCanDownload,
                'published_at' => $wasAlreadyReleased ? $fresh->published_at : now(),
                'published_by' => $wasAlreadyReleased ? $fresh->published_by : $actor->getKey(),
            ]);

            // Bước 5, nửa sau: thông báo cho khách với tài liệu quan trọng (nhóm B hoặc C), và chỉ
            // ở lần công bố đầu — xem docblock `DocumentPublished`. Dispatch bên trong transaction
            // là an toàn vì sự kiện là `ShouldDispatchAfterCommit`.
            if (! $wasAlreadyReleased && in_array($fresh->group, [DocumentGroup::Issued, DocumentGroup::Authority], true)) {
                event(new DocumentPublished($fresh));
            }

            // Bước 5, nửa đầu: SPEC §10.6 bắt buộc ghi nhật ký MỌI lần công bố tài liệu. Ghi BÊN
            // TRONG transaction, cùng lý do với `TransitionMatterStage`: nếu dòng nhật ký nằm ngoài
            // thì có một khoảng — dù rất ngắn — mà tài liệu đã công bố còn dòng nhật ký thì chưa
            // ghi, và một tiến trình chết đúng lúc đó để lại một lần công bố không ai biết là của
            // ai. Causer truyền tường minh là `$actor`: Action đã kiểm tra quyền trên đúng người
            // này, nên dòng nhật ký phải mang đúng tên người này, không suy ra từ phiên `auth()`
            // đang mở (có thể là người khác, hoặc không có ai).
            // `client_id` và `version` được chép vào dòng nhật ký chứ không để người đọc tự suy
            // ra: "tài liệu này đã ra tới AI" hôm nay suy được qua `matter`, nhưng
            // `matters.client_id` là một cột sửa được, nên phép suy đó không ổn định qua thời
            // gian — một hồ sơ chuyển sang khách hàng khác sẽ viết lại lịch sử của mọi lần công
            // bố đã xảy ra. `version` trả lời "BẢN NÀO đã ra", câu mà một danh mục hồ sơ có
            // nhiều lần nộp lại (SPEC §6.6 bước 7) không trả lời được nếu chỉ có `document_id`.
            Audit::record('document_published', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'group' => $fresh->group->value,
                'version' => $fresh->version,
                'client_can_view' => true,
                'client_can_download' => $clientCanDownload,
                'republished' => $wasAlreadyReleased,
            ], $actor);

            return $fresh;
        });
    }
}
