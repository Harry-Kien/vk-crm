<?php

namespace App\Actions\Matter;

use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
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
 *
 * # Gợi ý giới thiệu luật sư mới cho khách (SPEC §6.11 bước 4) — ĐÃ LÀM, KHÔNG nằm trong Action này
 *
 * Fix round 1 (spec gap): bản Task 4 gốc để bước này trong danh sách "Deferred" ở trên, nhưng nó
 * không thật sự bị hoãn — chỉ SAI CHỖ. Đã dựng ở `ViewMatter::reassignAction()` (không phải ở
 * đây): sau một lần bàn giao thành công trên một vụ việc ĐÃ công bố portal
 * (`is_published_to_portal`), trang hiện một `Filament\Notifications\Notification` GỢI Ý soạn một
 * dòng cập nhật giới thiệu lead mới — không tự soạn, không tự công bố, không đọc trạng thái công
 * bố nào của `stage_logs`. `ReassignMatter::handle()` (Action này) không biết gì về gợi ý đó và
 * không nên biết: nó chỉ đổi lead, không quyết định thứ gì hiện ra trên màn hình sau đó.
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
 * trần nào đứng trước nó bên trong transaction, TRÊN CHÍNH bảng `matters` (hay bất kỳ hàng nào
 * đang bị khoá dưới đây). `Gate::forUser($actor)->authorize('manageTeam', $matter)` và ba kiểm tra
 * TRƯỚC transaction (`$reason` rỗng, `$newLead` còn hoạt động, `$newLead` giữ đúng vai) chạy TRƯỚC
 * khi mở transaction, cùng thành ngữ {@see RemoveTeamMember}, {@see AddTeamMember}.
 *
 * **Sửa lại (fix round 3): câu "không tự phát sinh câu SELECT MỚI nào" ở trên từng SAI cho điều
 * kiện thứ ba.** Hai kiểm tra đầu (`$reason` rỗng, `$newLead->is_active`/`trashed()`) chỉ đọc thuộc
 * tính đã nạp sẵn, đúng là không SELECT. Nhưng `$newLead->hasRole(...)` (điều kiện thứ ba) LÀ một
 * quan hệ Eloquent (`roles`, qua spatie/laravel-permission) — nếu nó CHƯA được nạp sẵn trên đối
 * tượng `$newLead` (ví dụ bởi màn hình gọi vào), lần gọi `hasRole()` ĐẦU TIÊN này TỰ PHÁT SINH một
 * câu SELECT (lazy load), y hệt cách I3 residual ngay dưới đây khai thác để đóng khe hở "vai đổi
 * giữa lúc màn hình dựng danh sách và lúc lượt bàn giao giành được khoá". Điều đó KHÔNG vi phạm kỷ
 * luật "khoá là câu đầu tiên" — câu SELECT đó chạm bảng `roles`/`model_has_roles`, không phải
 * `matters` hay bất kỳ hàng nào sẽ bị `lockForUpdate()` khoá, nên không có gì để làm bẩn snapshot
 * REPEATABLE READ của các khoá dưới đây cả; nó chỉ SAI ở chỗ tự nhận "không SELECT nào", không sai
 * ở chỗ an toàn giao dịch.
 *
 * `$oldLead` phải tra lại DƯỚI khoá (không tin `$matter->lead_lawyer_id` của đối tượng caller đưa
 * vào, có thể cũ) — `withTrashed()` vì một lead cũ đã bị xoá mềm (qua một đường khác, trước khi
 * luật này tồn tại) vẫn cần tên thật cho dòng `stage_logs`/audit, không phải `null`.
 *
 * **I3 residual (fix round 2) — vai cũng được hỏi lại trên `$lockedNewLead`, không chỉ
 * `is_active`/`trashed()`.** Bản round 1 chỉ khoá lại is_active/trashed; nếu đối tượng `$newLead`
 * caller đưa vào đã CACHE quan hệ `roles` từ một lần chạm trước đó (ví dụ
 * `ViewMatter::reassignCandidateOptions()` vừa lọc ô chọn bằng `hasRole()`), một lần đổi chức danh
 * xảy ra GIỮA lúc màn hình đó dựng danh sách và lúc lượt bàn giao này giành được khoá sẽ không bị
 * câu kiểm tra TRƯỚC transaction bắt được (đọc lại bản cache cũ, không tự query). Hỏi lại đúng câu
 * đó trên `$lockedNewLead` (một đối tượng MỚI, tự query `roles` riêng, không chia sẻ cache với
 * `$newLead`) đóng khe hở đó — mutation probe xác nhận: một test mô phỏng đúng cache cũ (gọi
 * `hasRole()` một lần trên `$newLead` TRƯỚC khi đổi vai qua một đối tượng khác) đỏ nếu thiếu câu
 * hỏi lại này (xem báo cáo).
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

        // I3 (fix round 1): chỉ Lawyer/Manager "đứng tên phụ trách" được — cùng tập vai
        // AddTeamMember::eligibleForRole() chấp nhận cho `associate`. `hasRole()` CÓ THỂ tự phát
        // sinh một câu SELECT (lazy load quan hệ `roles`, nếu chưa nạp sẵn — sửa lại claim SAI ở
        // fix round 3: bảng đó là bảng vai trò riêng của spatie/laravel-permission, KHÔNG phải
        // `matters`/`users`, nên câu SELECT đó (nếu có) không làm bẩn snapshot REPEATABLE READ của
        // các khoá dưới đây — xem docblock lớp, mục khoá dòng vụ việc, cho lý lẽ đầy đủ).
        if (! ($newLead->hasRole(Role::Lawyer->value) || $newLead->hasRole(Role::Manager->value))) {
            throw ValidationException::withMessages([
                'new_lead_id' => [__('reassign.validation.new_lead_not_eligible')],
            ]);
        }

        return DB::transaction(function () use ($matter, $actor, $newLead, $reason, $keepOldLeadAsAssociate): StageLog {
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            // I2 (fix round 1): khoá dòng lead mới NGAY SAU dòng vụ việc — cùng thứ tự toàn cục
            // "vụ việc trước, bảng con sau" — rồi đọc lại `is_active`/`trashed()` DƯỚI KHOÁ. Câu
            // kiểm tra TRƯỚC transaction (ngay trên) chỉ đọc đối tượng caller đưa vào, có thể đã
            // cũ (form mở ra lúc $newLead còn hoạt động, rồi bị vô hiệu hoá/xoá giữa lúc người
            // dùng đang điền lý do và lúc họ bấm lưu) — câu này đóng đúng khe hở đó.
            $lockedNewLead = User::query()->withTrashed()->whereKey($newLead->getKey())->lockForUpdate()->first();

            if ($lockedNewLead === null || ! $lockedNewLead->is_active || $lockedNewLead->trashed()) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('reassign.validation.new_lead_inactive')],
                ]);
            }

            // I3 residual (fix round 2): câu kiểm tra vai NGAY TRÊN (trước transaction) đọc
            // `$newLead` — đối tượng caller đưa vào, có thể đã CACHE quan hệ `roles` từ một lần
            // chạm trước đó (ví dụ danh sách chọn của `ViewMatter::reassignCandidateOptions()` đã
            // lọc bằng `hasRole()`), nên không tự thấy một lần đổi chức danh xảy ra GIỮA lúc màn
            // hình đó dựng danh sách và lúc lượt bàn giao này giành được khoá. Hỏi lại CHÍNH câu đó
            // trên `$lockedNewLead` — đọc thẳng CSDL dưới khoá, không cache — đóng đúng khe hở đó,
            // cùng công thức `is_active`/`trashed()` ngay trên.
            if (! ($lockedNewLead->hasRole(Role::Lawyer->value) || $lockedNewLead->hasRole(Role::Manager->value))) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('reassign.validation.new_lead_not_eligible')],
                ]);
            }

            $oldLead = User::query()->withTrashed()->find($locked->lead_lawyer_id);

            // Minor (fix round 1): `lead_lawyer_id` trỏ vào một hàng không còn tồn tại phải nói
            // ĐÚNG chuyện gì đã xảy ra, không mượn câu "trùng lead" — hai lý do khác hẳn nhau cho
            // cùng một ô. **Không có mutation probe cho nhánh này — đã tự kiểm, không phải bỏ
            // sót.** Cột có ràng buộc khoá ngoại `restrictOnDelete()`; đã thử dựng tình huống này
            // bằng `DB::table('matters')->update(['lead_lawyer_id' => <id giả>])` thẳng trên
            // CSDL test (bỏ qua hẳn app/) và chính SQLite (`PRAGMA foreign_keys`) từ chối câu lệnh
            // đó bằng `FOREIGN KEY constraint failed` — nên không có kịch bản nào, kể cả thao tác
            // CSDL trực tiếp trong bộ test, tạo ra được trạng thái này. Giữ lại nhánh vì đây là một
            // sửa chữa đúng đắn về mặt LOGIC nếu ràng buộc khoá ngoại từng bị nới lỏng (migration
            // tương lai đổi thành `nullOnDelete()`/`setNullOnDelete()`), không phải vì nó đang
            // chặn một kịch bản có thật hôm nay.
            if ($oldLead === null) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('reassign.validation.no_current_lead')],
                ]);
            }

            if ($lockedNewLead->is($oldLead)) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('reassign.validation.same_lead')],
                ]);
            }

            // Bước 1: lead mới vào đội với vai `lead` — `syncWithoutDetaching` (upsert) chứ không
            // `attach()`: một lời gọi lặp/đồng thời sẽ để lộ UNIQUE constraint thô nếu lead mới
            // vô tình đã có mặt trong đội với vai khác (associate/assistant/observer).
            $locked->team()->syncWithoutDetaching([
                $lockedNewLead->getKey() => ['role_in_matter' => MatterRole::Lead->value],
            ]);

            $locked->lead_lawyer_id = $lockedNewLead->getKey();
            $locked->blameOn($actor)->save();

            // Minor (fix round 1): lead cũ phải CÒN ĐI LÀM để giữ lại làm associate — cùng câu
            // hỏi mà mọi ô chọn người khác trong dự án hỏi trên người được chọn/giữ lại
            // (`TriageClientRequest::canHoldTheThread()`, `AddMatterDeadline::canHoldTheDeadline()`).
            // Một lead cũ đã bị vô hiệu hoá hoặc xoá mềm — có thể xảy ra khi hai thao tác đụng
            // nhau (một admin vô hiệu hoá họ trong lúc lượt bàn giao này đang mở) — không "giữ
            // lại" được: không có gì để giữ.
            $oldLeadKeptAsAssociate = false;

            if ($keepOldLeadAsAssociate && $oldLead->is_active && ! $oldLead->trashed()) {
                if (! Gate::forUser($oldLead)->allows('view', $locked)) {
                    throw ValidationException::withMessages([
                        'keep_old_lead_as_associate' => [__('reassign.validation.old_lead_would_not_see_matter')],
                    ]);
                }

                // Minor (fix round 1): `updateExistingPivot()` lặng lẽ không làm gì khi hàng
                // `matter_user` không tồn tại (ví dụ đã bị `RemoveTeamMember` gỡ ở một tab khác
                // trong lúc lượt bàn giao này đang mở) — trước bản sửa này, dòng audit vẫn ghi
                // `old_lead_kept_as_associate: true` dù KHÔNG có gì được ghi, một dòng nhật ký
                // nói dối. `syncWithoutDetaching()` — cùng cách Bước 1 thêm lead mới — luôn ghi
                // đúng, dù hàng cũ có tồn tại hay không.
                $locked->team()->syncWithoutDetaching([
                    $oldLead->getKey() => ['role_in_matter' => MatterRole::Associate->value],
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
                    'to' => $lockedNewLead->name,
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
                Deadline::query()->whereKey($movedDeadlineIds)->update(['responsible_user_id' => $lockedNewLead->id]);
            }

            // Bước 4: chỉ client_requests CHƯA ĐÓNG.
            $movedRequestIds = ClientRequest::query()
                ->where('matter_id', $locked->id)
                ->where('assigned_to', $oldLead->id)
                ->where('status', '!=', ClientRequestStatus::Closed->value)
                ->pluck('id');

            if ($movedRequestIds->isNotEmpty()) {
                ClientRequest::query()->whereKey($movedRequestIds)->update(['assigned_to' => $lockedNewLead->id]);
            }

            // Bước 5.
            Audit::record('matter_reassigned', $locked, [
                'from_user_id' => $oldLead->id,
                'to_user_id' => $lockedNewLead->id,
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
