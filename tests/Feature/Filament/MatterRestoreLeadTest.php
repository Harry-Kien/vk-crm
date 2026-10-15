<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\Matter\RestoreMatter;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fi, A3 — khôi phục một hồ sơ đã huỷ không được để vụ mở lại dưới tên một người không còn xử lý được
 * nó (đã chuyển sang Nhân viên kinh doanh / chức danh không phụ trách vụ, bị khoá truy cập, đã xoá).
 * Phán quyết: hộp thoại "Khôi phục" đòi chọn người phụ trách mới trong trường hợp đó — "bàn giao trước"
 * không làm được, vì "Bàn giao" (`ReassignMatter`) không mở được hồ sơ đã huỷ. Thêm: `RestoreMatter` và
 * `ExtendClientAccess` từ chối người thao tác có tài khoản đã bị vô hiệu hoá. Hàm toàn cục tiền tố `mrl…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Cũ']);
    $this->newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Mới']);
});

const MRL_REASON = 'Huỷ nhầm vụ thật, khôi phục lại.';

function mrlCancelled(User $lead): Matter
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'closed_at' => null]);
    app(CancelMatter::class)->handle($matter, test()->admin, 'Mở nhầm khách hàng, mở lại vụ việc đúng.');

    return $matter;
}

function mrlList()
{
    test()->actingAs(test()->admin, 'web');

    return test()->livewire(ListMatters::class)->filterTable('cancelled', true);
}

dataset('unavailable leads', [
    'moved to sales' => fn () => tap(test()->lead)->update(['position' => 'sales'])->assignRoleFromPosition(),
    'suspended' => fn () => tap(test()->lead)->forceFill(['is_active' => false])->save(),
    'deleted' => fn () => tap(test()->lead)->delete(),
]);

it('asks for a new lead before restoring a matter whose lead can no longer handle it, and restores nothing without one', function (Closure $makeUnavailable) {
    $matter = mrlCancelled($this->lead);
    $makeUnavailable();

    mrlList()
        ->assertTableActionVisible('restore', $matter)
        ->callTableAction('restore', $matter, data: ['reason' => MRL_REASON])
        ->assertHasTableActionErrors(['new_lead_id']);

    expect($matter->fresh()->trashed())->toBeTrue();
})->with('unavailable leads');

it('restores the matter under the new lead chosen in the dialog', function (Closure $makeUnavailable) {
    $matter = mrlCancelled($this->lead);
    $makeUnavailable();

    mrlList()
        ->callTableAction('restore', $matter, data: ['reason' => MRL_REASON, 'new_lead_id' => $this->newLead->id])
        ->assertHasNoTableActionErrors();

    $matter = Matter::query()->findOrFail($matter->id);

    expect($matter->lead_lawyer_id)->toBe($this->newLead->id)
        ->and(Activity::query()->where('event', 'matter_restored')->sole()->properties['new_lead_id'])->toBe($this->newLead->id);

    // Người phụ trách mới mở được vụ vừa khôi phục.
    $this->actingAs($this->newLead, 'web');
    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])->assertOk();
})->with('unavailable leads');

/*
 * Bất biến của Action, dưới khoá: màn hình hiện ô chọn ngay khi người phụ trách không còn xử lý được vụ
 * (test trên), nên đường "không chọn ai" chỉ còn tới được qua một cuộc đua giữa lúc kiểm form và lúc
 * khoá dòng — đo ở tầng Action. Người phụ trách mới không đứng tên được (trợ lý) thì `ReassignMatter`
 * từ chối, và transaction hoàn lại cả lần khôi phục.
 */
it('refuses, under the lock, a restore with no new lead or with one who cannot lead, and restores nothing', function () {
    $matter = mrlCancelled($this->lead);
    $this->lead->forceFill(['is_active' => false])->save();

    expect(fn () => app(RestoreMatter::class)->handle($matter, $this->admin, MRL_REASON))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe([
            'new_lead_id' => [__('lifecycle.restore.lead_unavailable', ['name' => 'Luật Sư Cũ'])],
        ]));

    $assistant = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => app(RestoreMatter::class)->handle($matter, $this->admin, MRL_REASON, $assistant))
        ->toThrow(ValidationException::class);

    expect($matter->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'matter_restored')->exists())->toBeFalse();
});

it('does not ask for a new lead when the lead can still handle the matter', function () {
    $matter = mrlCancelled($this->lead);

    mrlList()
        ->callTableAction('restore', $matter, data: ['reason' => MRL_REASON])
        ->assertHasNoTableActionErrors();

    expect(Matter::query()->findOrFail($matter->id)->lead_lawyer_id)->toBe($this->lead->id)
        ->and(Activity::query()->where('event', 'matter_restored')->sole()->properties->has('new_lead_id'))->toBeFalse();
});

it('refuses a restore by an admin whose account was disabled while the dialog was open', function () {
    $matter = mrlCancelled($this->lead);

    $component = mrlList();
    $this->admin->forceFill(['is_active' => false])->save();

    $component->callTableAction('restore', $matter, data: ['reason' => MRL_REASON])
        ->assertNotified(__('actions.unauthorized'));

    expect($matter->fresh()->trashed())->toBeTrue();
});

it('refuses to extend client access for a lawyer whose account was disabled while the page was open', function () {
    Carbon::setTestNow('2026-06-05 09:00:00');

    $matter = Matter::factory()->atStage('closed')->create([
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
        'closed_at' => '2026-03-01',
    ]);
    MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'client_access_until' => '2026-05-30',
        'retention_until' => '2036-03-01',
    ]);

    $this->actingAs($this->lead, 'web');

    $component = $this->livewire(ViewMatter::class, ['record' => $matter->getKey()]);
    $this->lead->forceFill(['is_active' => false])->save();

    $component->callAction('extendClientAccess', data: ['until' => '2026-07-05', 'reason' => 'Khách xin thêm thời gian để tải.'])
        ->assertNotified(__('actions.failed_title'));

    expect(MatterArchive::query()->where('matter_id', $matter->id)->first()->client_access_until->toDateString())->toBe('2026-05-30');

    Carbon::setTestNow();
});
