<?php

use App\Enums\CommunicationType;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\CommunicationLogsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterActivityRelationManager;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Tab "Nhật ký" của riêng một vụ việc (SPEC §7.2, M7 Task 8).
 *
 * Trang Nhật ký hệ thống (M3) đòi `auditLog.view` — chỉ admin và trưởng phòng. Tab này mở thêm
 * cho đúng MỘT người nữa: luật sư phụ trách CỦA VỤ NÀY. Không trợ lý, không cộng sự, không kế
 * toán. Dòng nào thuộc vụ này do CHÍNH luật của `ActivityOwningMatter` quyết, không có định
 * nghĩa thứ hai.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
});

function matterActivityTab(Matter $matter)
{
    return test()->livewire(MatterActivityRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** @return list<class-string> các tab mà trang chi tiết thật sự dựng ra cho người đang đăng nhập. */
function visibleMatterTabs(Matter $matter): array
{
    $page = test()->livewire(ViewMatter::class, ['record' => $matter->getRouteKey()])->instance();

    return array_values(array_map(
        fn ($manager): string => is_string($manager) ? $manager : $manager->relationManager,
        $page->getRelationManagers(),
    ));
}

// =========================================================================================
// AI THẤY TAB
// =========================================================================================

it('shows the tab to the lead of this matter, a manager and an admin', function () {
    $this->actingAs($this->lead, 'web');
    expect(visibleMatterTabs($this->matter))->toContain(MatterActivityRelationManager::class);

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');
    expect(visibleMatterTabs($this->matter))->toContain(MatterActivityRelationManager::class);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');
    expect(visibleMatterTabs($this->matter))->toContain(MatterActivityRelationManager::class);
});

/**
 * Cộng sự và trợ lý TRONG đội vẫn mở được trang (và thấy tab Liên lạc), nhưng không thấy tab
 * Nhật ký — và một lần mount/ép gọi Livewire trả 404, không phải 403.
 */
it('hides the tab from an associate and an assistant on the team, and answers a forced mount with 404', function () {
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($associate, MatterRole::Associate);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    foreach ([$associate, $assistant] as $member) {
        $this->actingAs($member, 'web');

        expect(visibleMatterTabs($this->matter))
            ->toContain(CommunicationLogsRelationManager::class)
            ->not->toContain(MatterActivityRelationManager::class);

        matterActivityTab($this->matter)->assertNotFound();
    }
});

it('hides the tab from a lawyer who led the matter before it was handed over, on the next Livewire request', function () {
    $this->actingAs($this->lead, 'web');

    $component = matterActivityTab($this->matter)->assertOk();

    // Người cũ vẫn trong đội ngũ (dòng `matter_user` của họ ở nguyên) — chỉ `lead_lawyer_id` đổi.
    $this->matter->update(['lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id]);

    $component->call('$refresh')->assertNotFound();
});

it('is out of reach for an accountant', function () {
    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');

    expect(MatterActivityRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();
    matterActivityTab($this->matter)->assertNotFound();
});

/** Trưởng phòng không lead một vụ `restricted`: không mở được TRANG, nên càng không có tab. */
it('keeps a manager out of a restricted matter they do not lead, page and tab', function () {
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    $this->actingAs($manager, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin'))
        ->assertNotFound();

    expect(MatterActivityRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();
    matterActivityTab($this->matter)->assertNotFound();

    // Vế dương: luật sư phụ trách của chính vụ `restricted` đó vẫn đọc được nhật ký của nó.
    $this->actingAs($this->lead, 'web');
    matterActivityTab($this->matter)->assertOk();
});

// =========================================================================================
// DÒNG NÀO THUỘC VỤ NÀY
// =========================================================================================

it('lists the rows of this matter and of its children, and none of another matter or of no matter', function () {
    $other = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    $ownDeadline = Deadline::factory()->for($this->matter)->create();
    $ownParty = MatterParty::factory()->create(['matter_id' => $this->matter->id]);
    $ownLog = CommunicationLog::factory()->for($this->matter)->create();
    $otherDeadline = Deadline::factory()->for($other)->create();

    $own = [
        Audit::record('matter_details_updated', $this->matter, [], $this->lead),
        Audit::record('deadline_added', $ownDeadline, ['matter_id' => $this->matter->id], $this->lead),
        Audit::record('matter_party_updated', $ownParty, [], $this->lead),
        Audit::record('communication_logged', $ownLog, ['matter_id' => $this->matter->id], $this->lead),
        // Không chủ thể, chỉ `properties.matter_id`.
        Audit::record('client_lookup', null, ['matter_id' => $this->matter->id], $this->lead),
    ];

    $foreign = [
        Audit::record('matter_details_updated', $other, [], $this->lead),
        Audit::record('deadline_added', $otherDeadline, [], $this->lead),
        // Chủ thể thuộc vụ KHÁC thắng `properties.matter_id` (bước 2 trước bước 3).
        Audit::record('deadline_updated', $otherDeadline, ['matter_id' => $this->matter->id], $this->lead),
        Audit::record('client_lookup', null, ['matter_id' => $other->id], $this->lead),
        Audit::record('login_success', $this->lead, ['guard' => 'web'], $this->lead),
    ];

    $this->actingAs($this->lead, 'web');

    matterActivityTab($this->matter)
        ->assertCanSeeTableRecords($own)
        ->assertCanNotSeeTableRecords($foreign);

    // Chéo: tab của vụ kia thấy đúng phần của nó.
    matterActivityTab($other)
        ->assertCanSeeTableRecords([$foreign[0], $foreign[1], $foreign[2], $foreign[3]])
        ->assertCanNotSeeTableRecords($own);
});

/**
 * Nút "Xem chi tiết" phân giải bản ghi trên CHÍNH truy vấn của tab: một khoá dòng của vụ khác —
 * ở đây một vụ `restricted` mà luật sư này không ở trong — ép vào lời gọi không mở được modal,
 * nên không giá trị nào của dòng đó được dựng ra.
 *
 * Nội dung modal không nằm trong HTML mà bộ test Livewire trả về, nên đo trên danh sách action
 * đang mở và trên chính `getModalContent()` (như ca che số điện thoại ở dưới), không bằng
 * `assertDontSee` — thứ sẽ xanh cả khi modal đã mở.
 */
it('cannot open the details of a row of another matter by forcing its key', function () {
    $other = Matter::factory()->create(['confidentiality' => Confidentiality::Restricted]);
    $foreign = Audit::record('matter_details_updated', $other, ['note' => 'Bí mật của vụ khác'], $this->lead);

    $this->actingAs($this->lead, 'web');

    $component = matterActivityTab($this->matter)->mountTableAction('viewProperties', $foreign);

    expect($component->instance()->mountedActions)->toBe([])
        ->and($component->instance()->getMountedActions())->toBe([]);

    // Vế dương: cùng lời gọi trên một dòng của chính vụ này mở được modal và dựng giá trị của nó —
    // nên vế âm ở trên không xanh vì lời gọi hỏng, mà vì khoá kia không có trong truy vấn của tab.
    $own = Audit::record('matter_details_updated', $this->matter, ['note' => 'Ghi chú của vụ này'], $this->lead);

    $component = matterActivityTab($this->matter)->mountTableAction('viewProperties', $own);
    $mounted = $component->instance()->getMountedActions();

    expect($mounted)->toHaveCount(1)
        ->and((string) $mounted[0]->getModalContent())->toContain('Ghi chú của vụ này');
});

it('labels each row with the Vietnamese name of its event and who did it', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create(['type' => CommunicationType::CallIn]);
    Audit::record('communication_logged', $log, ['matter_id' => $this->matter->id], $this->lead);

    $this->actingAs($this->lead, 'web');

    matterActivityTab($this->matter)
        ->assertSee(__('activity.events.communication_logged'))
        ->assertSee('Luật sư Vũ Khang')
        ->assertDontSee('communication_logged');
});

it('masks contact details in the details modal, as the system page does', function () {
    $activity = Audit::record('matter_details_updated', $this->matter, [
        'phone' => '0912345678',
        'email' => 'khach@example.com',
        'id_number' => '001099012345',
    ], $this->lead);

    $this->actingAs($this->lead, 'web');

    $component = matterActivityTab($this->matter)->mountTableAction('viewProperties', $activity);

    $mounted = $component->instance()->getMountedActions();
    $html = (string) $mounted[0]->getModalContent();

    expect($html)->not->toContain('0912345678')
        ->not->toContain('khach@example.com')
        ->not->toContain('001099012345')
        ->toContain('k•••@example.com');
});

/**
 * Chiều ngược lại của tab: dòng audit của một nhật ký liên lạc trên vụ `restricted` không được lọt
 * ra trang Nhật ký HỆ THỐNG cho một trưởng phòng ngoài vụ — vì `communication_log` nay nằm trong
 * `ActivityOwningMatter::MATTER_OWNED`.
 */
it('keeps the audit row of a communication log on a restricted matter off the system page for a manager', function () {
    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);
    $log = CommunicationLog::factory()->for($this->matter)->create();

    // Không `properties.matter_id`: chỉ có chủ thể để quy về vụ việc.
    $row = Audit::record('communication_log_deleted', $log, ['reason' => 'Nhập trùng.'], $this->lead);

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');
    $this->livewire(ActivityLogPage::class)->assertCanNotSeeTableRecords([$row]);

    $this->actingAs(User::factory()->withRole(Role::Admin)->create(), 'web');
    $this->livewire(ActivityLogPage::class)->assertCanSeeTableRecords([$row]);
});

it('lists the rows of a communication log that has since been soft-deleted', function () {
    $log = CommunicationLog::factory()->for($this->matter)->create();
    $row = Audit::record('communication_log_deleted', $log, ['reason' => 'Nhập trùng.'], $this->lead);
    $log->delete();

    $this->actingAs($this->lead, 'web');

    matterActivityTab($this->matter)->assertCanSeeTableRecords([$row]);
});
