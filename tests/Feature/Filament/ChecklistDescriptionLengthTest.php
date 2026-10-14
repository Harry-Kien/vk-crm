<?php

use App\Actions\Document\AddChecklistItem;
use App\Actions\Document\UpdateChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ChecklistRelationManager;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/*
 * Làn fa, sửa sau rà soát cuối (F3) — mô tả của một đầu mục danh mục nằm trong cột `text` (65.535
 * BYTE). Trước bản sửa, ô mô tả của "Thêm đầu mục" và "Sửa đầu mục" không có trần và hai Action không
 * kiểm độ dài: trên MariaDB strict một chuỗi quá dài là lỗi 500 (luật cứng "maxLength của form = độ dài
 * cột"). Trần là {@see MatterChecklistItem::DESCRIPTION_MAX_LENGTH} KÝ TỰ = 65.535 / 4 làm tròn xuống:
 * mọi chuỗi qua trần, kể cả toàn ký tự 4 byte, vừa cột. Hành vi màn hình qua Livewire; trần ở Action
 * (lối vào không có form) đo riêng ở cuối tệp.
 *
 * Hàm toàn cục mang tiền tố `cdl…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function cdlScreen(Matter $matter)
{
    return test()->livewire(ChecklistRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class]);
}

function cdlItem(Matter $matter): MatterChecklistItem
{
    return MatterChecklistItem::factory()->for($matter)->create([
        'name' => 'Biên bản hoà giải tại UBND xã',
        'description' => 'Mô tả cũ.',
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);
}

it('refuses on the description field an edit whose description is longer than the column holds, and changes nothing', function () {
    $item = cdlItem($this->matter);
    $this->actingAs($this->lawyer, 'web');

    cdlScreen($this->matter)
        ->callAction(TestAction::make('editItem')->table($item), data: [
            'name' => 'Biên bản hoà giải tại UBND xã',
            'description' => str_repeat('a', MatterChecklistItem::DESCRIPTION_MAX_LENGTH + 1),
            'is_required' => true,
        ])
        ->assertHasActionErrors(['description']);

    expect($item->fresh()->description)->toBe('Mô tả cũ.');
});

it('refuses on the description field a new item whose description is longer than the column holds, and adds nothing', function () {
    $this->actingAs($this->lawyer, 'web');

    cdlScreen($this->matter)
        ->callTableAction('addItem', data: [
            'name' => 'Giấy uỷ quyền',
            'description' => str_repeat('a', MatterChecklistItem::DESCRIPTION_MAX_LENGTH + 1),
            'is_required' => true,
        ])
        ->assertHasTableActionErrors(['description']);

    expect(MatterChecklistItem::query()->where('matter_id', $this->matter->id)->count())->toBe(0);
});

it('saves a description at the cap even when every character takes four bytes (fits the text column on MariaDB)', function () {
    $item = cdlItem($this->matter);
    $longest = str_repeat('😀', MatterChecklistItem::DESCRIPTION_MAX_LENGTH);
    $this->actingAs($this->lawyer, 'web');

    expect(strlen($longest))->toBeLessThanOrEqual(65535);

    cdlScreen($this->matter)
        ->callAction(TestAction::make('editItem')->table($item), data: [
            'name' => 'Biên bản hoà giải tại UBND xã',
            'description' => $longest,
            'is_required' => true,
        ])
        ->assertHasNoActionErrors();

    cdlScreen($this->matter)
        ->callTableAction('addItem', data: ['name' => 'Giấy uỷ quyền', 'description' => $longest, 'is_required' => false])
        ->assertHasNoTableActionErrors();

    expect($item->fresh()->description)->toBe($longest)
        ->and(MatterChecklistItem::query()->where('name', 'Giấy uỷ quyền')->sole()->description)->toBe($longest);
});

it('caps the description in both actions too, for a caller without the form', function () {
    $item = cdlItem($this->matter);
    $tooLong = str_repeat('a', MatterChecklistItem::DESCRIPTION_MAX_LENGTH + 1);

    $errorsOf = function (callable $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };

    expect($errorsOf(fn () => app(UpdateChecklistItem::class)->handle($item, $this->lawyer, 'Biên bản hoà giải tại UBND xã', $tooLong, true)))->toBe(['description'])
        ->and($errorsOf(fn () => app(AddChecklistItem::class)->handle($this->matter, $this->lawyer, 'Giấy uỷ quyền', $tooLong, true)))->toBe(['description']);

    expect($item->fresh()->description)->toBe('Mô tả cũ.')
        ->and(MatterChecklistItem::query()->where('name', 'Giấy uỷ quyền')->exists())->toBeFalse();
});

it('puts the same cap on the description field of both forms, so the browser stops an over-long text first', function () {
    $item = cdlItem($this->matter);
    $this->actingAs($this->lawyer, 'web');

    $edit = cdlScreen($this->matter)->mountAction(TestAction::make('editItem')->table($item));
    $add = cdlScreen($this->matter)->mountTableAction('addItem');

    expect($edit->instance()->getSchema('mountedActionSchema0')->getComponent('description')->getMaxLength())
        ->toBe(MatterChecklistItem::DESCRIPTION_MAX_LENGTH)
        ->and($add->instance()->getSchema('mountedActionSchema0')->getComponent('description')->getMaxLength())
        ->toBe(MatterChecklistItem::DESCRIPTION_MAX_LENGTH);
});
