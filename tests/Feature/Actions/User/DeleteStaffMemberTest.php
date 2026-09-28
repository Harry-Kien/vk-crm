<?php

use App\Actions\User\DeleteStaffMember;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Test Ở TẦNG ACTION cho `App\Actions\User\DeleteStaffMember` (fix round 2, finding I2.2) — cùng
 * quy ước `tests/Feature/Actions/Matter/ReassignMatterTest.php`: cổng THÔ của chính `DeleteAction`
 * (Filament tự hỏi `UserPolicy::delete()`, KHÔNG khoá gì) từ chối một actor không hợp lệ TRƯỚC
 * khi Livewire kịp gọi tới `->using()`, nên không màn hình nào dựng được cuộc đua "actor vừa mất
 * quyền admin GIỮA CHỪNG" — phải gọi thẳng Action ở tầng này mới đo được.
 *
 * Mọi test "actor đã đổi" đều mô phỏng ĐÚNG hình dạng cuộc đua: sửa CSDL trực tiếp (không qua đối
 * tượng `$actor` truyền vào `handle()`), để đối tượng đó vẫn "cũ" trong bộ nhớ — như một request B
 * đã âm thầm đổi actor trong lúc request A (đang gọi `handle()`) còn cầm bản ghi từ trước.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('deletes a staff member with no open work', function () {
    $admin = User::factory()->admin()->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    app(DeleteStaffMember::class)->handle($admin, $lawyer);

    expect($lawyer->fresh()->trashed())->toBeTrue();
});

/**
 * I2.2, vế 1: actor bị vô hiệu hoá NGAY SAU khi được nạp (đối tượng `$admin` truyền vào vẫn
 * `is_active = true` trong bộ nhớ) — chỉ câu đọc lại DƯỚI khoá mới bắt được.
 */
it('refuses when the actor was deactivated after being loaded', function () {
    $admin = User::factory()->admin()->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    User::query()->whereKey($admin->id)->update(['is_active' => false]);

    expect(fn () => app(DeleteStaffMember::class)->handle($admin, $lawyer))
        ->toThrow(AuthorizationException::class);

    expect($lawyer->fresh()->trashed())->toBeFalse();
});

/** I2.2, vế 1 (nhánh khác): actor đã bị xoá mềm — cùng lý do, khác cột. */
it('refuses when the actor was soft deleted after being loaded', function () {
    $admin = User::factory()->admin()->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $freshAdmin = User::query()->findOrFail($admin->id);
    $freshAdmin->delete();

    expect(fn () => app(DeleteStaffMember::class)->handle($admin, $lawyer))
        ->toThrow(AuthorizationException::class);

    expect($lawyer->fresh()->trashed())->toBeFalse();
});

/** I2.2, vế 1 (nhánh thứ ba): actor không còn giữ vai Admin (bị hạ chức danh giữa chừng). */
it('refuses when the actor is no longer an admin after being loaded', function () {
    $admin = User::factory()->admin()->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $freshAdmin = User::query()->findOrFail($admin->id);
    $freshAdmin->syncRoles([Role::Lawyer->value]);

    expect(fn () => app(DeleteStaffMember::class)->handle($admin, $lawyer))
        ->toThrow(AuthorizationException::class);

    expect($lawyer->fresh()->trashed())->toBeFalse();
});

/**
 * I2.2, vế 2 — kịch bản đua thật: admin A xoá admin B trong khi CHÍNH A vừa mất vai admin (một
 * request khác đã hạ chức danh A trong lúc request này còn cầm đối tượng A cũ). B là admin ĐANG
 * HOẠT ĐỘNG DUY NHẤT còn lại sau khi A mất vai — xoá B lúc này nghĩa là xoá đúng admin cuối cùng.
 * Bị chặn bởi CHÍNH câu kiểm tra actor (vế 1), trước khi kịp chạm tới câu hỏi "còn admin nào khác"
 * trên B — đúng thứ tự phòng thủ: một actor không còn hợp lệ thì không cần hỏi tiếp gì nữa.
 */
it('refuses to delete the last other active admin when the actor is no longer admin themself', function () {
    $actor = User::factory()->admin()->create();
    $lastAdmin = User::factory()->admin()->create();

    User::query()->whereKey($actor->id)->update(['is_active' => false]);

    expect(fn () => app(DeleteStaffMember::class)->handle($actor, $lastAdmin))
        ->toThrow(AuthorizationException::class);

    expect($lastAdmin->fresh()->trashed())->toBeFalse();
});

/**
 * Vế dương của luật "admin cuối cùng": actor VẪN là admin hợp lệ, xoá một admin KHÁC trong khi
 * CHÍNH actor vẫn còn đó — sau khi xoá, hệ thống còn đúng 1 admin đang hoạt động (actor), không
 * phải 0. Đây là vế dương bắt buộc đi kèm `wouldLeaveNoActiveAdmin($lockedTarget, false)` được gọi
 * lại dưới khoá — xem "Tự đánh giá" trong báo cáo cho lý do KHÔNG có một test âm RIÊNG cho nhánh
 * này tách khỏi câu kiểm tra actor (vế 1): với actor luôn hợp lệ và luôn KHÁC target (tự xoá đã bị
 * chặn ở một chỗ khác), actor tự nó luôn là "một admin khác" — nên nhánh `wouldLeaveNoActiveAdmin`
 * trên target không bao giờ `true` được khi actor đã qua được vế 1. Giữ lại làm phòng thủ nhiều
 * lớp, đo bằng mutation probe trực tiếp trên chính điều kiện đó (xem báo cáo).
 */
it('lets a valid admin delete another admin, leaving exactly one admin active', function () {
    $onlyAdminAfter = User::factory()->admin()->create();
    $otherAdminBeingDeleted = User::factory()->admin()->create();

    app(DeleteStaffMember::class)->handle($onlyAdminAfter, $otherAdminBeingDeleted);

    expect($otherAdminBeingDeleted->fresh()->trashed())->toBeTrue()
        ->and($onlyAdminAfter->fresh()->trashed())->toBeFalse();
});
