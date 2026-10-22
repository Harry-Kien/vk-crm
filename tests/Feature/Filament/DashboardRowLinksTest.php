<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\MatterRole;
use App\Enums\PushTopic;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\MatterTab;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn ops — nút "Mở" trên ba danh sách việc của trang chủ đưa tới TAB nơi
 * việc đó được làm, như thư, chuông và thông báo đẩy của cùng việc (`PushTopic::url()`: nhắc hạn → tab "Mốc
 * thời hạn", giấy tờ khách nộp → tab "Danh mục hồ sơ"). Trước đây cả ba mở tab "Tổng quan": người duyệt giấy
 * tờ phải tự tìm tab, trong khi bấm từ điện thoại thì vào thẳng đúng chỗ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->actingAs($this->lawyer, 'web');
});

it('opens an upcoming deadline on the deadlines tab of its matter, where the reminder push opens it', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'responsible_user_id' => $this->lawyer->id,
        'is_completed' => false,
        'due_date' => today()->addDays(3),
    ]);
    $url = MatterTab::url($this->matter->id, DeadlinesRelationManager::class);

    $this->livewire(UpcomingDeadlinesWidget::class)
        ->assertTableActionHasUrl('open', $url, $deadline);

    // Cùng chỗ thông báo đẩy nhắc hạn của mốc này mở (đường dẫn tương đối trong scope của panel).
    $push = PushTopic::StaffDeadlineReminder->message($deadline->fresh(), $this->lawyer, 'd3');
    expect($url)->toEndWith($push->url);
    $this->get($url)->assertOk();
});

it('opens a document waiting for review on the checklist tab, where it is reviewed', function () {
    $item = MatterChecklistItem::factory()->status(ChecklistItemStatus::PendingReview)->create(['matter_id' => $this->matter->id]);
    $url = MatterTab::url($this->matter->id, ChecklistRelationManager::class);

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertTableActionHasUrl('open', $url, $item);

    $this->get($url)->assertOk();
});

it('opens a matter stuck on missing documents on its checklist tab', function () {
    $item = MatterChecklistItem::factory()->status(ChecklistItemStatus::Missing)->create([
        'matter_id' => $this->matter->id,
        'is_required' => true,
    ]);
    $item->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertTableActionHasUrl('open', MatterTab::url($this->matter->id, ChecklistRelationManager::class), $this->matter);
});

it('lands an assistant of the team on the same tabs, not on an error', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $this->actingAs($assistant, 'web');

    $this->get(MatterTab::url($this->matter->id, ChecklistRelationManager::class))->assertOk();
    $this->get(MatterTab::url($this->matter->id, DeadlinesRelationManager::class))->assertOk();
});
