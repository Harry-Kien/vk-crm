<?php

use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Task 6 brief: "trang trả 404 với vụ ngoài quyền". Đã có một bản ở MatterResourceTest, lặp lại
 * ở đây vì đây là test bắt buộc của chính task này và trang giờ có nội dung thật (trước đây trang
 * rỗng — Task 4/5 chỉ dựng route để kiểm tra route-binding).
 */
it('returns 404 for a matter outside the actors scope', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/**
 * SPEC §7.2: mỗi dòng tiến độ hiện rõ đâu là ghi chú nội bộ (nhãn "Nội bộ"). Nhân sự có quyền
 * xem vụ việc (đội ngũ) phải thấy nhãn này. Kiểm tra "không lộ" cho ai không có quyền ở CẢ hai
 * tầng: trang 404 thẳng (không ai đọc được gì), VÀ nếu ai đó địa chỉ thẳng đến chính relation
 * manager của tab Tiến độ (bỏ qua trang), ScopesToVisibleMatters cũng phải chặn — đây là điều
 * brief yêu cầu kiểm tra riêng, không chỉ tin vào 404 của trang cha.
 */
it('shows the internal note marker to a team member but the relation manager itself hides it from an outsider', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    StageLog::factory()->for($matter)->internalOnly()->create(['internal_note' => 'Ghi chú riêng cho nội bộ về vụ này']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertSee(__('matters.stage_log_fields.internal_marker'))
        ->assertSee('Ghi chú riêng cho nội bộ về vụ này');

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($outsider, 'web');

    // Tầng 1: ScopesToVisibleMatters chặn ngay trong chính relation manager, kể cả khi nó được
    // mount trực tiếp (bỏ qua authorizeAccess() của ViewMatter).
    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertDontSee('Ghi chú riêng cho nội bộ về vụ này');

    // Tầng 2: trên thực tế, outsider còn không mở nổi trang — 404 trước khi tới tab nào cả.
    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/**
 * TransitionMatterStage yêu cầu matter.transitionStage (SPEC §5); Assistant không có quyền này
 * (chỉ matter.view + matter.update), nên nút "Chuyển giai đoạn" (và "Thêm cập nhật" — cùng một
 * Action, cùng một điều kiện quyền, xem docblock TransitionMatterStage) phải ẩn với assistant, dù
 * assistant vẫn mở được trang vì có matter.view.
 */
it('hides the transition-stage button from an assistant but shows it to a lawyer on the team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertTableActionHidden('transitionStage')
        ->assertTableActionHidden('addUpdate');

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertTableActionVisible('transitionStage')
        ->assertTableActionVisible('addUpdate');
});

/**
 * SPEC §4.18 / §7.2: dòng đã công bố mà khách đã xem hiện "Khách đã xem lúc …"; chưa xem hiện
 * "Khách chưa xem" và tô vàng nếu quá 5 ngày kể từ khi công bố.
 */
it('shows the viewed label once a client user has viewed a published stage log', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $log = StageLog::factory()->for($matter)->published()->create();
    StageLogView::factory()->for($log, 'stageLog')->create(['viewed_at' => '2026-09-14 21:14:00']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertSee('21:14')
        ->assertDontSee(__('matters.stage_log_fields.not_viewed'));
});

it('highlights the unread label once a published update has gone unread for more than five days', function () {
    expect(StageLogsRelationManager::readReceiptLabel(
        StageLog::factory()->published()->make(['published_at' => now()->subDays(6)])
    )['highlighted'])->toBeTrue();

    expect(StageLogsRelationManager::readReceiptLabel(
        StageLog::factory()->published()->make(['published_at' => now()->subDays(2)])
    )['highlighted'])->toBeFalse();
});

it('toggles portal publication only for someone with matter.update, and the switch actually flips', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('togglePortalPublication')
        ->callAction('togglePortalPublication');

    expect($matter->refresh()->is_published_to_portal)->toBeFalse();
});

/**
 * SPEC §7.2 "Các bên": thêm một bên thì chạy lại RunConflictCheck ngay, hiện kết quả tại chỗ
 * (ở đây bằng một Notification, xem báo cáo task). Bên mới trùng số căn cước với khách hàng đối
 * lập trong một vụ khác phải lên mức đỏ.
 */
it('reruns the conflict check in place when a lawyer adds a party through the Các bên tab', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Người trùng căn cước',
    ])->identify('001099001234', null)->save();

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn mới',
        'id_number' => '001099001234',
    ]);

    Notification::assertNotified(__('matters.parties.conflict_check_title'));

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeTrue();
});
