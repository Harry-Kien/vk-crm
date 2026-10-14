<?php

use App\Actions\Document\ChecklistProgress;
use App\Enums\ChecklistItemStatus;
use App\Enums\Role;
use App\Events\ChecklistItemRejected;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fa, mục A5 của đợt kiểm tra nghiệp vụ 2026-10-09 — một đầu mục danh mục bấm nhầm hay viết sai.
 * Trước bản sửa không có đường nào trả một đầu mục về "Còn thiếu" (một lần bấm nhầm "Không cần nộp" làm
 * thư nhắc thôi nhắc mãi; trên mục đó chỉ còn nút "Đã nhận" — sai sự thật), và không sửa được tên, mô tả
 * hay "bắt buộc" của một đầu mục (thêm lại cùng tên thì bị từ chối vì trùng). Mọi thứ qua tab Danh mục.
 * Hàm toàn cục mang tiền tố `ckx…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function ckxScreen(Matter $matter)
{
    return test()->livewire(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function ckxItem(Matter $matter, ChecklistItemStatus $status, array $attributes = []): MatterChecklistItem
{
    return MatterChecklistItem::factory()->for($matter)->create([
        'name' => 'Biên bản hoà giải tại UBND xã',
        'is_required' => true,
        'status' => $status,
        ...$attributes,
    ]);
}

it('puts an item marked "not needed" by mistake back to missing, so the reminders pick it up again, with no rejection mail', function () {
    Event::fake([ChecklistItemRejected::class]);
    $item = ckxItem($this->matter, ChecklistItemStatus::NotApplicable);
    $this->actingAs($this->lawyer, 'web');

    expect(ChecklistProgress::outstandingRequiredItems($this->matter))->toHaveCount(0);

    ckxScreen($this->matter)
        ->assertActionVisible(TestAction::make('reopen')->table($item))
        ->callAction(TestAction::make('reopen')->table($item))
        ->assertHasNoActionErrors()
        ->assertNotified(__('document_corrections.reopen.success'));

    $row = Activity::query()->where('event', 'checklist_item_reopened')->sole();

    expect($item->fresh()->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->fresh()->rejection_reason)->toBeNull()
        ->and($row->causer_id)->toBe($this->lawyer->id)
        ->and($row->properties['previous_status'])->toBe(ChecklistItemStatus::NotApplicable->value)
        ->and(ChecklistProgress::outstandingRequiredItems($this->matter->fresh())->pluck('id')->all())->toBe([$item->id]);

    Event::assertNotDispatched(ChecklistItemRejected::class);
});

it('puts an accepted item back to missing too', function () {
    $item = ckxItem($this->matter, ChecklistItemStatus::Accepted, ['reviewed_by' => $this->lawyer->id, 'reviewed_at' => now()]);
    $this->actingAs($this->lawyer, 'web');

    ckxScreen($this->matter)->callAction(TestAction::make('reopen')->table($item))->assertHasNoActionErrors();

    expect($item->fresh()->status)->toBe(ChecklistItemStatus::Missing)
        ->and($item->fresh()->accepted_version)->toBeNull();
});

it('offers no reopening where there is nothing to reopen, or on a closed matter', function () {
    $missing = ckxItem($this->matter, ChecklistItemStatus::Missing, ['name' => 'Mục còn thiếu']);
    $pending = ckxItem($this->matter, ChecklistItemStatus::PendingReview, ['name' => 'Mục chờ duyệt']);
    $rejected = ckxItem($this->matter, ChecklistItemStatus::Rejected, ['name' => 'Mục bị từ chối', 'rejection_reason' => 'Ảnh bị mờ ở góc trên bên trái']);
    $notNeeded = ckxItem($this->matter, ChecklistItemStatus::NotApplicable, ['name' => 'Mục không cần']);
    $this->actingAs($this->lawyer, 'web');

    ckxScreen($this->matter)
        ->assertActionHidden(TestAction::make('reopen')->table($missing))
        ->assertActionHidden(TestAction::make('reopen')->table($pending))
        ->assertActionHidden(TestAction::make('reopen')->table($rejected))
        ->assertActionVisible(TestAction::make('reopen')->table($notNeeded));

    $this->matter->forceFill(['closed_at' => now()])->save();
    $this->actingAs($this->lawyer, 'web');
    ckxScreen($this->matter->fresh())->assertActionHidden(TestAction::make('reopen')->table($notNeeded));
});

it('edits the name, description and required flag of an item for this matter alone, with old and new in the audit row', function () {
    $item = ckxItem($this->matter, ChecklistItemStatus::Missing, ['name' => 'Bien ban hoa giai', 'is_required' => false, 'description' => null]);
    $this->actingAs($this->lawyer, 'web');

    ckxScreen($this->matter)
        ->callAction(TestAction::make('editItem')->table($item), data: [
            'name' => 'Biên bản hoà giải tại UBND xã',
            'description' => 'Bản có dấu đỏ của UBND xã.',
            'is_required' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified(__('document_corrections.edit_item.success'));

    $fresh = $item->fresh();
    $row = Activity::query()->where('event', 'checklist_item_updated')->sole();

    expect($fresh->name)->toBe('Biên bản hoà giải tại UBND xã')
        ->and($fresh->description)->toBe('Bản có dấu đỏ của UBND xã.')
        ->and($fresh->is_required)->toBeTrue()
        ->and($row->properties['changes']['name'])->toBe(['from' => 'Bien ban hoa giai', 'to' => 'Biên bản hoà giải tại UBND xã'])
        ->and($row->properties['changes']['is_required'])->toBe(['from' => false, 'to' => true])
        ->and(ChecklistProgress::outstandingRequiredItems($this->matter->fresh())->pluck('id')->all())->toBe([$item->id]);
});

it('refuses to rename an item to the name of another item of the same matter', function () {
    ckxItem($this->matter, ChecklistItemStatus::Missing, ['name' => 'Giấy tờ tuỳ thân']);
    $item = ckxItem($this->matter, ChecklistItemStatus::Missing, ['name' => 'Giay to tuy than']);
    $this->actingAs($this->lawyer, 'web');

    ckxScreen($this->matter)
        ->callAction(TestAction::make('editItem')->table($item), data: ['name' => 'Giấy tờ tuỳ thân', 'description' => null, 'is_required' => true])
        ->assertHasActionErrors(['name']);

    expect($item->fresh()->name)->toBe('Giay to tuy than');
});
