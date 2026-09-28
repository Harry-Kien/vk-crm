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
 * "Đã ký, đã nộp" — bước THỨ HAI và cuối của vòng đời văn bản nhóm B trước khi nó công bố được
 * (SPEC §4.11): `pending_approval` → `signed_filed`. `PublishDocument` đã luôn đòi đúng trạng thái
 * này cho nhóm B (bước 2, SPEC §6.5) — cái thiếu, trước Action này, là không có gì ghi được nó.
 *
 * **"Ai đánh dấu": phán quyết R9.** Khác "Trình duyệt" (việc hồ sơ thường ngày), đánh dấu một văn
 * bản là đã ký và đã nộp là một lời XÁC NHẬN rằng nó đã rời khỏi văn phòng và tới tay bên thứ ba
 * (toà, cơ quan nhà nước) — đúng loại quyết định mà sau đó văn bản một bước nữa là tới tay khách.
 * R9 vì vậy đặt nó cùng cổng với `document.publish`, không phải `matter.update`: trợ lý trình
 * duyệt được nhưng không đánh dấu được bước này, cùng ranh giới "làm hồ sơ" / "quyết định số phận
 * tài liệu" mà `DocumentPolicy::publish` đã dựng cho chính việc công bố.
 *
 * Hình dạng và thứ tự cổng chép từ `SubmitDocumentForApproval` — xem docblock lớp đó.
 */
class MarkDocumentSignedFiled
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

            // `document.publish`, không `document.update` — xem docblock lớp.
            Gate::forUser($actor)->authorize('publish', $fresh);

            if ($fresh->status !== DocumentStatus::PendingApproval) {
                throw DocumentLifecycleNotAllowed::notPendingApproval($fresh);
            }

            $fresh->update(['status' => DocumentStatus::SignedFiled]);

            Audit::record('document_signed_filed', $fresh, [
                'matter_id' => $fresh->matter_id,
                'from_status' => DocumentStatus::PendingApproval->value,
                'to_status' => DocumentStatus::SignedFiled->value,
            ], $actor);

            return $fresh;
        });
    }
}
