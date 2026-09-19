<?php

namespace App\Actions\Document;

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
 */
class PublishDocument
{
    public function handle(
        Document $document,
        User $actor,
        bool $clientCanView,
        bool $clientCanDownload,
    ): Document {
        return DB::transaction(function () use (
            $document, $actor, $clientCanView, $clientCanDownload,
        ): Document {
            // Đọc lại bản ghi thật. `withTrashed()` để một tài liệu đã xoá mềm nhận được câu trả
            // lời riêng của nó thay vì lẫn vào "không tồn tại".
            $fresh = Document::query()
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
            $matter = $fresh->matter()->withTrashed()->first();

            if ($matter === null || $matter->trashed()) {
                throw DocumentNotPublishable::matterUnavailable($fresh);
            }

            // Action tự kiểm tra quyền, không tin caller đã kiểm tra. Hỏi trên `$fresh` chứ không
            // trên `$document`: policy đọc `group` và `matter` của đối tượng được đưa vào, nên một
            // đối tượng bị sửa thuộc tính trong bộ nhớ sẽ trả lời thay cho dòng dữ liệu thật.
            Gate::forUser($actor)->authorize('publish', $fresh);

            // Bước 3, phần bị từ chối — xem docblock lớp.
            if (! $clientCanView) {
                throw DocumentNotPublishable::withoutClientView($fresh);
            }

            // Bước 2: vòng đời nhóm B (SPEC §4.11). Chỉ nhóm B — nhóm C là văn bản do cơ quan nhà
            // nước ban hành, văn phòng không soạn và không ký nên không có gì để trình duyệt.
            if ($fresh->group === DocumentGroup::Issued && $fresh->status !== DocumentStatus::SignedFiled) {
                throw DocumentNotPublishable::notSignedAndFiled($fresh);
            }

            $wasAlreadyPublished = $fresh->status === DocumentStatus::Published;

            // Bước 3 và 4.
            $fresh->update([
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => $clientCanDownload,
                'published_at' => $wasAlreadyPublished ? $fresh->published_at : now(),
                'published_by' => $wasAlreadyPublished ? $fresh->published_by : $actor->getKey(),
            ]);

            // Bước 5, nửa sau: thông báo cho khách với tài liệu quan trọng (nhóm B hoặc C), và chỉ
            // ở lần công bố đầu — xem docblock `DocumentPublished`. Dispatch bên trong transaction
            // là an toàn vì sự kiện là `ShouldDispatchAfterCommit`.
            if (! $wasAlreadyPublished && in_array($fresh->group, [DocumentGroup::Issued, DocumentGroup::Authority], true)) {
                event(new DocumentPublished($fresh));
            }

            // Bước 5, nửa đầu: SPEC §10.6 bắt buộc ghi nhật ký MỌI lần công bố tài liệu. Ghi BÊN
            // TRONG transaction, cùng lý do với `TransitionMatterStage`: nếu dòng nhật ký nằm ngoài
            // thì có một khoảng — dù rất ngắn — mà tài liệu đã công bố còn dòng nhật ký thì chưa
            // ghi, và một tiến trình chết đúng lúc đó để lại một lần công bố không ai biết là của
            // ai. Causer truyền tường minh là `$actor`: Action đã kiểm tra quyền trên đúng người
            // này, nên dòng nhật ký phải mang đúng tên người này, không suy ra từ phiên `auth()`
            // đang mở (có thể là người khác, hoặc không có ai).
            Audit::record('document_published', $fresh, [
                'matter_id' => $fresh->matter_id,
                'group' => $fresh->group->value,
                'client_can_view' => true,
                'client_can_download' => $clientCanDownload,
                'republished' => $wasAlreadyPublished,
            ], $actor);

            return $fresh;
        });
    }
}
