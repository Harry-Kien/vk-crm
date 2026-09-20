<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\Role;
use App\Filament\Admin\Widgets\PendingChecklistReviewsWidget;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** SPEC §7.1 mục 3: "khách đã nộp, chưa ai xem" — đúng những đầu mục đang `pending_review`. */
it('lists only checklist items waiting for review', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $waiting = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => $matter->id]);
    $accepted = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::Accepted)
        ->create(['matter_id' => $matter->id]);
    $missing = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::Missing)
        ->create(['matter_id' => $matter->id]);
    $rejected = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::Rejected)
        ->create(['matter_id' => $matter->id, 'rejection_reason' => str_repeat('a', 25)]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$accepted, $missing, $rejected]);
});

/** listableBy(): một luật sư không thấy hàng chờ duyệt của vụ việc mình không tham gia. */
it('shows a lawyer only the reviews waiting on matters they can list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    $own = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => Matter::factory()->create(['lead_lawyer_id' => $lawyer->id])->id]);
    $theirs = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => Matter::factory()->create(['lead_lawyer_id' => $colleague->id])->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$theirs]);
});

/**
 * Nhánh `restricted` của scopeListableBy(): một vụ việc mật chỉ luật sư phụ trách và admin thấy,
 * kể cả trưởng phòng có matter.viewAny cũng không. Cặp âm/dương đi liền nhau để không có test nào
 * xanh vì lý do khác (trưởng phòng thấy MỌI vụ thường, nên nếu nhánh restricted bị gỡ thì đúng
 * test này đỏ).
 */
it('hides a restricted matter review from a manager who is not the lead lawyer', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => $restricted->id]);

    $this->actingAs($manager, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanNotSeeTableRecords([$item]);
});

it('shows a restricted matter review to its own lead lawyer', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => $restricted->id]);

    // Trưởng phòng vẫn thấy MỌI vụ thường, nên cặp này chứng minh điều kiện `restricted` là thứ
    // duy nhất khác nhau giữa hai test.
    $ordinary = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => Matter::factory()->create(['lead_lawyer_id' => $lawyer->id])->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanSeeTableRecords([$item]);

    $this->actingAs($manager, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanSeeTableRecords([$ordinary]);
});

/** Vụ việc đã xoá mềm biến mất khỏi hàng chờ: whereHas('matter') loại nó qua global scope. */
it('drops a review whose matter has been soft deleted', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => $matter->id]);

    $matter->delete();

    $this->actingAs($lawyer, 'web');

    $this->livewire(PendingChecklistReviewsWidget::class)
        ->assertCanNotSeeTableRecords([$item]);
});

/**
 * Mốc "nộp lúc" lấy từ tài liệu NHÓM A gắn vào đầu mục — nhóm A là "khách cung cấp" (SPEC §4.11),
 * tức chính lần nộp. Một ghi chú nội bộ nhóm D gắn vào cùng đầu mục không được kéo mốc đó về sau,
 * vì văn phòng ghi chú lúc nào không phải là lúc khách nộp.
 */
it('dates the row by the client submission, not by an internal note on the same item', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()
        ->status(ChecklistItemStatus::PendingReview)
        ->create(['matter_id' => $matter->id]);

    Document::factory()->group(DocumentGroup::ClientProvided)->create([
        'matter_id' => $matter->id,
        'matter_checklist_item_id' => $item->id,
        'created_at' => now()->subDays(9),
    ]);
    Document::factory()->group(DocumentGroup::Internal)->create([
        'matter_id' => $matter->id,
        'matter_checklist_item_id' => $item->id,
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($lawyer, 'web');

    $row = PendingChecklistReviewsWidget::rowsFor($lawyer)->sole();

    expect($row->submitted_at)->not->toBeNull()
        ->and(Carbon::parse($row->submitted_at)->diffInDays(now()))
        ->toBeGreaterThanOrEqual(8);
});

it('hides the widget from an accountant, who has no checklist.review', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(PendingChecklistReviewsWidget::canView())->toBeFalse();
});

it('shows the widget to a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web');

    expect(PendingChecklistReviewsWidget::canView())->toBeTrue();
});
