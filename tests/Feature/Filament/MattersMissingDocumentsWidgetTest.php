<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\Role;
use App\Filament\Admin\Widgets\MattersMissingDocumentsWidget;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Dựng một đầu mục còn thiếu với đồng hồ lùi lại `$days` ngày. `created_at` là mốc của một đầu
 * mục `missing` (chưa ai duyệt lần nào), `reviewed_at` là mốc của một đầu mục `rejected` — xem
 * docblock widget cho vì sao hai cột đó là đồng hồ.
 */
function missingItem(Matter $matter, int $days, ChecklistItemStatus $status = ChecklistItemStatus::Missing): MatterChecklistItem
{
    $item = MatterChecklistItem::factory()->status($status)->create([
        'matter_id' => $matter->id,
        'is_required' => true,
        'rejection_reason' => $status === ChecklistItemStatus::Rejected ? str_repeat('a', 25) : null,
        'reviewed_at' => $status === ChecklistItemStatus::Rejected ? now()->subDays($days) : null,
    ]);

    // created_at là cột do Eloquent tự quản, phải ghi đè sau khi tạo.
    $item->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

    return $item->refresh();
}

/** SPEC §7.1 mục 4 + §6.9: hồ sơ còn item BẮT BUỘC `missing`/`rejected` quá 14 ngày. */
it('lists a matter whose required item has been missing for more than 14 days', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $stuck = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($stuck, 20);

    $recent = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($recent, 3);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanSeeTableRecords([$stuck])
        ->assertCanNotSeeTableRecords([$recent]);
});

/** §6.9 đếm cả `rejected`: khách đã nộp nhưng bị từ chối thì hồ sơ vẫn đang thiếu giấy tờ đó. */
it('counts a required item rejected more than 14 days ago, clocked from the rejection', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $stuck = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($stuck, 30, ChecklistItemStatus::Rejected);

    // Đầu mục được tạo từ lâu nhưng vừa bị từ chối hôm qua: đồng hồ chạy từ lúc từ chối, vì đó
    // là lúc khách được cho biết phải nộp lại.
    $justRejected = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = missingItem($justRejected, 60, ChecklistItemStatus::Rejected);
    $item->forceFill(['reviewed_at' => now()->subDay()])->saveQuietly();

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanSeeTableRecords([$stuck])
        ->assertCanNotSeeTableRecords([$justRejected]);
});

/** §6.9 nói "item BẮT BUỘC": một đầu mục không bắt buộc còn thiếu không làm hồ sơ tắc. */
it('ignores an optional item that has been missing for a long time', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $item = missingItem($matter, 40);
    $item->forceFill(['is_required' => false])->saveQuietly();

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanNotSeeTableRecords([$matter]);
});

/**
 * `pending_review` KHÔNG tính: khách đã nộp, quả bóng đang ở sân văn phòng. Đây cũng là chỗ
 * widget này và thanh tiến độ `X/Y` (SPEC §4.10) phải đồng ý với nhau — cả hai chỉ đọc cột
 * `status`, không đọc bảng `documents`.
 */
it('ignores an item waiting for the office to review it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($matter, 40, ChecklistItemStatus::PendingReview);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanNotSeeTableRecords([$matter]);
});

/** §6.9: chỉ vụ việc ĐANG MỞ. */
it('ignores a closed matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);
    missingItem($matter, 40);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanNotSeeTableRecords([$matter]);
});

/** §6.9: chỉ vụ việc ĐÃ CÔNG BỐ PORTAL — chưa công bố thì khách không có đường nào để nộp. */
it('ignores a matter that has never been published to the portal', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->unpublished()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($matter, 40);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanNotSeeTableRecords([$matter]);
});

it('shows a lawyer only their own stuck matters', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $colleague = User::factory()->withRole(Role::Lawyer)->create();

    $own = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($own, 20);
    $theirs = Matter::factory()->create(['lead_lawyer_id' => $colleague->id]);
    missingItem($theirs, 20);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$theirs]);
});

/** Nhánh `restricted` của scopeListableBy(), cặp âm/dương — xem test tương ứng ở widget mục 3. */
it('hides a restricted stuck matter from a manager who is not the lead lawyer', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($restricted, 20);

    $this->actingAs($manager, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanNotSeeTableRecords([$restricted]);
});

it('shows a restricted stuck matter to its own lead lawyer, and an ordinary one to the manager', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($restricted, 20);

    $ordinary = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($ordinary, 20);

    $this->actingAs($lawyer, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanSeeTableRecords([$restricted, $ordinary]);

    $this->actingAs($manager, 'web');

    $this->livewire(MattersMissingDocumentsWidget::class)
        ->assertCanSeeTableRecords([$ordinary]);
});

/** Cột "còn thiếu" đếm MỌI đầu mục bắt buộc chưa nộp, không chỉ những cái đã quá 14 ngày. */
it('counts every outstanding required item, not only the ones past 14 days', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    missingItem($matter, 30);
    missingItem($matter, 2);
    MatterChecklistItem::factory()->status(ChecklistItemStatus::Accepted)->create([
        'matter_id' => $matter->id,
        'is_required' => true,
    ]);

    $this->actingAs($lawyer, 'web');

    $row = MattersMissingDocumentsWidget::rowsFor($lawyer)->sole();

    expect($row->outstanding_required_count)->toBe(2);
});

it('hides the widget from an accountant, who cannot open a matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');

    expect(MattersMissingDocumentsWidget::canView())->toBeFalse();
});

it('shows the widget to a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $this->actingAs($lawyer, 'web');

    expect(MattersMissingDocumentsWidget::canView())->toBeTrue();
});
