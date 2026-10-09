<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\Matter\RestoreMatter;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fm, mục A4 (kiểm tra nghiệp vụ 2026-10-09): "Huỷ hồ sơ mở nhầm" chỉ cho vụ chưa từng kết thúc
 * (vụ đã đóng mà bị huỷ thì thoát khỏi hạn lưu trữ và tiêu huỷ); hộp thoại nói rõ việc còn dở và
 * đường khôi phục; quản trị viên thấy danh sách hồ sơ đã huỷ và khôi phục được, có lý do và nhật ký.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

const CANCEL_REASON = 'Mở nhầm khách hàng, mở lại vụ việc đúng.';

/** @return list<string> */
function mountedTexts(Testable $component): array
{
    $formName = $component->instance()->getMountedActionSchemaName();
    /** @var Schema $schema */
    $schema = $component->instance()->{$formName};

    return collect($schema->getFlatComponents())
        ->filter(fn ($c) => $c instanceof Text && $c->isVisible())
        ->map(fn ($c) => (string) $c->getContent())
        ->values()
        ->all();
}

it('refuses to cancel a closed matter, in the action and on the edit screen', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subYear()]);

    $this->actingAs($admin, 'web');

    $this->livewire(EditMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('cancelMatter');

    try {
        app(CancelMatter::class)->handle($matter, $admin, CANCEL_REASON);
        $this->fail('Không ném ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['reason' => [__('lifecycle.cancel.closed_refused')]]);
    }

    expect($matter->fresh()->trashed())->toBeFalse();
});

it('refuses to cancel a reopened matter that already has an archive row', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['closed_at' => null]);
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => null]);

    expect(fn () => app(CancelMatter::class)->handle($matter, $admin, CANCEL_REASON))
        ->toThrow(ValidationException::class);

    expect($matter->fresh()->trashed())->toBeFalse();
});

it('shows the open deadlines, the portal state and the way back on the cancel dialog', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['is_published_to_portal' => true]);
    Deadline::factory()->for($matter)->create(['name' => 'Hạn nộp bản tự khai', 'due_date' => '2026-11-30']);

    $this->actingAs($admin, 'web');

    $texts = implode("\n", mountedTexts(
        $this->livewire(EditMatter::class, ['record' => $matter->getKey()])->mountAction('cancelMatter'),
    ));

    expect($texts)->toContain('Hạn nộp bản tự khai (hạn 30/11/2026)')
        ->toContain(__('lifecycle.open_work.on_portal'))
        ->toContain(__('lifecycle.cancel.consequences'));
});

it('lets an admin list cancelled matters and restore one with a reason', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $cancelled = Matter::factory()->create();
    $live = Matter::factory()->create();
    app(CancelMatter::class)->handle($cancelled, $admin, CANCEL_REASON);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(ListMatters::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$cancelled])
        ->filterTable('cancelled', true)
        ->assertCanSeeTableRecords([$cancelled])
        ->assertCanNotSeeTableRecords([$live])
        ->assertTableActionHidden('view', $cancelled)
        ->assertTableActionVisible('restore', $cancelled);

    $component->callTableAction('restore', $cancelled, data: ['reason' => 'Huỷ nhầm vụ thật, khôi phục lại.'])
        ->assertHasNoTableActionErrors();

    expect($cancelled->fresh()->trashed())->toBeFalse();

    $activity = Activity::query()->where('event', 'matter_restored')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($cancelled->id)
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->properties['reason'])->toBe('Huỷ nhầm vụ thật, khôi phục lại.');
});

it('asks for a reason before restoring', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $cancelled = Matter::factory()->create();
    app(CancelMatter::class)->handle($cancelled, $admin, CANCEL_REASON);

    $this->actingAs($admin, 'web');

    $this->livewire(ListMatters::class)
        ->filterTable('cancelled', true)
        ->callTableAction('restore', $cancelled, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($cancelled->fresh()->trashed())->toBeTrue();
    expect(fn () => app(RestoreMatter::class)->handle($cancelled, $admin, '   '))
        ->toThrow(ValidationException::class);
});

it('keeps cancelled matters and the restore path away from everyone but an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $cancelled = Matter::factory()->create();
    app(CancelMatter::class)->handle($cancelled, $admin, CANCEL_REASON);

    $this->actingAs($manager, 'web');

    $component = $this->livewire(ListMatters::class);

    expect(collect($component->instance()->getTable()->getFilters())->get('cancelled')?->isVisible() ?? false)->toBeFalse();

    // Một payload dàn dựng bật bộ lọc ẩn cũng không lộ hồ sơ đã huỷ.
    $component->set('tableFilters.cancelled.value', true)
        ->assertCanNotSeeTableRecords([$cancelled]);

    expect(fn () => app(RestoreMatter::class)->handle($cancelled, $manager, 'Khôi phục giúp quản trị viên.'))
        ->toThrow(AuthorizationException::class);

    expect($cancelled->fresh()->trashed())->toBeTrue();
});

it('refuses to restore a matter that is not cancelled', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(RestoreMatter::class)->handle($matter, $admin, 'Khôi phục một vụ đang chạy.'))
        ->toThrow(ValidationException::class);
});
