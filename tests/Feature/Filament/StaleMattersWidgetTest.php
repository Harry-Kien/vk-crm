<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Widgets\MatterCountsWidget;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** SPEC §7.1 mục 1: chỉ vụ việc chưa cập nhật cho khách quá 14 ngày, chưa đóng. */
it('only lists matters whose last client update is older than 14 days', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $stale = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(20),
    ]);
    $fresh = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(2),
    ]);
    $neverUpdated = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => null,
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StaleMattersWidget::class)
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$fresh, $neverUpdated]);
});

/** Widget quan trọng nhất phải giới hạn theo listableBy(): một luật sư chỉ thấy hồ sơ quá hạn của mình. */
it('shows a lawyer only their own overdue matters, not the whole office', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    $own = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(30),
    ]);
    $colleagues = Matter::factory()->create([
        'lead_lawyer_id' => $colleague->id,
        'last_client_update_at' => now()->subDays(30),
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StaleMattersWidget::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$colleagues]);
});

it('hides the widget from an accountant, who has no matter.view', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(StaleMattersWidget::canView())->toBeFalse();
});

it('shows the widget to a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web');

    expect(StaleMattersWidget::canView())->toBeTrue();
});

// --- Fix round 2 (review, task 3: the widget hid the worst case) ---------------------------

/**
 * SPEC §6.4: a matter with NO last_client_update_at at all — the worst case — is exactly what
 * the widget exists to surface. The old `whereNotNull('last_client_update_at')` made this
 * unreachable. The effective clock, absent a real update, is stage_entered_at (see the widget's
 * docblock for why); backdate it here since Matter::booted() otherwise stamps it at "now".
 */
it('lists a matter that has never been updated for the client, using stage_entered_at as the clock', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $neverUpdated = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => null,
        'stage_entered_at' => now()->subDays(20),
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StaleMattersWidget::class)
        ->assertCanSeeTableRecords([$neverUpdated]);
});

/** SPEC §6.4 requires is_published_to_portal = true; the old query omitted this condition entirely. */
it('does not list an overdue matter that is not published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $unpublished = Matter::factory()->unpublished()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(20),
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StaleMattersWidget::class)
        ->assertCanNotSeeTableRecords([$unpublished]);
});

it('does not list a matter that was updated for the client recently', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $recentlyUpdated = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(2),
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StaleMattersWidget::class)
        ->assertCanNotSeeTableRecords([$recentlyUpdated]);
});

/**
 * R8 end-to-end (M6.5 Task 5, findings stage-03/spec-gap-03): đi qua đúng đường luật sư dùng —
 * bấm "Chuyển giai đoạn" (StageLogsRelationManager, action transitionStage) sang một giai đoạn
 * `is_terminal` ('closed', tới được từ 'enforcement' theo StagePresets::civil()) — chứ không tự
 * gán `closed_at` bằng tay. Trước bản sửa này, closed_at không bao giờ được ghi nên vụ này ở lại
 * vĩnh viễn trong widget sau 14 ngày và ô "Đã kết thúc" luôn bằng 0; giờ vụ biến mất khỏi widget
 * và ô đó tăng lên đúng một.
 */
it('drops a matter from the widget after it is transitioned into a terminal stage through the real screen', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->atStage('enforcement')->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(20),
    ]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('transitionStage', data: [
        'to_stage' => 'closed',
        'occurred_at' => today()->toDateString(),
        'internal_note' => 'Đã hoàn tất, đóng hồ sơ.',
        'public_content' => null,
        'next_step' => null,
        'client_action' => null,
        'expected_next_update_at' => null,
        'publish' => false,
        // Làn fm A1: đóng vụ trên màn hình đòi tích xác nhận đã xem việc còn dở.
        'confirm_close' => true,
    ]);

    expect($matter->fresh()->closed_at)->not->toBeNull();

    $this->travel(15)->days();

    $this->livewire(StaleMattersWidget::class)
        ->assertCanNotSeeTableRecords([$matter]);

    $method = new ReflectionMethod(MatterCountsWidget::class, 'getStats');
    $method->setAccessible(true);
    $stats = [];
    foreach ($method->invoke(new MatterCountsWidget) as $stat) {
        $stats[(string) $stat->getLabel()] = (string) $stat->getValue();
    }

    expect($stats[__('widgets.matter_counts.closed')])->toBe('1');
});
