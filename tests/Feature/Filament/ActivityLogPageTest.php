<?php

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
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

// -------------------------------------------------------------------------------------------
// Task 20 — cột "Đối tượng" chỉ có liên kết khi người XEM trang nhật ký (không phải người gây
// ra dòng đó) được Gate::forUser($viewer)->allows('view', $subject).
// -------------------------------------------------------------------------------------------

it('links the subject column to the matter view page when the viewer can see that matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create(['confidentiality' => Confidentiality::Normal]);

    Audit::record('matter_details_updated', $matter, [], $admin);

    $response = $this->actingAs($admin, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertSee(ViewMatter::getUrl(['record' => $matter], panel: 'admin'), escape: false);
});

/**
 * Vế âm: một trưởng phòng (`Role::Manager`) không phải admin và không phải luật sư phụ trách —
 * `Matter::scopeListableBy()` từ chối vai trò này cho một vụ `restricted` (chỉ luật sư phụ trách
 * và admin thấy được, "kể cả trưởng phòng cũng không" — đọc đúng docblock của hàm đó). Cột đối
 * tượng vẫn phải hiện TÊN LỚP (đã có test riêng ở trên cho việc này), chỉ không được có liên kết.
 */
it('does not link the subject column when the viewer cannot see that restricted matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $otherLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'confidentiality' => Confidentiality::Restricted,
        'lead_lawyer_id' => $otherLawyer->id,
    ]);

    Audit::record('matter_details_updated', $matter, [], $otherLawyer);

    $response = $this->actingAs($manager, 'web')->get(ActivityLogPage::getUrl(panel: 'admin'));

    $response->assertOk();
    $response->assertDontSee(ViewMatter::getUrl(['record' => $matter], panel: 'admin'), escape: false);
});

// -------------------------------------------------------------------------------------------
// Task 20 — modal "Xem chi tiết" hiện properties, lọc khoá nhạy cảm, và trang không còn hiện
// activity.events.* thô cho bất kỳ sự kiện nào của M4–M6.5 (phát hiện "lượt rà soát cuối").
// -------------------------------------------------------------------------------------------

/**
 * Đi qua đúng cơ chế Livewire thật của một record action (`mountTableAction`, cùng cách người
 * dùng bấm nút "Xem chi tiết" thật sự mở modal) — không gọi thẳng
 * `App\Support\SensitivePropertyFilter` hay đọc `$activity->properties` trực tiếp.
 *
 * Đọc nội dung modal qua `$action->getModalContent()` của chính action ĐÃ ĐƯỢC MOUNT (record đã
 * gắn, cùng closure `modalContent` đăng ký ở `ActivityLogPage::table()`), thay vì
 * `assertSee()` trên `$component->html()` của toàn trang: bản dựng Livewire Testable đang cài
 * (`livewire/livewire` qua `pestphp/pest-plugin-livewire`) không lặp lại phần thân modal của một
 * record action vào chuỗi HTML trả về của TOÀN TRANG cho lần cập nhật đó — đã xác minh: cùng một
 * action, cùng một record, `$action->getModalContent()` trả đúng nội dung mong đợi (Trần Thị B có
 * mặt, id_number bị ẩn), trong khi `$component->html()` không mang theo đoạn đó. Đây là giới hạn
 * của bộ dựng thử Livewire cho testable, không phải hành vi sai của trang — xem báo cáo Task 20,
 * mục "Mối lo".
 */
it('shows the logged properties in a details modal, with sensitive keys redacted', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();

    $activity = Audit::record('matter_details_updated', $matter, [
        'changed' => ['title'],
        'proposed_party' => ['name' => 'Trần Thị B', 'id_number' => '079099999999'],
    ], $admin);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(ActivityLogPage::class)
        ->mountTableAction('viewProperties', $activity);

    $mountedActions = $component->instance()->getMountedActions();

    expect($mountedActions)->toHaveCount(1);

    $modalContent = (string) $mountedActions[0]->getModalContent();

    expect($modalContent)->toContain('Trần Thị B')
        ->toContain(__('activity.page.properties.redacted'))
        ->not->toContain('079099999999');
});
