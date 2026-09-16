<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** auditLog.view chỉ admin và manager có (SPEC §5). */
it('forbids a lawyer without auditLog.view from opening the activity log page', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'))->assertForbidden();
});

it('lets an admin open the activity log page and see a logged change', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');
    $client = Client::factory()->create(['name' => 'Khách hàng ban đầu']);
    $client->update(['name' => 'Khách hàng đã sửa']);

    $this->actingAs($admin, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'))->assertOk();

    expect(Activity::query()->where('subject_id', $client->id)->where('subject_type', $client->getMorphClass())->exists())->toBeTrue();
});

it('lets a manager open the activity log page', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'))->assertOk();
});
