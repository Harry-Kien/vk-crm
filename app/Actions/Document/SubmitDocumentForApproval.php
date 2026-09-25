<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentLifecycleNotAllowed;
use App\Models\Document;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Trình duyệt" — bước ĐẦU của vòng đời văn bản nhóm B (SPEC §4.11): `internal_draft` →
 * `pending_approval`. Trước Action này không có Action, nút hay ô nào ghi được trạng thái đó, nên
 * mọi văn bản nhóm B mãi kẹt ở `internal_draft` và không bao giờ công bố được — `docs/docs-1`
 * (critical).
 *
 * **"Ai trình duyệt": phán quyết R9, vì SPEC im lặng.** SPEC §4.11 bắt buộc vòng đời này tồn tại
 * nhưng không nói ai được đẩy nó đi bước đầu tiên. R9 đọc "trình duyệt" là một việc hồ sơ thường
 * ngày — soạn xong, gửi cho người có thẩm quyền xem — không phải một quyết định về số phận tài
 * liệu, nên nó đòi đúng `DocumentPolicy::update` (`matter.update` cộng đọc được tài liệu), cùng
 * quyền với việc sửa tiêu đề hay gắn đầu mục danh mục. Trợ lý làm được bước này. Phân biệt với
 * bước SAU — "Đã ký, đã nộp" — mới là quyết định (`MarkDocumentSignedFiled`, đòi
 * `document.publish`), vì chỉ sau bước đó văn bản mới công bố được cho khách.
 *
 * **Hình dạng chép từ `RegroupDocument`, không từ `PublishDocument`.** Cả ba cổng trạng thái ở
 * đây (nhóm B, tài liệu xoá mềm, vụ việc xoá mềm) đều là sự thật ĐỘC LẬP với người hỏi — nhưng
 * không có gì ở đây được SPEC gọi là "tuyệt đối" như nhóm D, nên chúng không cần đứng trước `Gate`
 * như `PublishDocument` làm với nhóm D. Thứ tự ở đây: đọc lại dưới khoá → cổng cấu trúc (nhóm B)
 * → `Gate::authorize('update')` → cổng vòng đời (đang ở `internal_draft`) → ghi.
 */
class SubmitDocumentForApproval
{
    use ReadsWithoutPortalScope;

    public function handle(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
            // Đọc lại bản ghi thật dưới khoá, cùng lý do với `PublishDocument`/`RegroupDocument`:
            // không tin đối tượng caller cầm trong tay, và không để guard đang mở quyết định nó
            // đọc thấy gì (`scopelessly()` — xem docblock `ReadsWithoutPortalScope`).
            $fresh = $this->scopelessly(Document::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($document->getKey());

            if ($fresh === null) {
                throw DocumentLifecycleNotAllowed::missing();
            }

            if ($fresh->trashed()) {
                throw DocumentLifecycleNotAllowed::trashed($fresh);
            }

            $matter = $this->scopelessly($fresh->matter()->withTrashed()->getQuery())->first();

            if ($matter === null || $matter->trashed()) {
                throw DocumentLifecycleNotAllowed::matterUnavailable($fresh);
            }

            // Chỉ nhóm B đi qua vòng đời này — xem docblock `DocumentLifecycleNotAllowed::notGroupB()`.
            if ($fresh->group !== DocumentGroup::Issued) {
                throw DocumentLifecycleNotAllowed::notGroupB($fresh);
            }

            // Action tự kiểm tra quyền, không tin caller đã kiểm tra — hỏi trên `$fresh`, không
            // trên `$document`, cùng lý do với `PublishDocument`.
            Gate::forUser($actor)->authorize('update', $fresh);

            if ($fresh->status !== DocumentStatus::InternalDraft) {
                throw DocumentLifecycleNotAllowed::notInternalDraft($fresh);
            }

            $fresh->update(['status' => DocumentStatus::PendingApproval]);

            // SPEC §10.6 không liệt kê tên sự kiện này (bảng đó được viết trước khi vòng đời nhóm
            // B có đường nào đi qua nó), nhưng cùng lý lẽ với `document_regrouped`: một bước
            // chuyển trạng thái không ai ghi lại là một bước không ai lần ra được sau này.
            Audit::record('document_submitted_for_approval', $fresh, [
                'matter_id' => $fresh->matter_id,
                'from_status' => DocumentStatus::InternalDraft->value,
                'to_status' => DocumentStatus::PendingApproval->value,
            ], $actor);

            return $fresh;
        });
    }
}
