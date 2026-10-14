<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Exceptions\DocumentCorrectionRefused;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A4) — XOÁ (mềm) một tài liệu tải lên nhầm mà khách chưa
 * từng thấy. Luật "ai xoá được gì" đã có từ M4/M7/M9 ở `DocumentPolicy::delete` (không ra tới khách,
 * chưa rút, có `document.publish` + quyền sửa, không làm bằng chứng tiền) mà không màn hình nào dùng:
 * thông báo thụ lý của khách X tải nhầm vào vụ của khách Y ở nhóm C, bản nháp, chỉ còn cách chuyển
 * sang nhóm D — tệp của X nằm mãi trong vụ của Y (đội ngũ của Y thấy, tìm theo tiêu đề ra).
 *
 * **Thứ tự** (như `RetractDocument`): `matter_id` đọc trước transaction; khoá `matters` rồi `documents`;
 * người thực hiện đọc lại từ CSDL; policy hỏi trên bản vừa khoá. Policy trả `Response::deny()` kèm câu
 * riêng cho tệp làm bằng chứng tiền — câu đó đi ra nguyên vẹn. Lý do bắt buộc,
 * {@see self::REASON_MIN}–{@see self::REASON_MAX} ký tự sau khi gỡ khoảng trắng Unicode hai đầu (cùng
 * cách `RegroupDocument`), lỗi gắn vào ô `delete_reason`.
 *
 * **Xoá MỀM.** Dòng `documents`, tệp và mọi `document_downloads` ở lại (quản trị khôi phục được; hook
 * `Document::deleting` chặn cùng điều kiện tiền trên mọi đường). Dòng nhật ký `document_deleted` mang hồ
 * sơ, nhóm, trạng thái, version và LÝ DO — không tiêu đề (dòng tài liệu vẫn còn, đọc được ở đó).
 */
class DeleteDocument
{
    use ReadsWithoutPortalScope;

    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function handle(Document $document, User $actor, string $reason): Document
    {
        $reason = $this->validatedReason($reason);

        $matterId = $this->scopelessly(Document::query())
            ->withTrashed()
            ->whereKey($document->getKey())
            ->value('matter_id');

        return DB::transaction(function () use ($document, $actor, $reason, $matterId): Document {
            $matter = $matterId === null ? null : $this->scopelessly(Matter::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($matterId);

            $fresh = $this->scopelessly(Document::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($document->getKey());

            if ($fresh === null || $matter === null || (int) $fresh->matter_id !== (int) $matter->getKey()
                || $fresh->trashed() || $matter->trashed()) {
                throw DocumentCorrectionRefused::documentUnavailable();
            }

            $fresh->setRelation('matter', $matter);

            $freshActor = User::query()->find($actor->getKey());

            if ($freshActor === null || ! $freshActor->is_active) {
                throw new AuthorizationException;
            }

            $verdict = Gate::forUser($freshActor)->inspect('delete', $fresh);

            if ($verdict->denied()) {
                throw DocumentCorrectionRefused::notDeletable($verdict->message());
            }

            $fresh->delete();

            Audit::record('document_deleted', $fresh, [
                'matter_id' => $matter->getKey(),
                'group' => $fresh->group->value,
                'status' => $fresh->status->value,
                'version' => $fresh->version,
                'reason' => $reason,
            ], $freshActor);

            return $fresh;
        });
    }

    private function validatedReason(string $reason): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $reason) ?? '';
        $length = mb_strlen($trimmed);

        if ($length < self::REASON_MIN) {
            throw ValidationException::withMessages(['delete_reason' => [__('document_corrections.delete.errors.reason_min', ['min' => self::REASON_MIN])]]);
        }

        if ($length > self::REASON_MAX) {
            throw ValidationException::withMessages(['delete_reason' => [__('document_corrections.delete.errors.reason_max', ['max' => self::REASON_MAX])]]);
        }

        return $trimmed;
    }
}
