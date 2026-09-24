<?php

use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Models\Client;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** auditLog.view chỉ admin và manager có (SPEC §5). */
it('hides the activity log page from a lawyer without auditLog.view', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    // Panel từ chối bằng 404 (SPEC §10.10, xem DenialCodeTest và
    // AnswerDeniedPanelRequestsWithNotFound).
    $this->actingAs($lawyer, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'))->assertNotFound();
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

/**
 * Review fix round 1, Important #1: bốn sự kiện M3 thực sự ghi qua Audit::record()
 * (matter_opened, matter_stage_transitioned, matter_party_added, conflict_check_run) thiếu
 * trong lang/vi/activity.php, nên cột "Sự kiện" hiện nguyên khoá dịch thay vì nhãn tiếng Việt.
 * Test này ghi thẳng một dòng activity qua Audit::record() (không cần chạy trọn Action) và xác
 * nhận trang render đúng nhãn, không phải khoá trần.
 */
it('renders a translated label for the M3 audit events, not the raw translation key', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    Audit::record('matter_opened', $matter, [], $admin);

    $response = $this->actingAs($admin, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertSee(__('activity.events.matter_opened'));
    $response->assertDontSee('activity.events.matter_opened');
});

/**
 * M6.5 Task 3, fix round 1 ("also fix"): `team_member_added`/`team_member_removed`
 * (`App\Actions\Matter\{AddTeamMember,RemoveTeamMember}`) thiếu trong `lang/vi/activity.php` —
 * cùng lỗ hổng đã sửa cho bốn sự kiện M3 ở test trên, cùng cách đo.
 */
it('renders a translated label for the team member events, not the raw translation key', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    Audit::record('team_member_added', $matter, [], $admin);
    Audit::record('team_member_removed', $matter, [], $admin);

    $response = $this->actingAs($admin, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertSee(__('activity.events.team_member_added'));
    $response->assertSee(__('activity.events.team_member_removed'));
    $response->assertDontSee('activity.events.team_member_added');
    $response->assertDontSee('activity.events.team_member_removed');
});
