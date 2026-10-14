<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\DocumentGroup;
use App\Exceptions\DocumentCorrectionRefused;
use App\Exceptions\MatterChecklistReadOnly;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A4) — SỬA tiêu đề, ngày ban hành và đầu mục danh mục của
 * một tài liệu đã có. Trước Action này không có đường nào sửa ba cột đó sau lúc tạo
 * (`UploadStaffDocument`, `SubmitClientDocument`): một bản án công bố với ngày ban hành gõ sai chỉ còn
 * cách rút lại (khách thấy dòng "Văn phòng đã rút lại tài liệu này" mãi mãi) rồi tải lên lại — và mục
 * lục `MUC-LUC.pdf` của gói bàn giao in tiêu đề và ngày từ chính các cột này. Không đổi tệp, nhóm, trạng
 * thái hay hai cờ khách (đó là việc của `RegroupDocument`, `PublishDocument`, `RetractDocument`).
 *
 * **Quyền.** `DocumentPolicy::update` (làm hồ sơ — trợ lý trong đội cũng sửa được một bản nháp); với
 * tài liệu ĐANG ra tới khách (`Document::isReleasedToPortal()`) thì thêm `DocumentPolicy::publish`
 * (`document.publish`): sửa thứ khách đang đọc là quyết định về điều văn phòng nói với khách.
 *
 * **Đầu mục.** Chỉ chuyển được tài liệu nhóm B, C, D sang đầu mục khác (hay bỏ gắn): với chúng đầu mục
 * chỉ để tra cứu, không đổi trạng thái đầu mục nào. Tài liệu nhóm A thì không — nó nằm trong CHUỖI NỘP
 * của đầu mục (số `version` tính theo đầu mục, `StoresDocumentFile::nextInSubmissionChain()`) và đã làm
 * đổi trạng thái đầu mục đó; chuyển nó đi là để lại hai chuỗi sai số. Lỗi gắn vào ô đầu mục, chỉ đường
 * rút lại/xoá rồi tải lên lại. Đầu mục đích phải thuộc đúng hồ sơ và chưa bị gỡ; hồ sơ đã kết thúc thì
 * danh mục chỉ đọc (`MatterChecklistReadOnly`, như `UploadStaffDocument`).
 *
 * **Thứ tự** (như `RetractDocument`): `matter_id` đọc TRƯỚC transaction bằng một câu riêng; câu đầu tiên
 * của transaction khoá dòng `matters`, rồi khoá dòng `documents`, rồi (nếu đổi đầu mục) dòng đầu mục.
 * Mọi quyết định đọc từ bản vừa khoá. Người thực hiện đọc lại từ CSDL (tài khoản vừa bị vô hiệu hoá không
 * sửa được bằng đối tượng cũ).
 *
 * Không đổi gì thì không ghi gì. Có đổi: một dòng `document_details_updated` mang `changes` — từng ô đã
 * đổi với giá trị cũ → mới (ngày ở dạng `Y-m-d`). Không thư, không thông báo.
 */
class UpdateDocumentDetails
{
    use ReadsWithoutPortalScope;

    /** Đúng độ rộng cột `documents.title` — cùng con số `UploadStaffDocument`. */
    public const MAX_TITLE_LENGTH = 250;

    public function handle(
        Document $document,
        User $actor,
        string $title,
        DateTimeInterface|string|null $issuedAt,
        ?int $checklistItemId,
    ): Document {
        $title = $this->validatedTitle($title);
        $issued = $this->parseIssuedAt($issuedAt)?->toDateString();

        // TRƯỚC transaction, không bên trong — cùng lý do `RetractDocument` bước 0 (rà soát cuối M7, I1).
        $matterId = $this->scopelessly(Document::query())
            ->withTrashed()
            ->whereKey($document->getKey())
            ->value('matter_id');

        return DB::transaction(function () use ($document, $actor, $title, $issued, $checklistItemId, $matterId): Document {
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

            Gate::forUser($freshActor)->authorize('update', $fresh);

            if ($fresh->isReleasedToPortal()) {
                Gate::forUser($freshActor)->authorize('publish', $fresh);
            }

            if ($checklistItemId !== $fresh->matter_checklist_item_id) {
                $this->guardChecklistItemChange($fresh, $matter, $checklistItemId);
            }

            $before = [
                'title' => $fresh->title,
                'issued_at' => $fresh->issued_at?->toDateString(),
                'matter_checklist_item_id' => $fresh->matter_checklist_item_id,
            ];
            $after = ['title' => $title, 'issued_at' => $issued, 'matter_checklist_item_id' => $checklistItemId];

            $changes = collect($after)
                ->filter(fn (mixed $value, string $field): bool => $value !== $before[$field])
                ->map(fn (mixed $value, string $field): array => ['from' => $before[$field], 'to' => $value])
                ->all();

            if ($changes === []) {
                return $fresh;
            }

            $fresh->update($after);

            Audit::record('document_details_updated', $fresh, [
                'matter_id' => $matter->getKey(),
                'changes' => $changes,
            ], $freshActor);

            return $fresh;
        });
    }

    /** Đầu mục đích — xem docblock lớp, mục "Đầu mục". Gọi dưới khoá `matters` + `documents`. */
    private function guardChecklistItemChange(Document $document, Matter $matter, ?int $checklistItemId): void
    {
        if ($document->group === DocumentGroup::ClientProvided) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('document_corrections.edit.errors.checklist_item_chain')],
            ]);
        }

        if ($matter->isClosed()) {
            throw MatterChecklistReadOnly::make();
        }

        if ($checklistItemId === null) {
            return;
        }

        $item = $this->scopelessly(MatterChecklistItem::query())->lockForUpdate()->find($checklistItemId);

        if ($item === null) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('documents.upload.checklist_item_deleted')],
            ]);
        }

        if ((int) $item->matter_id !== (int) $matter->getKey()) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('document_corrections.edit.errors.checklist_item_other_matter')],
            ]);
        }
    }

    /** Gỡ khoảng trắng Unicode hai đầu (cùng cách `AddChecklistItem`); rỗng hay quá cột → lỗi ở ô. */
    private function validatedTitle(string $title): string
    {
        $title = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $title) ?? '';

        if ($title === '') {
            throw ValidationException::withMessages(['title' => [__('document_corrections.edit.errors.title_required')]]);
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw ValidationException::withMessages(['title' => [__('document_corrections.edit.errors.title_too_long', ['max' => self::MAX_TITLE_LENGTH])]]);
        }

        return $title;
    }

    /** Cùng luật `UploadStaffDocument::parseIssuedAt()`: chuỗi rỗng là "không có ngày", chuỗi hỏng là lỗi ở ô. */
    private function parseIssuedAt(DateTimeInterface|string|null $issuedAt): ?CarbonInterface
    {
        if ($issuedAt instanceof DateTimeInterface) {
            return Carbon::instance($issuedAt);
        }

        if ($issuedAt === null || trim($issuedAt) === '') {
            return null;
        }

        try {
            return Carbon::parse($issuedAt);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages(['issued_at' => [__('documents.upload.issued_at_invalid')]]);
        }
    }
}
