<?php

namespace App\Actions\Document;

use App\Enums\ChecklistItemStatus;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Policies\MatterChecklistItemPolicy;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Thêm MỘT đầu mục riêng vào danh mục hồ sơ của MỘT vụ việc cụ thể — không đụng tới mẫu
 * (`ChecklistTemplate`) và không đụng tới vụ việc nào khác (M6.5 Task 15, SPEC §4.10, §7.4).
 *
 * # Vì sao Action này tồn tại: đường DUY NHẤT ghi `matter_checklist_items` là lúc mở vụ
 *
 * Trước task này, chỗ DUY NHẤT trong `app/` ghi một dòng MỚI vào `matter_checklist_items` là
 * {@see ApplyChecklistTemplate}, và nó chỉ được gọi một lần, lúc `OpenMatter` — không có Action
 * hay màn hình nào thêm được một đầu mục cho một vụ việc ĐÃ MỞ. Hậu quả kép (finding
 * `intake-02`/`checklist-02`/`roles-06`/`spec-gap-04`, critical): (1) ba loại vụ việc không có
 * mẫu nào (Hình sự, Doanh nghiệp, Lao động trên dữ liệu dev) mở ra với 0 đầu mục VĨNH VIỄN, và
 * khách của các vụ đó không có cách nào nộp giấy tờ qua cổng — `SubmitClientDocument` đòi một
 * đầu mục danh mục có thật; (2) ngay cả một vụ CÓ mẫu, văn phòng cũng không xin được một giấy tờ
 * cụ thể ngoài danh sách mẫu (ví dụ một giấy toà vừa đòi thêm) mà không sửa mã seeder. Action này
 * là đường ghi thứ hai, và là đường DUY NHẤT có một màn hình đứng trước nó
 * ({@see ChecklistRelationManager}).
 *
 * # Quyền: `matter.update` thường, trợ lý CŨNG được — không phải `manageTeam`
 *
 * Task brief nói thẳng: "Người có `matter.update` trên vụ thêm được đầu mục riêng cho vụ đó. Trợ
 * lý cũng được, vì đây là việc xin giấy tờ, không phải việc công bố." R5 chỉ nâng
 * ngưỡng lên `matter.update` VÀ KHÔNG PHẢI trợ lý cho ba việc: đổi `confidentiality`, quản lý đội
 * ngũ, và bàn giao — tức các quyết định về AI ĐƯỢC ĐỨNG TRONG vụ việc và về CẤU TRÚC của nó.
 * Thêm một đầu mục giấy tờ không nằm trong ba việc đó và cũng không phải một công tắc công bố
 * (thứ đòi `stageLog.publish`, xem R5) — nó chỉ mở rộng danh sách giấy tờ văn phòng đang XIN
 * khách, đúng việc một trợ lý vẫn làm hằng ngày qua điện thoại hay email. Vì vậy cổng ở đây uỷ
 * thẳng cho {@see MatterChecklistItemPolicy::create()}, và chính nó uỷ tiếp cho
 * `MatterPolicy::update()` — "điều kiện chưa xoá mềm, có `matter.update`, thấy được vụ việc chỉ
 * tồn tại MỘT chỗ" (cùng lý lẽ với `DocumentPolicy::create()`), và `Role::Assistant->permissions()`
 * đã có sẵn `MatterUpdate` nên trợ lý qua được đúng như brief yêu cầu, không cần một nhánh riêng.
 *
 * # Tên đầu mục là duy nhất trong PHẠM VI MỘT VỤ VIỆC, kể cả với đầu mục đã xoá mềm
 *
 * Cùng luật mà {@see ApplyChecklistTemplate} đã dùng để không tạo trùng khi áp lại cùng một mẫu:
 * "item đã có (theo tên) được giữ nguyên", tính CẢ các dòng đã xoá mềm (`withTrashed()`). Đầu
 * mục ở đây được thêm TAY từng cái một — không phải một lượt sao chép hàng loạt như
 * `ApplyChecklistTemplate` — nên một cái tên trùng không lặng lẽ bị bỏ qua (làm caller tưởng đã
 * thêm mà thật ra không có gì mới), nó bị TỪ CHỐI với một câu rõ ràng trên đúng ô `name`. Tính cả
 * hàng đã xoá mềm giữ cho bất biến "tên đầu mục là duy nhất trong một vụ việc" đúng bất kể đầu
 * mục đó được sinh ra từ mẫu hay được thêm tay, và bất kể còn sống hay đã gỡ — một khoá ngoại của
 * `documents.matter_checklist_item_id` vẫn trỏ vào dòng đã gỡ đó (xem `UploadStaffDocument`), nên
 * hai dòng cùng tên trên cùng một vụ là một điều dễ đọc nhầm với người tra cứu về sau.
 */
class AddChecklistItem
{
    public function handle(
        Matter $matter,
        User $actor,
        string $name,
        ?string $description,
        bool $isRequired,
    ): MatterChecklistItem {
        Gate::forUser($actor)->authorize('create', [MatterChecklistItem::class, $matter]);

        return DB::transaction(function () use ($matter, $actor, $name, $description, $isRequired): MatterChecklistItem {
            // Khoá dòng vụ việc trước khi hỏi lại tên có trùng không — cùng lý do
            // `AddTeamMember` khoá trước khi hỏi "đã trong đội ngũ chưa": không có khoá, hai lần
            // thêm gần như đồng thời cùng một tên đều đọc "chưa trùng" trước khi lần nào kịp ghi,
            // và ra hai dòng trùng tên thay vì một lời từ chối tiếng Việt.
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            $duplicateExists = $locked->checklistItems()->withTrashed()->where('name', $name)->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'name' => [__('actions.add_checklist_item.duplicate_name')],
                ]);
            }

            // Nối vào CUỐI danh mục hiện có — đầu mục thêm tay không có ý kiến gì về thứ tự các
            // đầu mục sinh ra từ mẫu, nó chỉ cần đứng SAU chúng trên màn hình.
            $nextSortOrder = ((int) $locked->checklistItems()->max('sort_order')) + 1;

            $item = $locked->checklistItems()->create([
                'name' => $name,
                'description' => $description,
                'is_required' => $isRequired,
                'sort_order' => $nextSortOrder,
                'status' => ChecklistItemStatus::Missing,
            ]);

            // Bên TRONG transaction, cùng lý do với các Action Task 15 khác chép lại từ M4/M6.5:
            // ngoài transaction thì có một khoảng mà dòng đã ghi còn nhật ký thì chưa.
            Audit::record('checklist_item_added', $item, [
                'matter_id' => $locked->id,
                'name' => $name,
                'is_required' => $isRequired,
            ], $actor);

            return $item;
        });
    }
}
