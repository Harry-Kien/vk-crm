<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Enums\ConflictLevel;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa, sửa sau rà soát cuối (F1) — gộp hai lần tiếp nhận không được rửa mất TỔ CHỨC người gọi đại
 * diện (A1). Bản nguồn thành "Đã gộp" và rời nguồn dò thứ hai; nếu tên tổ chức và dấu băm mã số thuế
 * của nó không sang bản đích, bản đích được kiểm tra lại mà không có công ty — một khớp Vàng của công
 * ty biến mất, ô câu chuyện mở, và bản đích chuyển đổi được thành khách cá nhân. Mọi hành vi qua màn
 * hình (Livewire, hành động "Gộp vào bản ghi khác").
 *
 * Hàm toàn cục mang tiền tố `imo…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function imoStaff(Role $role = Role::Assistant): User
{
    return User::factory()->withRole($role)->create();
}

/** Một bản ghi đã ghi nhận thông báo quyền riêng tư, một bên đối lập đủ định danh (để bản không tổ chức là Xanh). */
function imoRecord(User $actor, array $overrides = []): IntakeRequest
{
    $intake = app(RecordIntake::class)->handle($actor, [...[
        'contact_name' => 'Nguyễn Văn An',
        'contact_phone' => '0901234567',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
    ], ...$overrides], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);

    return $intake->fresh();
}

function imoEdit(IntakeRequest $intake)
{
    return test()->livewire(EditIntakeRequest::class, ['record' => $intake->getRouteKey()]);
}

it('carries the organisation of the merged record to a target that names none, so a yellow on the company survives the merge', function () {
    // Công ty là BỊ ĐƠN của một vụ đang làm, chỉ có tên: khớp theo tên → Vàng.
    MatterParty::factory()->for(Matter::factory()->create())->defendant()->create(['name' => 'Công ty TNHH Xây dựng Hoà Bình']);

    $assistant = imoStaff();
    $target = imoRecord($assistant);
    $source = imoRecord($assistant, [
        'contact_phone' => '0901234568',
        'organization_name' => 'CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH',
        'organization_id_number' => '0101234567',
    ]);

    expect($target->conflict_level)->toBe(ConflictLevel::Green)
        ->and($source->conflict_level)->toBe(ConflictLevel::Yellow);

    $this->actingAs($assistant, 'web');
    imoEdit($target)->assertFormFieldIsEnabled('summary');

    imoEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasNoErrors();

    $target = $target->fresh();
    $match = collect($target->conflict_result['matches'])->sole();

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($target->organization_name)->toBe('CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH')
        ->and($target->organization_id_number_hash)->toBe(Normalizer::idNumberHash('0101234567'))
        ->and($target->conflict_level)->toBe(ConflictLevel::Yellow)
        ->and($match['party_name'])->toBe('Công ty TNHH Xây dựng Hoà Bình')
        ->and($match['our_party_name'])->toBe('CÔNG TY TNHH XÂY DỰNG HOÀ BÌNH')
        ->and($target->conflict_result['fingerprint'])->toBe($target->identityFingerprint())
        ->and(Activity::query()->where('event', 'intake_merge_received')->sole()->properties->all())
        ->toBe(['merged_from' => $source->code, 'moved_parties' => 0, 'organization_carried' => true]);

    imoEdit($target)
        ->assertSee(__('enums.intake_summary_blocker.conflict_acknowledgement'))
        ->assertFormFieldIsDisabled('summary');
});

it('fills in the tax code of the same organisation when only the merged record had it', function () {
    $assistant = imoStaff();
    $target = imoRecord($assistant, ['organization_name' => 'Công ty TNHH Sao Mai']);
    $source = imoRecord($assistant, ['contact_phone' => '0901234568', 'organization_name' => 'CÔNG TY TNHH SAO MAI', 'organization_id_number' => '0109999999']);

    $this->actingAs($assistant, 'web');
    imoEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasNoErrors();

    $target = $target->fresh();

    expect($target->organization_name)->toBe('Công ty TNHH Sao Mai')
        ->and($target->organization_id_number_hash)->toBe(Normalizer::idNumberHash('0109999999'))
        ->and($target->conflict_result['fingerprint'])->toBe($target->identityFingerprint());
});

it('keeps the organisation of the target when the merged record names none', function () {
    $assistant = imoStaff();
    $target = imoRecord($assistant, ['organization_name' => 'Công ty TNHH Sao Mai', 'organization_id_number' => '0109999999']);
    $source = imoRecord($assistant, ['contact_phone' => '0901234568']);

    $this->actingAs($assistant, 'web');
    imoEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasNoErrors();

    expect($target->fresh()->organization_name)->toBe('Công ty TNHH Sao Mai')
        ->and($target->fresh()->organization_id_number_hash)->toBe(Normalizer::idNumberHash('0109999999'));
});

it('refuses to merge two records that name different organisations, and changes nothing', function (array $sourceOrg, array $targetOrg) {
    $assistant = imoStaff();
    $target = imoRecord($assistant, $targetOrg);
    $source = imoRecord($assistant, ['contact_phone' => '0901234568', ...$sourceOrg]);
    $before = $target->fresh()->only(['organization_name', 'organization_id_number_hash', 'conflict_checked_at']);

    $this->actingAs($assistant, 'web');
    imoEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasActionErrors(['merge_target']);

    expect($source->fresh()->status)->toBe(IntakeStatus::New)
        ->and($source->fresh()->merged_into_id)->toBeNull()
        ->and($source->fresh()->parties()->count())->toBe(1)
        ->and($target->fresh()->only(['organization_name', 'organization_id_number_hash', 'conflict_checked_at']))->toEqual($before);
})->with([
    'different names' => [
        ['organization_name' => 'Công ty TNHH Sao Mai'],
        ['organization_name' => 'Công ty Cổ phần Hoà Bình'],
    ],
    'same name, different tax codes' => [
        ['organization_name' => 'Công ty TNHH Sao Mai', 'organization_id_number' => '0101111111'],
        ['organization_name' => 'Công ty TNHH Sao Mai', 'organization_id_number' => '0102222222'],
    ],
    'same tax code, different names' => [
        ['organization_name' => 'Công ty TNHH Sao Mai', 'organization_id_number' => '0101111111'],
        ['organization_name' => 'Công ty Cổ phần Hoà Bình', 'organization_id_number' => '0101111111'],
    ],
]);

it('merges two records of the same organisation with the same tax code, the target keeping its own', function () {
    $assistant = imoStaff();
    $target = imoRecord($assistant, ['organization_name' => 'Công ty TNHH Sao Mai', 'organization_id_number' => '0109999999']);
    $source = imoRecord($assistant, ['contact_phone' => '0901234568', 'organization_name' => 'CÔNG TY TNHH SAO MAI', 'organization_id_number' => '010 999 9999']);

    $this->actingAs($assistant, 'web');
    imoEdit($source)
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasNoErrors();

    expect($source->fresh()->status)->toBe(IntakeStatus::Merged)
        ->and($target->fresh()->organization_name)->toBe('Công ty TNHH Sao Mai')
        ->and($target->fresh()->organization_id_number_hash)->toBe(Normalizer::idNumberHash('0109999999'))
        ->and(Activity::query()->where('event', 'intake_merge_received')->sole()->properties->has('organization_carried'))->toBeFalse();
});
