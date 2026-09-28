<?php

use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Models\Client;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

/**
 * Fix round 1 (I1, ruling): "the activity viewer must not show raw contact data" — dòng nhật ký
 * này KHÔNG đi qua `Audit::record()` như test ở trên, mà là dòng "updated" mà
 * `Spatie\LogsActivity` tự sinh khi sửa `Client` (xem `Client::getActivitylogOptions()` —
 * `logOnly` giữ nguyên `phone`/`email`/`address`, không loại như `id_number`), nên `properties`
 * mang đúng hình dạng thật `{"attributes": {...mới...}, "old": {...cũ...}}` mà phát hiện gốc mô
 * tả, không phải một cấu trúc test tự dựng.
 */
it('masks a client edit\'s phone, email and address in the details modal, in both the new and old diff', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $client = Client::factory()->create([
        'phone' => '0912345678',
        'email' => 'nam@luatvukhang.com',
        'address' => '123 Đường Láng, Đống Đa',
    ]);

    $client->update([
        'phone' => '0987654321',
        'email' => 'khac@luatvukhang.com',
        'address' => '456 Đường Mới, Ba Đình',
    ]);

    $activity = Activity::query()
        ->where('subject_type', $client->getMorphClass())
        ->where('subject_id', $client->id)
        ->latest('id')
        ->first();

    $component = $this->livewire(ActivityLogPage::class)
        ->mountTableAction('viewProperties', $activity);

    $modalContent = (string) $component->instance()->getMountedActions()[0]->getModalContent();

    expect($modalContent)
        // attributes (giá trị MỚI)
        ->toContain('09•••321')
        ->toContain('k•••@luatvukhang.com')
        ->toContain('456…')
        // old (giá trị CŨ)
        ->toContain('09•••678')
        ->toContain('n•••@luatvukhang.com')
        ->toContain('123…')
        // không còn số/địa chỉ thô nào, mới lẫn cũ
        ->not->toContain('0987654321')
        ->not->toContain('0912345678')
        ->not->toContain('khac@luatvukhang.com')
        ->not->toContain('nam@luatvukhang.com')
        ->not->toContain('456 Đường Mới, Ba Đình')
        ->not->toContain('123 Đường Láng, Đống Đa');
});

// -------------------------------------------------------------------------------------------
// Final review X1 (A-C1 = C-C1): trang nhật ký không được là cửa sau vào nội dung một vụ
// `restricted`. Mỗi dòng được quy về vụ việc sở hữu nó (`ActivityOwningMatter`); với người xem
// không phải admin, bảng KHÔNG liệt kê dòng thuộc một vụ họ không `view` được, và modal "Xem chi
// tiết" tự hỏi lại luật đó.
// -------------------------------------------------------------------------------------------

/**
 * @return array{0: User, 1: Matter}
 */
function restrictedMatterLedByAnotherLawyer(): array
{
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $lead->id,
        'title' => 'Vụ tuyệt mật XYZ',
    ]);

    return [$lead, $matter];
}

function mountedPropertiesOf($component): ?string
{
    $mounted = $component->instance()->getMountedActions();

    return $mounted === [] ? null : (string) $mounted[0]->getModalContent();
}

it('does not list, nor open the details of, a restricted matter\'s row for a manager outside it', function () {
    [$lead, $matter] = restrictedMatterLedByAnotherLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $activity = Audit::record('matter_details_updated', $matter, ['title' => 'Vụ tuyệt mật XYZ'], $lead);

    $this->actingAs($manager, 'web');

    $component = $this->livewire(ActivityLogPage::class)
        ->assertCanNotSeeTableRecords([$activity]);

    try {
        $component->mountTableAction('viewProperties', $activity);
    } catch (Throwable) {
        // Filament không tìm thấy bản ghi trong truy vấn bảng — cũng là một lần từ chối.
    }

    expect(mountedPropertiesOf($component))->toBeNull();
});

it('hides a restricted matter\'s child rows (party, document, deadline) and property-linked rows from a manager', function () {
    [$lead, $matter] = restrictedMatterLedByAnotherLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $party = MatterParty::factory()->create(['matter_id' => $matter->id]);
    $document = Document::factory()->create(['matter_id' => $matter->id]);
    $deadline = Deadline::factory()->create(['matter_id' => $matter->id]);

    $rows = [
        Audit::record('matter_party_updated', $party, [], $lead),
        Audit::record('document_published', $document, [], $lead),
        Audit::record('deadline_added', $deadline, [], $lead),
        // Không chủ thể, chỉ properties.matter_id.
        Audit::record('client_lookup', null, ['matter_id' => $matter->id], $lead),
    ];

    $this->actingAs($manager, 'web');

    $this->livewire(ActivityLogPage::class)->assertCanNotSeeTableRecords($rows);
});

it('hides a row whose matter-owned subject no longer resolves to any matter from a manager, but not from an admin', function () {
    [$lead, $matter] = restrictedMatterLedByAnotherLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $party = MatterParty::factory()->create(['matter_id' => $matter->id]);
    $activity = Audit::record('matter_party_updated', $party, [], $lead);

    DB::table('matter_parties')->where('id', $party->id)->delete();

    $this->actingAs($manager, 'web');
    $this->livewire(ActivityLogPage::class)->assertCanNotSeeTableRecords([$activity]);

    $this->actingAs($admin, 'web');
    $this->livewire(ActivityLogPage::class)->assertCanSeeTableRecords([$activity]);
});

it('keeps listing a normal matter\'s rows and rows with no matter at all for a manager', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->create(['matter_id' => $matter->id]);

    $rows = [
        Audit::record('matter_details_updated', $matter, [], $manager),
        Audit::record('matter_party_updated', $party, [], $manager),
        Audit::record('client_lookup', null, ['matter_id' => $matter->id], $manager),
        Audit::record('login_success', $manager, ['guard' => 'web'], $manager),
    ];

    $this->actingAs($manager, 'web');

    $component = $this->livewire(ActivityLogPage::class)->assertCanSeeTableRecords($rows);

    $component->mountTableAction('viewProperties', $rows[3]);
    expect(mountedPropertiesOf($component))->toContain('web');
});

it('lets an admin list and open a restricted matter\'s row', function () {
    [$lead, $matter] = restrictedMatterLedByAnotherLawyer();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $activity = Audit::record('matter_details_updated', $matter, ['title' => 'Vụ tuyệt mật XYZ'], $lead);

    $this->actingAs($admin, 'web');

    $component = $this->livewire(ActivityLogPage::class)
        ->assertCanSeeTableRecords([$activity])
        ->mountTableAction('viewProperties', $activity);

    expect(mountedPropertiesOf($component))->toContain('Vụ tuyệt mật XYZ');
});

/**
 * Trang nhật ký chỉ mở cho auditLog.view (admin, trưởng phòng). "Người trong đội vụ restricted"
 * có quyền đó ở đây là một trưởng phòng làm LUẬT SƯ PHỤ TRÁCH của vụ — `Matter::scopeListableBy`
 * chỉ mở vụ `restricted` cho lead và admin.
 */
it('lets a manager who leads the restricted matter list and open its row', function () {
    $managerLead = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $managerLead->id]);

    $activity = Audit::record('matter_details_updated', $matter, ['title' => 'Vụ tuyệt mật XYZ'], $managerLead);

    $this->actingAs($managerLead, 'web');

    $component = $this->livewire(ActivityLogPage::class)
        ->assertCanSeeTableRecords([$activity])
        ->mountTableAction('viewProperties', $activity);

    expect(mountedPropertiesOf($component))->toContain('Vụ tuyệt mật XYZ');
});

/**
 * Final review X8: dòng `client_lookup` ghi `identifier_hash` (HMAC có khoá). Modal "Xem chi
 * tiết" không hiện nó, kể cả cho admin — xem `SensitivePropertyFilter`, mọi khoá `*_hash`.
 */
it('never shows an identifier hash in the details modal, even to an admin', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $this->livewire(CreateMatter::class)
        ->call('lookupClient', '0912345678');

    $activity = Activity::query()->where('event', 'client_lookup')->latest('id')->firstOrFail();
    $stored = $activity->properties->get('identifier_hash');

    expect($stored)->toBeString()->not->toBe(hash('sha256', '0912345678'));

    $component = $this->livewire(ActivityLogPage::class)
        ->mountTableAction('viewProperties', $activity);

    expect(mountedPropertiesOf($component))
        ->not->toContain($stored)
        ->toContain(__('activity.page.properties.redacted'));
});

/**
 * Final review C-M2: (1) `restored` — sự kiện `LogsActivity` sinh khi khôi phục một bản ghi xoá mềm —
 * thiếu nhãn tiếng Việt, cột "Sự kiện" hiện nguyên khoá dịch; (2) cột "Mô tả" hiện nguyên mã sự
 * kiện (`matter_details_updated`), vì `Audit::record()` và `LogsActivity` đều ghi mã đó làm mô tả.
 */
it('labels the restored event in Vietnamese', function () {
    expect(__('activity.events.restored'))->not->toBe('activity.events.restored');

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin, 'web');

    $client = Client::factory()->create();
    $client->delete();
    $client->restore();

    $restored = Activity::query()->where('event', 'restored')->latest('id')->firstOrFail();

    $this->livewire(ActivityLogPage::class)
        ->assertTableColumnFormattedStateSet('event', __('activity.events.restored'), $restored)
        ->assertTableColumnFormattedStateSet('description', __('activity.events.restored'), $restored);
});

it('shows the translated label in the description column, not the raw event code', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();
    $activity = Audit::record('matter_details_updated', $matter, [], $admin);

    $this->actingAs($admin, 'web');

    $this->livewire(ActivityLogPage::class)
        ->assertTableColumnFormattedStateSet('description', __('activity.events.matter_details_updated'), $activity)
        ->assertTableColumnFormattedStateNotSet('description', 'matter_details_updated', $activity);
});

/**
 * Final review wave 2, M-2: `authorize()` của nút "Xem chi tiết" hỏi luật X1 cho TỪNG dòng — mỗi
 * dòng vài truy vấn (dòng con → vụ việc → Gate). Một trang 25 dòng là ~75 truy vấn chỉ để vẽ
 * nút. Trang giải quyết vụ việc sở hữu của CẢ trang một lần (theo lô), nhớ trong request.
 */
it('keeps the query count of a 25-row page flat for a manager, not three queries per row', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();

    $count = function (int $rows) use ($manager): int {
        Activity::query()->delete();

        foreach (range(1, $rows) as $i) {
            $party = MatterParty::factory()->create();
            Audit::record('matter_party_updated', $party, ['matter_id' => $party->matter_id], $manager);
        }

        $this->actingAs($manager, 'web');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->livewire(ActivityLogPage::class)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(2);
    $many = $count(20);

    // 18 dòng nữa không được kéo theo mấy chục truy vấn nữa.
    expect($many - $few)->toBeLessThan(10);
});
