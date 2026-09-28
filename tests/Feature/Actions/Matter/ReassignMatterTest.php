<?php

use App\Actions\Matter\ReassignMatter;
use App\Enums\Role;
use App\Jobs\SendReassignmentDigest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\Matter\ReassignMatter` (fix round 1, finding I3) — màn
 * hình `ViewMatter::reassignAction()` đã lọc ô chọn "Luật sư phụ trách mới" xuống đúng vai
 * Lawyer/Manager (`reassignCandidateOptions()`), nhưng M7 sẽ có một màn hình bàn giao HÀNG LOẠT
 * gọi thẳng Action này — Action phải TỰ chặn, không tin bất kỳ trang nào đã lọc đúng. Test màn
 * hình đã có ở `ReassignMatterActionTest.php` (đi qua Livewire); tệp này đo luật của chính Action,
 * cùng quy ước `tests/Feature/Actions/TeamMemberTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->oldLead->id]);
});

/**
 * I3: "ReassignMatter itself must refuse a new lead who is not a Lawyer or Manager." Cùng tập vai
 * `AddTeamMember::eligibleForRole()` chấp nhận cho `associate` — một người đứng tên "luật sư phụ
 * trách" phải giữ một trong hai vai đó, không phải trợ lý hay kế toán.
 */
it('refuses a new lead who is neither a lawyer nor a manager', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $assistant,
        reason: 'Ép bàn giao cho người không đủ vai.',
        keepOldLeadAsAssociate: false,
    ))->toThrow(ValidationException::class);

    expect($this->matter->fresh()->lead_lawyer_id)->toBe($this->oldLead->id);
});

it('refuses an accountant as the new lead', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $accountant,
        reason: 'Ép bàn giao cho kế toán.',
        keepOldLeadAsAssociate: false,
    ))->toThrow(ValidationException::class);

    expect($this->matter->fresh()->lead_lawyer_id)->toBe($this->oldLead->id);
});

/** Vế dương thứ nhất: một luật sư khác vẫn nhận được vụ việc bình thường. */
it('accepts another lawyer as the new lead', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    $stageLog = app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $newLead,
        reason: 'Bàn giao cho luật sư khác.',
        keepOldLeadAsAssociate: false,
    );

    expect($stageLog)->not->toBeNull()
        ->and($this->matter->fresh()->lead_lawyer_id)->toBe($newLead->id);
});

/** Vế dương thứ hai: một trưởng phòng cũng nhận được vụ việc — vai thứ hai mà SPEC §1 công nhận là "đứng tên phụ trách". */
it('accepts a manager as the new lead', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $stageLog = app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $manager,
        reason: 'Bàn giao cho trưởng phòng.',
        keepOldLeadAsAssociate: false,
    );

    expect($stageLog)->not->toBeNull()
        ->and($this->matter->fresh()->lead_lawyer_id)->toBe($manager->id);
});

/**
 * I2 (fix round 1): "Inside the ReassignMatter transaction, after the matter lock, lockForUpdate
 * the new lead's user row and re-check is_active and trashed." Mô phỏng đúng cuộc đua: form mở ra
 * lúc `$newLead` còn hoạt động (đối tượng caller đang cầm trong tay VẪN `is_active = true`), rồi
 * một request KHÁC vô hiệu hoá họ ngay trên CSDL (không qua đối tượng này) TRƯỚC khi lượt bàn giao
 * này bấm lưu. Câu kiểm tra TRƯỚC transaction (đọc `$newLead->is_active` trong bộ nhớ) không bắt
 * được — nó vẫn thấy `true`; chỉ câu khoá lại DƯỚI transaction, đọc thẳng CSDL, mới bắt được.
 */
it('refuses a new lead deactivated between when the form opened and when it was submitted', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    // Vô hiệu hoá thẳng trên CSDL — KHÔNG qua $newLead, để đối tượng trong tay test vẫn còn
    // is_active = true trong bộ nhớ, đúng hình dạng một request khác đã chen vào.
    User::query()->whereKey($newLead->id)->update(['is_active' => false]);

    expect(fn () => app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: false,
    ))->toThrow(ValidationException::class);

    expect($this->matter->fresh()->lead_lawyer_id)->toBe($this->oldLead->id);
});

/**
 * ReassignMatter's I3 residual (fix round 2): câu kiểm tra vai TRƯỚC transaction đọc `$newLead`
 * (đối tượng caller đưa vào) — cùng khe hở I2 vừa đóng cho `is_active`/`trashed()`, nhưng vẫn còn
 * hở cho VAI TRÒ: form mở ra lúc lead mới còn là Lawyer, một request khác đổi chức danh họ sang
 * Trợ lý (`EditUser`) NGAY TRƯỚC khi lượt bàn giao này bấm lưu. Câu kiểm tra trước transaction
 * không bắt được (đối tượng trong tay vẫn `hasRole(Lawyer)` cũ); chỉ câu hỏi lại trên
 * `$lockedNewLead` (đọc thẳng CSDL dưới khoá) mới bắt được.
 */
it('refuses a new lead whose role changed away from lawyer/manager between when the form opened and when it was submitted', function () {
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    // `hasRole()` (Eloquent) chỉ tự đọc CSDL ở lần đầu chạm quan hệ `roles` — gọi một lần Ở ĐÂY mô
    // phỏng đúng "màn hình đã chạm quan hệ này trước rồi" (ví dụ danh sách chọn lead mới của
    // `ViewMatter::reassignCandidateOptions()` đã lọc theo `hasRole()`), CACHE lại quan hệ TRÊN
    // ĐÚNG đối tượng `$newLead` này — để câu kiểm tra trước transaction (nếu còn đọc `$newLead`)
    // dùng lại bản CACHE cũ, không tự query lại.
    expect($newLead->hasRole(Role::Lawyer->value))->toBeTrue();

    // Đổi vai thẳng qua một đối tượng KHÁC (đọc lại từ CSDL) — KHÔNG qua $newLead, để đối tượng
    // trong tay test vẫn còn hasRole(Lawyer) = true trong bộ nhớ (quan hệ đã cache ở trên), đúng
    // hình dạng một request khác đã chen vào.
    User::query()->findOrFail($newLead->id)->syncRoles([Role::Assistant->value]);

    expect(fn () => app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: false,
    ))->toThrow(ValidationException::class);

    expect($this->matter->fresh()->lead_lawyer_id)->toBe($this->oldLead->id);
});

// =========================================================================================
// Minor items (fix round 1)
// =========================================================================================

/**
 * Minor: lead cũ phải CÒN ĐI LÀM để "giữ lại làm associate" có nghĩa — một tài khoản đã bị vô
 * hiệu hoá GIỮA CHỪNG (một request khác chen vào, cùng hình dạng cuộc đua I2 đo ở trên cho lead
 * mới) không giữ lại được; Action tự gỡ họ thay vì cố giữ một người không còn đi làm. Dùng admin
 * làm actor (không phải oldLead — họ vừa mất `is_active` nên không còn qua được `manageTeam`) để
 * cô lập đúng điều kiện đang đo khỏi cổng quyền của actor.
 */
it('detaches an old lead whose account was deactivated mid-flight, even when the form asked to keep them', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    User::query()->whereKey($this->oldLead->id)->update(['is_active' => false]);

    app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $admin,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: true,
    );

    expect($this->matter->fresh()->team()->whereKey($this->oldLead->id)->exists())->toBeFalse();
});

/**
 * Minor: `updateExistingPivot()` lặng lẽ không ghi gì khi hàng `matter_user` của lead cũ đã bị
 * gỡ trước đó (ví dụ qua `RemoveTeamMember` ở một tab khác trong lúc lượt bàn giao này đang mở) —
 * trước bản sửa, `old_lead_kept_as_associate` trong audit vẫn ghi `true` dù không có gì được ghi.
 * Xoá thẳng hàng pivot rồi bàn giao với `keepOldLeadAsAssociate: true` phải THẬT SỰ gắn lại họ.
 *
 * **Lead cũ là TRƯỞNG PHÒNG, cố ý** — họ có `matter.viewAny` nên vẫn qua được
 * `Gate::allows('view', $matter)` dù không còn hàng `matter_user` nào (khác một luật sư thường,
 * chỉ `view` được qua team membership — case đó đã đúng đắn bị chặn ở nhánh
 * "old_lead_would_not_see_matter", một test khác). Đây là ca DUY NHẤT "giữ lại làm associate"
 * còn chạy tới bước gắn pivot khi hàng cũ đã mất, nên là ca đúng để đo lỗi `updateExistingPivot()`.
 */
it('re-attaches the old lead as an associate even when their team_user row was removed beforehand', function () {
    $oldLeadManager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $oldLeadManager->id]);
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter->team()->detach($oldLeadManager->id);

    app(ReassignMatter::class)->handle(
        matter: $matter,
        actor: $oldLeadManager,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: true,
    );

    expect($matter->fresh()->team()->whereKey($oldLeadManager->id)
        ->wherePivot('role_in_matter', 'associate')->exists())->toBeTrue();
});

/**
 * Final review A-M5: `manageTeam` được hỏi TRƯỚC khoá, trên đối tượng caller đưa vào — có thể đã
 * cũ. Luật sư A mở màn hình khi còn là lead; một lượt khác đã bàn giao vụ cho B (A ở lại làm cộng
 * sự). A bấm lưu với đối tượng cũ vẫn ghi `lead_lawyer_id = A`: câu hỏi trước khoá cho qua, và
 * trước bản sửa này A giành lại vụ việc. Hỏi lại `manageTeam` trên bản ghi ĐÃ KHOÁ — M7 sẽ gọi
 * Action này hàng loạt, cổng phải đúng dưới khoá.
 */
it('re-checks manageTeam under the lock, so a stale matter object cannot let a former lead reassign', function () {
    $lawyerB = User::factory()->withRole(Role::Lawyer)->create();
    $lawyerC = User::factory()->withRole(Role::Lawyer)->create();
    $stale = $this->matter->fresh();

    app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $lawyerB,
        reason: 'Bàn giao cho B.',
        keepOldLeadAsAssociate: true,
    );

    expect($stale->lead_lawyer_id)->toBe($this->oldLead->id);

    expect(fn () => app(ReassignMatter::class)->handle(
        matter: $stale,
        actor: $this->oldLead,
        newLead: $lawyerC,
        reason: 'A bấm lưu trên màn hình đã cũ.',
        keepOldLeadAsAssociate: false,
    ))->toThrow(AuthorizationException::class);

    expect($this->matter->fresh()->lead_lawyer_id)->toBe($lawyerB->id);
});

// =========================================================================================
// M7 Task 1 — thư tổng hợp mốc hạn cho lead mới (SPEC §6.11 bước 3, R10).
// =========================================================================================

/**
 * Mặc định `$sendDigest = true`: bàn giao MỘT vụ (đường vào duy nhất hiện có, qua
 * `ViewMatter::reassignAction()`) tự xếp một `SendReassignmentDigest` mang đúng mốc CHƯA hoàn
 * thành vừa chuyển — payload chỉ mang id, job tự dựng lại nội dung lúc chạy (xem tệp test riêng
 * của job). Dùng `Queue::fake()` ở đây, khác `Mail::fake()` của `ReassignMatterActionTest`, để đo
 * ĐÚNG việc job có được dispatch với payload đúng hay không, tách khỏi nội dung thư (việc của job).
 */
it('dispatches a reassignment digest job after commit, by default, carrying the unfinished deadlines moved', function () {
    Queue::fake();

    $unfinished = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->oldLead->id,
        'is_completed' => false,
    ]);
    $finished = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->oldLead->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: false,
    );

    Queue::assertPushed(SendReassignmentDigest::class, function (SendReassignmentDigest $job) use ($newLead, $unfinished, $finished): bool {
        $ids = $job->matters[$this->matter->id]['deadline_ids'] ?? null;

        return $job->newLeadId === $newLead->id
            && $ids !== null
            && in_array($unfinished->id, $ids, true)
            && ! in_array($finished->id, $ids, true);
    });
});

/**
 * Mutation probe (cặp âm/dương với test trên): `$sendDigest: false` — dành cho M7 Task 2 (bàn
 * giao hàng loạt), nơi CALLER tự gộp một thư cho cả lô thay vì để Action này tự xếp một thư trên
 * mỗi vụ. Xoá điều kiện `if ($sendDigest)` khỏi `ReassignMatter::handle()` làm chính test này đỏ
 * (job vẫn bị dispatch dù đã tắt).
 */
it('does not dispatch a reassignment digest job when sendDigest is turned off', function () {
    Queue::fake();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    app(ReassignMatter::class)->handle(
        matter: $this->matter,
        actor: $this->oldLead,
        newLead: $newLead,
        reason: 'Bàn giao.',
        keepOldLeadAsAssociate: false,
        sendDigest: false,
    );

    Queue::assertNotPushed(SendReassignmentDigest::class);
});
