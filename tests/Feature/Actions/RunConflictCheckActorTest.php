<?php

use App\Actions\RunConflictCheck;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Quy tắc causer của dòng `conflict_check_run` (SPEC §6.10 bước 4 — dòng tồn tại để chứng minh
 * đã kiểm tra, VÀ bởi ai). Tách khỏi RunConflictCheckTest (vốn lo phần so khớp) vì đây là hợp
 * đồng về danh tính người thực hiện, không phải về kết quả kiểm tra.
 *
 * `$actor` là tham số CUỐI và TUỲ CHỌN: `RunConflictCheck` cũng là một chẩn đoán thuần đọc mà một
 * màn hình có thể chạy thăm dò, nên bắt buộc actor sẽ biến mọi lời gọi chẩn đoán thành lỗi kiểu.
 * Bù lại, dòng nhật ký phải TRUNG THỰC về chỗ danh tính đến từ đâu: `actor_explicit` nói rõ
 * causer là do caller khẳng định (true) hay chỉ suy ra từ phiên đang mở (false).
 */
function unsavedParty(PartyRole $role, string $name, ?string $idNumber = null): MatterParty
{
    return (new MatterParty(['role' => $role, 'name' => $name]))->identify($idNumber, null);
}

it('records the explicit actor as causer and ignores the session user entirely', function () {
    $actor = User::factory()->withRole(Role::Lawyer)->create();
    $sessionUser = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($sessionUser, 'web');

    app(RunConflictCheck::class)->handle(
        collect([unsavedParty(PartyRole::Plaintiff, 'Người Không Trùng Ai', '045678901234')]),
        null,
        $actor,
    );

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();

    expect($activity->causer?->is($actor))->toBeTrue()
        ->and($activity->causer?->is($sessionUser))->toBeFalse()
        ->and($activity->properties->get('actor_explicit'))->toBeTrue();
});

it('marks the row as having no asserted actor when the caller supplies none', function () {
    $sessionUser = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($sessionUser, 'web');

    app(RunConflictCheck::class)->handle(
        collect([unsavedParty(PartyRole::Plaintiff, 'Người Không Trùng Ai', '045678901234')]),
    );

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();

    // Causer vẫn suy ra từ phiên (hành vi cũ của Audit::record, không đổi ở đây), nhưng dòng
    // nhật ký nói rõ đó chỉ là suy luận — người đọc kiểm toán sau này không được hiểu nhầm nó là
    // một khẳng định của caller.
    expect($activity->properties->get('actor_explicit'))->toBeFalse();
});

it('records the row with no causer at all when there is no actor and no session', function () {
    app(RunConflictCheck::class)->handle(
        collect([unsavedParty(PartyRole::Plaintiff, 'Người Không Trùng Ai', '045678901234')]),
    );

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer)->toBeNull()
        ->and($activity->properties->get('actor_explicit'))->toBeFalse();
});
