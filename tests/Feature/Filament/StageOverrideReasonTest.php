<?php

use App\Actions\TransitionMatterStage;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fm, mục B4 (kiểm tra nghiệp vụ 2026-10-09): mở lại vụ đã kết thúc, hay đi ngoài `allowed_next`
 * (đường bỏ qua của quản trị viên), phải có lý do ghi trong ghi chú nội bộ, và lý do đó vào nhật ký.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

const OVERRIDE_REASON = 'Khách kháng cáo bản án, mở lại hồ sơ để tiếp tục.';

function overrideTransition(Matter $matter, User $actor, string $to, ?string $note)
{
    return app(TransitionMatterStage::class)->handle($matter->fresh(), $actor, $to, today(), $note, null, null, null, null, false);
}

it('refuses to reopen a closed matter without a reason of at least 20 characters', function (?string $note) {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDays(3)]);

    try {
        overrideTransition($matter, $admin, 'appeal', $note);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('internal_note');
    }

    expect($matter->fresh()->isClosed())->toBeTrue();
})->with([
    'không ghi chú' => [null],
    'ghi chú ngắn' => ['Mở lại vụ'],
    'toàn khoảng trắng' => ['                          '],
]);

it('reopens with a reason and logs it as the override reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDays(3)]);

    overrideTransition($matter, $admin, 'appeal', OVERRIDE_REASON);

    $activity = Activity::query()->where('event', 'matter_stage_transitioned')->latest('id')->first();

    expect($matter->fresh()->isClosed())->toBeFalse()
        ->and($activity->properties['reopened'])->toBeTrue()
        ->and($activity->properties['override_reason'])->toBe(OVERRIDE_REASON);
});

it('asks an admin for a reason when skipping the allowed path, but not a lawyer on the normal path', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $skipped = Matter::factory()->atStage('intake')->create();
    $normal = Matter::factory()->atStage('intake')->create(['lead_lawyer_id' => $lawyer->id]);

    expect(fn () => overrideTransition($skipped, $admin, 'drafting', null))->toThrow(ValidationException::class);

    overrideTransition($skipped, $admin, 'drafting', OVERRIDE_REASON);
    overrideTransition($normal, $lawyer, 'collecting_documents', null);

    $activity = Activity::query()->where('event', 'matter_stage_transitioned')->where('subject_id', $normal->id)->latest('id')->first();

    expect($skipped->fresh()->stage)->toBe('drafting')
        ->and($normal->fresh()->stage)->toBe('collecting_documents')
        ->and($activity->properties['reopened'])->toBeFalse()
        ->and($activity->properties['override_reason'])->toBeNull();
});

it('does not ask for a reason when a closed matter only moves to another closing stage', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDays(3)]);
    $matter->matterType->stages()->create([
        'key' => 'archived', 'label' => 'Lưu kho', 'client_label' => 'Đã lưu kho',
        'client_description' => 'Hồ sơ đã được lưu kho theo quy định của văn phòng.', 'sort_order' => 99,
        'is_terminal' => true, 'allowed_next' => [], 'default_next_update_days' => 14,
    ]);
    $matter->matterType->stages()->where('key', 'closed')->update(['allowed_next' => ['archived']]);
    $matter->matterType->unsetRelation('stages');

    overrideTransition($matter, $admin, 'archived', null);

    expect($matter->fresh()->stage)->toBe('archived');
});

it('marks the internal note required on the form when an admin reopens a closed matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subDays(3)]);

    $this->actingAs($admin, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'appeal',
        'occurred_at' => today()->toDateString(),
        'internal_note' => null,
        'publish' => false,
    ])->assertHasTableActionErrors(['internal_note']);

    expect($matter->fresh()->isClosed())->toBeTrue();
});

/**
 * Văn phòng có thể khai báo một đường ra khỏi giai đoạn kết thúc trong `allowed_next` (ví dụ "Kết
 * thúc" → "Phúc thẩm"). Đi đường đó không phải "bỏ qua", nhưng vẫn là MỞ LẠI vụ đã kết thúc — vẫn
 * đòi lý do, kể cả với luật sư.
 */
it('asks for a reason when a lawyer reopens through an allowed path out of a closing stage', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDays(3)]);
    $matter->matterType->stages()->where('key', 'closed')->update(['allowed_next' => ['appeal']]);
    $matter->matterType->unsetRelation('stages');

    expect(fn () => overrideTransition($matter, $lawyer, 'appeal', null))->toThrow(ValidationException::class);

    overrideTransition($matter, $lawyer, 'appeal', OVERRIDE_REASON);

    expect($matter->fresh()->isClosed())->toBeFalse();
});
