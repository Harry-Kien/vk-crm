<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * M9 Task 11 — người nhận thư về TIỀN của một vụ việc. Cùng lớp `ResolveStaffRecipients` với thư
 * nhắc hạn (R3), chỉ thay cổng "được xem vụ" bằng cổng "được xem TIỀN của vụ"
 * (`viewAny` + `[Contract::class, $matter]` = `billing.view` + `Matter::isListableBy()`, P3).
 *
 * Hai điều được đo riêng ở đây (phần còn lại của lớp đã có `ResolveStaffRecipientsTest`):
 *  - QUYẾT ĐỊNH VAI TRÒ nằm trong lớp (`billingAudienceFor()`), không ở nơi gọi: vụ thường = luật
 *    sư phụ trách + mọi kế toán đang hoạt động; vụ `restricted` = luật sư phụ trách + mọi admin.
 *  - CỔNG TIỀN thay cổng vụ: một trợ lý trong đội ngũ vụ thì `view` được vụ nhưng KHÔNG thấy tiền;
 *    một kế toán thì ngược lại.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->resolver = app(ResolveStaffRecipients::class);
});

function billingIds(iterable $users): array
{
    return collect($users)->pluck('id')->sort()->values()->all();
}

function billingIdList(User ...$users): array
{
    return collect($users)->pluck('id')->sort()->values()->all();
}

// =================================================================================================
// billingAudienceFor(): quyết định vai trò
// =================================================================================================

it('names the lead lawyer and every active accountant as the audience of a normal matter, and nobody else', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $accountantA = User::factory()->withRole(Role::Accountant)->create();
    $accountantB = User::factory()->withRole(Role::Accountant)->create();
    User::factory()->withRole(Role::Manager)->create();
    User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->billingAudienceFor($matter)))
        ->toBe(billingIdList($lead, $accountantA, $accountantB));
});

it('names the lead lawyer and every active admin as the audience of a restricted matter, and no accountant', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $adminA = User::factory()->withRole(Role::Admin)->create();
    $adminB = User::factory()->withRole(Role::Admin)->create();
    User::factory()->withRole(Role::Accountant)->create();
    User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->billingAudienceFor($matter)))
        ->toBe(billingIdList($lead, $adminA, $adminB));
});

it('lists the lead lawyer first so the person in charge is never lost behind the role members', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect($this->resolver->billingAudienceFor($matter)->first()->is($lead))->toBeTrue()
        ->and($this->resolver->billingAudienceFor($matter)->pluck('id')->all())->toContain($accountant->id);
});

it('leaves an inactive accountant out of the audience', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $active = User::factory()->withRole(Role::Accountant)->create();
    User::factory()->withRole(Role::Accountant)->create(['is_active' => false]);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->billingAudienceFor($matter)))->toBe(billingIdList($lead, $active));
});

it('leaves a soft-deleted accountant out of the audience even if the account is still marked active', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $gone = User::factory()->withRole(Role::Accountant)->create();
    $gone->delete();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->billingAudienceFor($matter)))->toBe(billingIdList($lead));
});

it('leaves an inactive lead lawyer out of the audience', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->billingAudienceFor($matter)))->toBe(billingIdList($accountant));
});

// =================================================================================================
// forBilling(): cổng tiền thay cổng vụ
// =================================================================================================

it('lets an accountant through the billing gate of a normal matter, though the accountant cannot view the matter', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$accountant])))->toBe(billingIdList($accountant))
        // Cặp đối chứng: cùng người, cổng VỤ VIỆC của `handle()` từ chối (kế toán không có `matter.view`),
        // nên nó rơi xuống chuỗi dự phòng và trả về LUẬT SƯ, không phải kế toán.
        ->and(billingIds($this->resolver->handle($matter, [$accountant])))->toBe(billingIdList($lead));
});

it('keeps out an assistant who is on the matter team and can view it but has no billing.view', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    // Cổng vụ: trợ lý qua được. Cổng tiền: không. `forBilling` phải theo cổng tiền, rồi rơi về dự
    // phòng (luật sư) — không trả trợ lý.
    expect(billingIds($this->resolver->handle($matter, [$assistant])))->toBe(billingIdList($assistant))
        ->and(billingIds($this->resolver->forBilling($matter, [$assistant])))->toBe(billingIdList($lead));
});

it('excludes an accountant from the billing recipients of a restricted matter', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$lead, $accountant])))->toBe(billingIdList($lead));
});

it('excludes a manager from the billing recipients of a restricted matter', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$lead, $manager])))->toBe(billingIdList($lead));
});

it('returns every qualified preferred person, not only the first', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $accountantA = User::factory()->withRole(Role::Accountant)->create();
    $accountantB = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$lead, $accountantA, $accountantB])))
        ->toBe(billingIdList($lead, $accountantA, $accountantB));
});

it('drops an inactive person from the billing recipients even though the billing gate would pass them', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $inactive = User::factory()->withRole(Role::Accountant)->create(['is_active' => false]);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$lead, $inactive])))->toBe(billingIdList($lead));
});

it('drops a soft-deleted person from the billing recipients', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $deleted = User::factory()->withRole(Role::Accountant)->create();
    $deleted->delete();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [$lead, $deleted])))->toBe(billingIdList($lead));
});

it('ignores null entries so a possibly-empty relation can be passed straight in', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, [null, $lead, null])))->toBe(billingIdList($lead));
});

// =================================================================================================
// Chuỗi dự phòng R3 — "không bao giờ im lặng", mỗi tầng qua CÙNG cổng tiền
// =================================================================================================

/**
 * Ca dự phòng 1 (vụ THƯỜNG): không còn kế toán hoạt động nào VÀ luật sư phụ trách nghỉ việc — toàn
 * bộ `$preferred` rớt. Chuỗi: luật sư (rớt) → MỘT quản lý (qua cổng tiền, vụ thường) → thư đi.
 * Chỉ MỘT người, không phải mọi quản lý (khác `$preferred`).
 */
it('falls back to one manager on a normal matter when the lead lawyer left and there is no active accountant', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $managerA = User::factory()->withRole(Role::Manager)->create();
    User::factory()->withRole(Role::Manager)->create();
    User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = $this->resolver->forBilling($matter, $this->resolver->billingAudienceFor($matter)->all());

    expect($recipients)->toHaveCount(1)
        ->and($recipients->first()->hasRole(Role::Manager->value))->toBeTrue();
});

/**
 * Ca dự phòng 2 (vụ THƯỜNG): không có quản lý nào — rơi xuống admin (cổng tiền của vụ thường cho
 * admin qua).
 */
it('falls back to an admin on a normal matter when nobody else qualifies', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, $this->resolver->billingAudienceFor($matter)->all())))
        ->toBe(billingIdList($admin));
});

/**
 * Ca dự phòng 3 (vụ `restricted`): audience = luật sư + admin; luật sư nghỉ việc và admin CHÍNH
 * (audience) bị vô hiệu hoá → toàn bộ `$preferred` rớt. Chuỗi: luật sư (rớt) → quản lý (KHÔNG qua
 * cổng tiền của vụ hạn chế, dù là quản lý hợp lệ) → admin (qua). Cho phép chuỗi dự phòng dùng ĐÚNG
 * cổng tiền: cổng vụ và cổng tiền đều loại quản lý ở đây, nên ca này ghim "quản lý không lọt".
 */
it('never falls back to a manager on a restricted matter — it goes to an admin', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    // `$preferred` rỗng ép chuỗi dự phòng chạy độc lập với `billingAudienceFor()`.
    expect(billingIds($this->resolver->forBilling($matter, [])))->toBe(billingIdList($admin));
});

it('does not run the fallback chain while a preferred person still qualifies', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    User::factory()->withRole(Role::Manager)->create();
    User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->forBilling($matter, $this->resolver->billingAudienceFor($matter)->all())))
        ->toBe(billingIdList($accountant));
});

it('passes the fallback lead lawyer through the billing gate, not the matter gate', function () {
    // Người đứng tên phụ trách vụ là một TRỢ LÝ (dữ liệu lạ nhưng có thể có: `lead_lawyer_id` chỉ
    // là một khoá ngoại tới `users`). Dự phòng tầng 1 dùng `$matter->leadLawyer`: cổng VỤ của
    // `handle()` cho họ qua (`matter.view` + đội ngũ), cổng TIỀN của `forBilling()` thì không
    // (không `billing.view`) — nên chuỗi tiếp tục xuống quản lý.
    $lead = User::factory()->withRole(Role::Assistant)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect(billingIds($this->resolver->handle($matter, [])))->toBe(billingIdList($lead))
        ->and(billingIds($this->resolver->forBilling($matter, [])))->toBe(billingIdList($manager));
});

it('returns nobody only when no active admin exists at all', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    expect($this->resolver->forBilling($matter, []))->toBeEmpty();
});

/**
 * Hai test dưới đây ghim rằng TẦNG quản lý và TẦNG admin của chuỗi dự phòng cũng hỏi cổng TIỀN,
 * không phải cổng vụ. Hôm nay hai cổng cho cùng một câu trả lời với quản lý và admin (cả hai vai
 * trò đều có `billing.view` lẫn `matter.viewAny`), nên để chúng tách ra được, test rút
 * `billing.view` khỏi CHÍNH VAI TRÒ (một thay đổi cấu hình vai trò có thật, như chủ văn phòng đổi
 * ma trận quyền): cổng vụ vẫn cho qua, cổng tiền thì không.
 */
it('asks the billing gate at the manager tier of the fallback chain, not the matter gate', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    Spatie\Permission\Models\Role::findByName(Role::Manager->value, 'web')
        ->revokePermissionTo(Permission::BillingView->value);

    expect(billingIds($this->resolver->handle($matter, [])))->toBe(billingIdList($manager))
        ->and(billingIds($this->resolver->forBilling($matter, [])))->toBe(billingIdList($admin));
});

it('asks the billing gate at the admin tier of the fallback chain, not the matter gate', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    Spatie\Permission\Models\Role::findByName(Role::Admin->value, 'web')
        ->revokePermissionTo(Permission::BillingView->value);

    expect(billingIds($this->resolver->handle($matter, [])))->toBe(billingIdList($admin))
        ->and($this->resolver->forBilling($matter, []))->toBeEmpty();
});

/**
 * Một admin đứng tên phụ trách chính vụ `restricted` của mình có mặt HAI lần trong danh sách thô
 * (luật sư phụ trách + mọi admin) — phải nhận một thư, không phải hai.
 */
it('lists a person once when they are both the lead lawyer and an admin of a restricted matter', function () {
    $adminLead = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $adminLead->id]);

    expect($this->resolver->billingAudienceFor($matter)->pluck('id')->all())->toBe([$adminLead->id]);
});
