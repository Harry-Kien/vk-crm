<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * R3 (M6.5 Task 8): người ưu tiên hợp lệ (is_active + Gate::view) đều nhận, không chỉ người đầu
 * tiên — "toàn bộ vai trò manager được xem vụ đó" (SPEC §6.8 đọc lại).
 */
it('returns every preferred person who is active and can view the matter, not just the first', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$lead, $manager]);

    expect($recipients->pluck('id')->sort()->values()->all())->toBe(
        collect([$lead->id, $manager->id])->sort()->values()->all()
    );
});

/** Người bị vô hiệu hoá giữa chừng không nhận, kể cả khi họ vẫn Gate::view() được (Review Focus 2). */
it('excludes an inactive preferred person even though they can still view the matter', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$lead, $manager]);

    expect($recipients->pluck('id')->all())->toBe([$manager->id]);
});

/**
 * R3: "Với vụ restricted, thay manager bằng admin." Không cần một nhánh riêng cho việc lọc
 * $preferred — Gate::view() của một vụ restricted đã tự loại một manager thường.
 */
it('excludes a manager from a restricted matter even when the manager is offered as preferred', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$lead, $manager]);

    expect($recipients->pluck('id')->all())->toBe([$lead->id]);
});

/**
 * Đúng nguyên văn phần đầu bài của controller context: một trợ lý còn đứng tên trong đội ngũ của
 * một vụ hạn chế (dữ liệu cũ, từ trước khi quy tắc hạn chế siết lại — `matter_user` không tự dọn
 * khi `confidentiality` đổi sau) nhưng KHÔNG được xem vụ đó (`isListableBy()` nhánh `restricted`
 * chỉ đọc `lead_lawyer_id`/`admin`, không đọc `team()` chút nào) phải bị loại, không phải vì họ
 * không active mà vì Gate::view() từ chối.
 */
it('excludes an assistant who is in a restricted matter’s team but cannot view it, even from stale data', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    // Dữ liệu cũ: trợ lý còn có mặt trong đội ngũ (ví dụ vụ việc được siết thành restricted SAU
    // khi họ đã được thêm vào) — team() không tự dọn khi confidentiality đổi.
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    expect($assistant->can('view', $matter))->toBeFalse();

    // Trợ lý bị loại NGAY trong $preferred, và vì không còn ai hợp lệ trong đó, handle() tự đi
    // tiếp chuỗi dự phòng ("không bao giờ im lặng" — R3) thay vì trả về rỗng: kết quả là luật sư
    // phụ trách, KHÔNG BAO GIỜ là trợ lý đó.
    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$assistant]);

    expect($recipients->pluck('id')->all())->toBe([$lead->id])
        ->and($recipients->pluck('id')->all())->not->toContain($assistant->id);
});

/** Chuỗi dự phòng, tầng 1: luật sư phụ trách, khi $preferred rỗng hoặc không ai hợp lệ. */
it('falls back to the lead lawyer when nobody in preferred qualifies', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, []);

    expect($recipients->pluck('id')->all())->toBe([$lead->id]);
});

/** Chuỗi dự phòng, tầng 2: luật sư phụ trách không hợp lệ (vô hiệu hoá) → một manager được xem vụ. */
it('falls back to a manager who can view the matter when the lead lawyer does not qualify', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, []);

    expect($recipients->pluck('id')->all())->toBe([$manager->id]);
});

/**
 * Chuỗi dự phòng, tầng 2 trên vụ restricted: đúng câu R3, admin thay cho manager — không phải vì
 * một nhánh riêng chọn vai trò khác, mà vì Gate::view() của một vụ restricted không cho một
 * manager thường đi qua ở bất kỳ tầng nào (xem docblock ResolveStaffRecipients::fallbackChain()).
 */
it('falls back to an admin instead of a manager when the matter is restricted', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, []);

    expect($recipients->pluck('id')->all())->toBe([$admin->id])
        ->and($recipients->pluck('id')->all())->not->toContain($manager->id);
});

/** Chuỗi dự phòng, tầng cuối: không luật sư phụ trách hợp lệ, không manager hợp lệ → admin bất kỳ. */
it('falls back all the way to any admin when neither the lead lawyer nor a manager qualify', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, []);

    expect($recipients->pluck('id')->all())->toBe([$admin->id]);
});

/** Null trong $preferred (ví dụ một quan hệ rỗng như $deadline->responsibleUser) bị bỏ qua lặng lẽ. */
it('silently skips null entries in the preferred list', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [null, $manager]);

    expect($recipients->pluck('id')->all())->toBe([$manager->id]);
});

/**
 * Fix round 1, minor ruling: một tài khoản đã xoá mềm (`trashed()`) không được nhận, kể cả khi
 * caller lỡ truyền một instance `User` nạp bằng `withTrashed()` mà `is_active` vẫn còn `true` (hai
 * cột KHÁC nhau — không có gì đảm bảo mọi đường xoá luôn đặt `is_active = false` trước, xem
 * docblock `qualify()`).
 */
it('excludes a soft-deleted user even when is_active is still true on the row', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    $deletedButActive = User::factory()->withRole(Role::Manager)->create(['is_active' => true]);
    $deletedButActive->delete();

    $reloaded = User::withTrashed()->whereKey($deletedButActive->id)->first();
    expect($reloaded->is_active)->toBeTrue()
        ->and($reloaded->trashed())->toBeTrue();

    $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$reloaded, $manager]);

    expect($recipients->pluck('id')->all())->toBe([$manager->id])
        ->and($recipients->pluck('id')->all())->not->toContain($deletedButActive->id);
});
