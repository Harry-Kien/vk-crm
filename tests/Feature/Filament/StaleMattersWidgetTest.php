<?php

use App\Enums\Role;
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
