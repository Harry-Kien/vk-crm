<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Exceptions\DocumentCorrectionRefused;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A6) — "Từ chối bản mới, giữ bản đã nhận". Khách (hay người
 * nhà dùng chung tài khoản) gửi thêm một ảnh mờ vào một đầu mục văn phòng ĐÃ NHẬN: đầu mục thành "chờ
 * duyệt" trên version mới (`SubmitClientDocument` — có chủ đích, đó là đường sửa sai của khách). Trước
 * Action này văn phòng chỉ còn hai lựa chọn sai: nhận version mới (ảnh mờ thay bản rõ trong gói bàn
 * giao) hoặc từ chối nó (khách nhận thư "giấy tờ bị từ chối" về giấy văn phòng đã có, bị nhắc nộp lại,
 * và gói bàn giao bỏ hẳn mục).
 *
 * **Khi nào làm được** (đọc trên bản vừa khoá): đầu mục `pending_review`; có `accepted_version` (bản văn
 * phòng nhận gần nhất — ghi bởi `ReviewChecklistItem`/`UploadStaffDocument`, `SubmitClientDocument`
 * không xoá nó) nhỏ hơn version đang chờ; và bản đã nhận đó vẫn còn trên cổng khách (`published` + cờ
 * xem, chưa rút, chưa xoá). Không thì `DocumentCorrectionRefused::noAcceptedVersionToKeep()` — một hàm
 * cho Action và cho nút ({@see self::isAvailable()}).
 *
 * **Làm gì.** Mọi tài liệu nhóm A chưa rút của đầu mục có version LỚN HƠN bản đã nhận (bản gửi thêm) bị
 * RÚT khỏi cổng khách — cùng các cột `RetractDocument` ghi (`retracted`, hai cờ khách tắt,
 * `retracted_at/by`, lý do) và cùng dòng nhật ký `document_retracted` (kèm số lượt khách đã tải), nên
 * khách thấy dòng "Văn phòng đã rút lại tài liệu này. Lý do: …" chứ không thấy tệp biến mất không lời.
 * Tệp và lượt tải giữ nguyên. Đầu mục trở lại `accepted` (người bấm, lúc bấm), `accepted_version` giữ
 * nguyên, `rejection_reason = null`. KHÔNG sự kiện `ChecklistItemRejected`, không thư. Dòng
 * `checklist_item_kept_accepted_version` mang bản đã giữ và id các tài liệu vừa rút.
 *
 * **Quyền** là quyền duyệt (`checklist.review`, qua {@see OpensChecklistItem}) — đây là một quyết định
 * duyệt trên tệp KHÁCH gửi, cùng hạng với "Từ chối"; không đòi `document.publish` như nút "Rút lại" của
 * tab Tài liệu (thứ rút tài liệu VĂN PHÒNG đã công bố). **Lý do** khách đọc được: bắt buộc,
 * {@see RetractDocument::REASON_MIN}–{@see RetractDocument::REASON_MAX} ký tự sau khi gỡ khoảng trắng
 * Unicode hai đầu, lỗi ở ô `reason`.
 *
 * **Thứ tự khoá:** `matters` → đầu mục (`OpensChecklistItem`) → các dòng `documents` đang rút.
 */
class KeepAcceptedChecklistVersion
{
    use OpensChecklistItem;

    public function handle(MatterChecklistItem $checklistItem, User $actor, string $reason): MatterChecklistItem
    {
        $reason = $this->validatedReason($reason);
        $matterId = $this->checklistItemMatterId($checklistItem);

        return DB::transaction(function () use ($checklistItem, $actor, $reason, $matterId): MatterChecklistItem {
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor, $matterId);

            if (! self::isAvailable($fresh)) {
                throw DocumentCorrectionRefused::noAcceptedVersionToKeep();
            }

            /** @var Collection<int, Document> $newer */
            $newer = self::liveClientProvided($fresh)
                ->where('version', '>', $fresh->accepted_version)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $newer->each(function (Document $document) use ($actor, $reason, $matter): void {
                $clientDownloads = DocumentDownload::query()
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->where('document_id', $document->getKey())
                    ->where('downloader_type', (new ClientUser)->getMorphClass())
                    ->count();

                $document->update([
                    'status' => DocumentStatus::Retracted,
                    'client_can_view' => false,
                    'client_can_download' => false,
                    'retracted_at' => now(),
                    'retracted_by' => $actor->getKey(),
                    'retraction_reason' => $reason,
                ]);

                Audit::record('document_retracted', $document, [
                    'matter_id' => $matter->getKey(),
                    'client_id' => $matter->client_id,
                    'group' => $document->group->value,
                    'version' => $document->version,
                    'client_downloads' => $clientDownloads,
                ], $actor);
            });

            $previousStatus = $fresh->status;

            $fresh->update([
                'status' => ChecklistItemStatus::Accepted,
                'rejection_reason' => null,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            Audit::record('checklist_item_kept_accepted_version', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'previous_status' => $previousStatus->value,
                'accepted_version' => $fresh->accepted_version,
                'retracted_document_ids' => $newer->modelKeys(),
            ], $actor);

            return $fresh;
        });
    }

    /**
     * "Từ chối bản mới, giữ bản đã nhận" có làm được trên `$item` không — xem docblock lớp, mục "Khi nào
     * làm được". Một định nghĩa cho Action (đọc trên bản vừa khoá) và cho nút trên tab Danh mục.
     */
    public static function isAvailable(MatterChecklistItem $item): bool
    {
        if ($item->status !== ChecklistItemStatus::PendingReview || $item->accepted_version === null) {
            return false;
        }

        return self::liveClientProvided($item)->where('version', '>', $item->accepted_version)->exists()
            && self::acceptedVersionStillReleased($item);
    }

    /**
     * Bản văn phòng đã nhận (`accepted_version`) còn trên cổng khách không — còn ít nhất một tài liệu nhóm
     * A của version đó `published` + cờ xem, chưa rút, chưa xoá. Dùng chung với `RetractDocument` (A7):
     * rút bản gửi thêm thì đầu mục chỉ trở lại "Đã nhận" khi bản đã nhận còn đứng đó.
     */
    public static function acceptedVersionStillReleased(MatterChecklistItem $item): bool
    {
        return $item->accepted_version !== null
            && self::liveClientProvided($item)
                ->where('version', $item->accepted_version)
                ->where('status', DocumentStatus::Published->value)
                ->where('client_can_view', true)
                ->exists();
    }

    /** @return Builder<Document> tài liệu nhóm A chưa rút, chưa xoá mềm của đầu mục. */
    private static function liveClientProvided(MatterChecklistItem $item): Builder
    {
        return Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $item->matter_id)
            ->where('matter_checklist_item_id', $item->getKey())
            ->where('group', DocumentGroup::ClientProvided->value)
            ->where('status', '!=', DocumentStatus::Retracted->value);
    }

    private function validatedReason(string $reason): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $reason) ?? '';
        $length = mb_strlen($trimmed);

        if ($length < RetractDocument::REASON_MIN) {
            throw ValidationException::withMessages(['reason' => [__('retraction.validation.reason_min', ['min' => RetractDocument::REASON_MIN])]]);
        }

        if ($length > RetractDocument::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => [__('retraction.validation.reason_max', ['max' => RetractDocument::REASON_MAX])]]);
        }

        return $trimmed;
    }
}
