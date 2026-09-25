<?php

namespace App\Actions\Matter;

use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bàn giao MỘT vụ việc sang một luật sư phụ trách mới (SPEC §6.11 bước 1-5; M6.5 Task 4, R7, kéo
 * lên từ M7 Task 1 và M7 R6). Năm bước, tất cả trong MỘT transaction:
 *
 *  1. Đổi `lead_lawyer_id`, cập nhật `matter_user` (lead mới vào đội với vai `lead`; lead cũ ở lại
 *     với vai `associate` hoặc bị gỡ hẳn, theo lựa chọn trên form).
 *  2. Tạo MỘT dòng `stage_logs` NỘI BỘ (`from_stage === to_stage === $matter->stage` — không đổi
 *     giai đoạn — `is_published = false`) ghi ai bàn giao cho ai và lý do.
 *  3. Chuyển mọi `deadlines` CHƯA HOÀN THÀNH của lead cũ trên CHÍNH vụ việc này sang lead mới.
 *  4. Chuyển mọi `client_requests` CHƯA ĐÓNG mà lead cũ đang được giao, trên CHÍNH vụ việc này.
 *  5. Ghi audit `matter_reassigned`.
 *
 * **Bước 3 cố ý lệch với câu "toàn bộ deadlines" của SPEC §6.11.** Chỉ mốc hạn CHƯA HOÀN THÀNH
 * mới chuyển — một mốc đã xong không còn "việc" nào để bàn giao, và chuyển nó đi chỉ làm sai lệch
 * "ai đã thật sự hoàn thành mốc này". Đây là phán quyết của brief Task 4, và M7 R10 ghi lại chính
 * xác sự lệch này vào SPEC — không phải một chỗ bỏ sót ở đây.
 *
 * # Deferred (M6.5 → M6/M7)
 *
 * - **Thư tổng hợp mốc hạn cho lead mới** (SPEC §6.11 bước 3, "gửi email tổng hợp danh sách mốc
 *   hạn cho người nhận") phải đi qua hàng đợi, sau khi commit (R2) — hạ tầng thư xếp hàng đó là
 *   việc của M6.5 Task 11, CHƯA merge lúc Task 4 chạy. Action này KHÔNG gửi thư nào. M7 Task 1
 *   dựng lại đúng bước này trên hạ tầng thư đã có (kế hoạch M7, R6 và Task 1 đã ghi rõ).
 * - **"Gợi ý soạn dòng cập nhật giới thiệu luật sư mới"** (SPEC §6.11 bước 4) chỉ là MỘT gợi ý
 *   hiện trên giao diện sau khi bàn giao xong (`Filament\Notifications\Notification`, không tự
 *   gửi) — Action này KHÔNG BAO GIỜ tự tạo hay công bố một `stage_logs` công khai giới thiệu lead
 *   mới. Xem `ViewMatter::reassignAction()`.
 *
 * # Vai `lead` KHÔNG đi qua `AddTeamMember`/`RemoveTeamMember` (R6)
 *
 * Đây là Action DUY NHẤT đổi `lead_lawyer_id`. `RemoveTeamMember` từ chối gỡ một `lead` (Task 3,
 * finding S3); `AddTeamMember` từ chối gán vai `lead`. Action này KHÔNG gọi lại hai Action đó — nó
 * tự thao tác `matter_user` trực tiếp, vì "bàn giao" không phải "gỡ rồi thêm": hai bước tách rời
 * sẽ để lọt một khoảnh khắc vụ việc KHÔNG có lead nào, đúng khe hở mà `OpenWork`/
 * `Matter::isListableBy()` (vụ `restricted`, nhánh chỉ đọc `lead_lawyer_id`) không được thiết kế
 * để sống sót.
 *
 * # Cổng: cùng `manageTeam`, không phải một cổng riêng (R5)
 *
 * R5 nhóm "quản lý đội ngũ và bàn giao" lại làm MỘT câu ("đòi `matter.update` VÀ không phải trợ
 * lý"): `MatterPolicy::manageTeam()` đã đúng hình dạng đó (lead của CHÍNH vụ việc này, manager
 * được xem vụ, hoặc admin — trừ trợ lý). Không viết một `reassign()` riêng chỉ để lặp lại đúng năm
 * dòng đó.
 *
 * # Lead cũ ở lại làm `associate`, hay bị gỡ — và vụ `restricted` xử khác
 *
 * Lựa chọn trên form (`$keepOldLeadAsAssociate`). Với vụ `restricted`, `Matter::isListableBy()`
 * nhánh đó KHÔNG đọc `team()` chút nào (chỉ admin hoặc chính `lead_lawyer_id`) — nên một lead cũ ở
 * lại với vai `associate` sẽ KHÔNG BAO GIỜ thấy lại được vụ việc họ vừa bàn giao, đúng cái bẫy
 * "thành viên vô hình" mà `AddTeamMember` (Task 3, finding I1) đã đóng cho đường THÊM. Ở đây khoá
 * lại bằng đúng công thức đó: hỏi `Gate::forUser($oldLead)->allows('view', $locked)` SAU khi
 * `lead_lawyer_id` đã đổi (không phải trước — hỏi trước luôn trả lời theo lead CŨ, luôn đúng, và
 * không đo được gì thật). Câu hỏi này chỉ chạy khi form đã chọn "giữ lại"; nếu sai (vụ
 * `restricted`, lead cũ không phải admin) thì `ValidationException` ném NGAY TRONG transaction —
 * Laravel tự rollback toàn bộ bước 1-4 đã chạy, không để lại một bàn giao nửa vời.
 * `ViewMatter::reassignAction()` còn ẩn hẳn ô "giữ lại" khi vụ việc đang `restricted`, nên đường
 * vào thật KHÔNG BAO GIỜ chạm nhánh này — nó là lưới an toàn cho một lời gọi bỏ qua form.
 *
 * # Khoá dòng vụ việc, đúng thứ tự Task 3 để lại (fix round 3 lesson)
 *
 * Câu ĐẦU TIÊN bên trong `DB::transaction` là `lockForUpdate()` trên `matters` — không câu đọc
 * trần nào đứng trước nó bên trong transaction. `Gate::forUser($actor)->authorize('manageTeam',
 * $matter)` và hai kiểm tra không đụng CSDL (`$reason` rỗng, `$newLead` còn hoạt động — cả hai chỉ
 * đọc thuộc tính đã nạp sẵn trên đối tượng, không phát sinh câu SELECT nào) chạy TRƯỚC khi mở
 * transaction, cùng thành ngữ {@see RemoveTeamMember}, {@see AddTeamMember}. `$oldLead` phải tra
 * lại DƯỚI khoá (không tin `$matter->lead_lawyer_id` của đối tượng caller đưa vào, có thể cũ) —
 * `withTrashed()` vì một lead cũ đã bị xoá mềm (qua một đường khác, trước khi luật này tồn tại)
 * vẫn cần tên thật cho dòng `stage_logs`/audit, không phải `null`.
 */
class ReassignMatter
{
    /**
     * @throws ValidationException
     */
    public function handle(
        Matter $matter,
        User $actor,
        User $newLead,
        string $reason,
        bool $keepOldLeadAsAssociate,
    ): StageLog {
        Gate::forUser($actor)->authorize('manageTeam', $matter);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => [__('reassign.validation.reason_required')],
            ]);
        }

        if (! $newLead->is_active || $newLead->trashed()) {
            throw ValidationException::withMessages([
                'new_lead_id' => [__('reassign.validation.new_lead_inactive')],
            ]);
        }

        return DB::transaction(function () use ($matter, $actor, $newLead, $reason, $keepOldLeadAsAssociate): StageLog {
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            $oldLead = User::query()->withTrashed()->find($locked->lead_lawyer_id);

            if ($oldLead === null || $newLead->is($oldLead)) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('reassign.validation.same_lead')],
                ]);
            }

            // Bước 1: lead mới vào đội với vai `lead` — `syncWithoutDetaching` (upsert) chứ không
            // `attach()`: một lời gọi lặp/đồng thời sẽ để lộ UNIQUE constraint thô nếu lead mới
            // vô tình đã có mặt trong đội với vai khác (associate/assistant/observer).
            $locked->team()->syncWithoutDetaching([
                $newLead->getKey() => ['role_in_matter' => MatterRole::Lead->value],
            ]);

            $locked->lead_lawyer_id = $newLead->getKey();
            $locked->blameOn($actor)->save();

            $oldLeadKeptAsAssociate = false;

            if ($keepOldLeadAsAssociate) {
                if (! Gate::forUser($oldLead)->allows('view', $locked)) {
                    throw ValidationException::withMessages([
                        'keep_old_lead_as_associate' => [__('reassign.validation.old_lead_would_not_see_matter')],
                    ]);
                }

                $locked->team()->updateExistingPivot($oldLead->getKey(), [
                    'role_in_matter' => MatterRole::Associate->value,
                ]);

                $oldLeadKeptAsAssociate = true;
            } else {
                $locked->team()->detach($oldLead->getKey());
            }

            // Bước 2: dòng stage_logs nội bộ — không đổi giai đoạn, không công bố cho khách theo
            // mặc định (SPEC §6.11 bước 2).
            $stageLog = new StageLog([
                'matter_id' => $locked->id,
                'from_stage' => $locked->stage,
                'to_stage' => $locked->stage,
                'occurred_at' => now(),
                'internal_note' => __('reassign.stage_log.internal_note', [
                    'from' => $oldLead->name,
                    'to' => $newLead->name,
                    'reason' => $reason,
                ]),
                'is_published' => false,
            ]);
            $stageLog->blameOn($actor)->save();

            // Bước 3: chỉ deadline CHƯA HOÀN THÀNH (xem docblock lớp — lệch có chủ đích với SPEC).
            $movedDeadlineIds = Deadline::query()
                ->where('matter_id', $locked->id)
                ->where('responsible_user_id', $oldLead->id)
                ->where('is_completed', false)
                ->pluck('id');

            if ($movedDeadlineIds->isNotEmpty()) {
                Deadline::query()->whereKey($movedDeadlineIds)->update(['responsible_user_id' => $newLead->id]);
            }

            // Bước 4: chỉ client_requests CHƯA ĐÓNG.
            $movedRequestIds = ClientRequest::query()
                ->where('matter_id', $locked->id)
                ->where('assigned_to', $oldLead->id)
                ->where('status', '!=', ClientRequestStatus::Closed->value)
                ->pluck('id');

            if ($movedRequestIds->isNotEmpty()) {
                ClientRequest::query()->whereKey($movedRequestIds)->update(['assigned_to' => $newLead->id]);
            }

            // Bước 5.
            Audit::record('matter_reassigned', $locked, [
                'from_user_id' => $oldLead->id,
                'to_user_id' => $newLead->id,
                'reason' => $reason,
                'old_lead_kept_as_associate' => $oldLeadKeptAsAssociate,
                'deadlines_moved' => $movedDeadlineIds->count(),
                'client_requests_moved' => $movedRequestIds->count(),
                'stage_log_id' => $stageLog->id,
            ], $actor);

            return $stageLog;
        });
    }
}
