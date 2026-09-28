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
 * "Trả về bản nháp" — ruling vòng sửa 1 (Task 16): đi NGƯỢC một bước của vòng đời văn bản nhóm B
 * (SPEC §4.11), `pending_approval` → `internal_draft`. Trước Action này, một bản thảo đã trình
 * duyệt nhầm (nội dung còn sai, hay bấm "Trình duyệt" nhầm dòng) không có đường quay lại: người
 * dùng chỉ còn cách tải một tài liệu MỚI lên, để lại một dòng `pending_approval` mồ côi trên bảng.
 *
 * **Đòi `document.publish`, không `document.update` — khác hẳn "Trình duyệt".** "Trình duyệt" là
 * việc hồ sơ thường ngày (`SubmitDocumentForApproval`, đòi `document.update`), nhưng ĐI NGƯỢC một
 * bước đã trình duyệt là xoá đi một quyết định "nội dung này sẵn sàng để xem xét ký" — cùng hạng
 * quyết định với việc đánh dấu đã ký (`MarkDocumentSignedFiled`) hay công bố. Cho trợ lý làm được
 * bước này sẽ để họ tự rút lại một bản thảo mà một luật sư khác đã gửi lên đúng lúc luật sư đó
 * đang xem xét nó — một cuộc đua không cần tồn tại.
 *
 * Hình dạng và thứ tự cổng chép từ `SubmitDocumentForApproval`/`MarkDocumentSignedFiled` — đọc
 * lại dưới khoá, `scopelessly()`, cổng cấu trúc (nhóm B) trước `Gate`, cổng trạng thái sau `Gate`.
 */
class ReturnDocumentToDraft
{
    use ReadsWithoutPortalScope;

    public function handle(Document $document, User $actor): Document
    {
        return DB::transaction(function () use ($document, $actor): Document {
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

            if ($fresh->group !== DocumentGroup::Issued) {
                throw DocumentLifecycleNotAllowed::notGroupB($fresh);
            }

            Gate::forUser($actor)->authorize('publish', $fresh);

            if ($fresh->status !== DocumentStatus::PendingApproval) {
                throw DocumentLifecycleNotAllowed::notPendingApprovalToReturn($fresh);
            }

            $fresh->update(['status' => DocumentStatus::InternalDraft]);

            Audit::record('document_returned_to_draft', $fresh, [
                'matter_id' => $fresh->matter_id,
                'from_status' => DocumentStatus::PendingApproval->value,
                'to_status' => DocumentStatus::InternalDraft->value,
            ], $actor);

            return $fresh;
        });
    }
}
