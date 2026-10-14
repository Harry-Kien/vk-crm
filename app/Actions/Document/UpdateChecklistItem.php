<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Models\Contract;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A5) — SỬA tên, mô tả và cờ "bắt buộc" của MỘT đầu mục
 * danh mục, cho riêng hồ sơ này (mẫu danh mục không đổi — SPEC §4.10: đầu mục là bản sao). Trước Action
 * này chỉ thêm được (`AddChecklistItem`): một đầu mục mẫu "không bắt buộc" không thành "bắt buộc" được
 * cho riêng vụ cần nó, và một tên gõ sai nằm mãi trên cổng khách (thêm lại cùng tên thì bị từ chối vì
 * trùng).
 *
 * Mở đầu mục qua {@see OpensChecklistItem} (khoá `matters` trước; quyền `MatterChecklistItemPolicy::update`
 * = `review` = `checklist.review`; hồ sơ đã kết thúc thì chỉ đọc). Tên: gỡ khoảng trắng Unicode hai đầu,
 * bắt buộc, ≤ {@see self::MAX_NAME_LENGTH} (cột), không trùng tên một đầu mục KHÁC của cùng hồ sơ — kể
 * cả đầu mục đã gỡ, cùng luật `AddChecklistItem`. Mô tả rỗng là null; dài hơn
 * {@see MatterChecklistItem::DESCRIPTION_MAX_LENGTH} ký tự thì từ chối trên ô `description` (cột `text`
 * 65.535 byte — sửa sau rà soát cuối, F3: trước đó MariaDB strict trả 500), kiểm trước khi mở transaction.
 *
 * Không đổi gì thì không ghi gì. Có đổi: dòng `checklist_item_updated` mang `changes` — từng ô đã đổi,
 * cũ → mới (khách đã đọc được cả hai, nên chép vào nhật ký không làm lộ gì thêm). Đổi "bắt buộc" có
 * hiệu lực ngay với thanh tiến độ và thư nhắc (`ChecklistProgress` đọc cột này).
 */
class UpdateChecklistItem
{
    use OpensChecklistItem;

    public const MAX_NAME_LENGTH = 200;

    public function handle(MatterChecklistItem $checklistItem, User $actor, string $name, ?string $description, bool $isRequired): MatterChecklistItem
    {
        $name = preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $name) ?? '';
        $description = $description === null ? null : (preg_replace('/^[\s\p{Z}\x{200B}]+|[\s\p{Z}\x{200B}]+$/u', '', $description) ?? '');
        $description = $description === '' ? null : $description;

        if ($name === '') {
            throw ValidationException::withMessages(['name' => [__('actions.add_checklist_item.name_required')]]);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages(['name' => [__('document_corrections.edit_item.name_too_long', ['max' => self::MAX_NAME_LENGTH])]]);
        }

        if ($description !== null && mb_strlen($description) > MatterChecklistItem::DESCRIPTION_MAX_LENGTH) {
            throw ValidationException::withMessages(['description' => [__('document_corrections.description_too_long', ['max' => MatterChecklistItem::DESCRIPTION_MAX_LENGTH])]]);
        }

        $matterId = $this->checklistItemMatterId($checklistItem);

        return DB::transaction(function () use ($checklistItem, $actor, $name, $description, $isRequired, $matterId): MatterChecklistItem {
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor, $matterId);

            $duplicate = MatterChecklistItem::query()
                ->withoutGlobalScopes()
                ->where('matter_id', $fresh->matter_id)
                ->whereKeyNot($fresh->getKey())
                ->where('name', $name)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => [__('actions.add_checklist_item.duplicate_name')]]);
            }

            // Đầu mục hợp đồng dịch vụ đã ký được nhận ra CHỈ bằng đúng tên (Contract::SIGNED_CONTRACT_CHECKLIST_ITEM_NAME)
            // — luật thư nhắc khách (ChecklistProgress::itemsToRemindClientOf: không đòi hợp đồng khi hợp đồng hiện
            // hành còn nháp) và lời nhắc "chưa có bản ký" của tab tiền đều so tên. Đổi tên nó lặng lẽ tắt cả hai;
            // đặt tên mục khác trùng tên đó thì mục kia bị coi là hợp đồng. Nên tên đó không đổi đi, không đổi tới
            // (rà soát gộp làn fa, 2026-10-14). Mô tả và "bắt buộc" vẫn sửa được.
            $contractName = Contract::SIGNED_CONTRACT_CHECKLIST_ITEM_NAME;

            if (($fresh->name === $contractName) !== ($name === $contractName)) {
                throw ValidationException::withMessages(['name' => [__('document_corrections.edit_item.contract_item_name_locked')]]);
            }

            $before = ['name' => $fresh->name, 'description' => $fresh->description, 'is_required' => (bool) $fresh->is_required];
            $after = ['name' => $name, 'description' => $description, 'is_required' => $isRequired];

            $changes = collect($after)
                ->filter(fn (mixed $value, string $field): bool => $value !== $before[$field])
                ->map(fn (mixed $value, string $field): array => ['from' => $before[$field], 'to' => $value])
                ->all();

            if ($changes === []) {
                return $fresh;
            }

            $fresh->update($after);

            Audit::record('checklist_item_updated', $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'changes' => $changes,
            ], $actor);

            return $fresh;
        });
    }
}
