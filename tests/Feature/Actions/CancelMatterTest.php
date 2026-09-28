<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** "Huỷ hồ sơ mở nhầm" — admin, xoá mềm kèm lý do bắt buộc, audit trong transaction. */
it('lets an admin cancel a wrongly opened matter with a reason, and records it on the audit trail', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $cancelled = app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');

    expect($cancelled->trashed())->toBeTrue()
        ->and(Matter::withTrashed()->find($matter->id)->trashed())->toBeTrue();

    $activity = Activity::query()->where('event', 'matter_cancelled')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer?->is($admin))->toBeTrue()
        ->and($activity->properties->get('reason'))->toBe('Mở nhầm khách hàng, đã mở lại hồ sơ đúng.');
});

/** Cổng thật: chỉ admin — một trưởng phòng, dù có matter.update, không huỷ được. */
it('refuses a manager, who is not an admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(CancelMatter::class)->handle($matter, $manager, 'Lý do bất kỳ'))
        ->toThrow(AuthorizationException::class);

    expect($matter->fresh()->trashed())->toBeFalse();
});

/** Lý do là bắt buộc — không huỷ được với một chuỗi rỗng hoặc chỉ có khoảng trắng. */
it('refuses to cancel without a reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(CancelMatter::class)->handle($matter, $admin, '   '))
        ->toThrow(ValidationException::class);

    expect($matter->fresh()->trashed())->toBeFalse();
});

/** Cặp dương của test trên: một lý do thật, dù ngắn, vẫn đi qua được. */
it('accepts a short but real reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    app(CancelMatter::class)->handle($matter, $admin, 'Trùng hồ sơ');

    expect($matter->fresh()->trashed())->toBeTrue();
});

/** Không huỷ được hai lần — vụ đã huỷ thì "chưa xong" không còn nghĩa gì để huỷ lại. */
it('refuses to cancel a matter that has already been cancelled', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();
    $matter->delete();

    expect(fn () => app(CancelMatter::class)->handle($matter, $admin, 'Lý do thứ hai'))
        ->toThrow(ValidationException::class);
});

/**
 * Cách ly cổng vẫn đứng vững sau khi huỷ (SPEC §11): một vụ đã xoá mềm không còn nằm trong
 * ĐƯỜNG TRUY VẤN mà `Matter::applyClientPortalConstraints()` dùng cho cổng khách, kể cả khi vẫn
 * đang `is_published_to_portal = true`. Đây là tầng đầu tiên trong ba tầng cách ly (query,
 * policy, serialize) — không tầng nào được yếu đi vì tầng khác đã che.
 */
it('keeps a cancelled matter off the portal query even while still marked published', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['is_published_to_portal' => true]);
    $clientUser = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm, huỷ để mở lại đúng.');

    $visible = Matter::query();
    $matter->applyClientPortalConstraints($visible, $clientUser);

    expect($visible->whereKey($matter->getKey())->exists())->toBeFalse();
});

/**
 * Ruling của fix round 1: bên của một vụ ĐÃ HUỶ (qua chính `CancelMatter`, không phải một
 * `->delete()` viết tay) VẪN nằm trong dữ liệu đối chiếu xung đột — một dương tính giả (báo động
 * nhầm về một vụ đã huỷ) an toàn hơn một âm tính giả (bỏ sót một xung đột thật). Đây là bằng
 * chứng cho đúng câu docblock lớp (`CancelMatter.php`) vừa sửa; hành vi bên dưới đã đúng SẴN
 * trước fix round 1 (`RunConflictCheck::query()` tự `withTrashed()`), không phải một điều kiện
 * mới — nên không có mutation probe: Action này không viết thêm một dòng mã nào cho luật này,
 * đúng như phán quyết yêu cầu ("Add no code that removes them").
 */
function conflictCheckPartyStub(
    PartyRole $role,
    string $name,
    ?string $idNumber = null,
    bool $isOurClient = false,
): MatterParty {
    return (new MatterParty([
        'role' => $role,
        'name' => $name,
        'is_our_client' => $isOurClient,
    ]))->identify($idNumber, null);
}

it('keeps a cancelled matters party in conflict-check data instead of a false-clean result', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $existingClient = Client::factory()->create(['id_number' => '055566677788']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($existingClient)->create();

    app(CancelMatter::class)->handle($matter, $admin, 'Mở nhầm khách hàng, huỷ để mở lại đúng.');

    $ourNewClient = conflictCheckPartyStub(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = conflictCheckPartyStub(PartyRole::Defendant, 'Bên trùng', '055566677788');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($matter->code);
});
