<?php

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Widgets\ContractsToEnterWidget;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Làn fa3, mục 4 (kiểm tra luồng nội bộ 2026-10-16): ô "Hồ sơ cần nhập hợp đồng" trên trang chủ kế toán và
 * bộ lọc mặc định của trang "Hợp đồng" dùng MỘT luật "Cần nhập": hồ sơ ĐANG MỞ (chưa kết thúc, chưa huỷ)
 * chưa có hợp đồng nào, hoặc hợp đồng hiện hành đã huỷ (đúng những dòng có nút "Nhập hợp đồng"). Trước bản
 * sửa ô đếm cả hồ sơ đã kết thúc và bỏ hồ sơ có hợp đồng đã huỷ, trong khi PROGRESS nói "Cần nhập". Hàm
 * toàn cục mang tiền tố `fa3Ce`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->empty = Matter::factory()->create();
    $this->redo = Matter::factory()->create();
    Contract::factory()->for($this->redo)->create(['status' => ContractStatus::Cancelled, 'total_amount' => 5_000_000]);
    $this->drafted = Matter::factory()->create();
    Contract::factory()->for($this->drafted)->create(['status' => ContractStatus::Draft]);
    $this->signed = Matter::factory()->create();
    Contract::factory()->for($this->signed)->active()->create();

    $this->closedEmpty = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subMonth()]);
    $this->closedRedo = Matter::factory()->atStage('closed')->create(['closed_at' => now()->subMonth()]);
    Contract::factory()->for($this->closedRedo)->create(['status' => ContractStatus::Cancelled, 'total_amount' => 5_000_000]);

    // Hồ sơ cũ có hợp đồng đã huỷ, rồi hợp đồng mới đã ký: hợp đồng HIỆN HÀNH là bản mới.
    $this->resigned = Matter::factory()->create();
    Contract::factory()->for($this->resigned)->create(['status' => ContractStatus::Cancelled, 'total_amount' => 5_000_000]);
    Contract::factory()->for($this->resigned)->active()->create();
});

/** @return array<string, array{value: string, url: ?string, label: string, description: ?string}> */
function fa3CeTiles(): array
{
    $stats = (fn () => $this->getStats())->call(Livewire::test(ContractsToEnterWidget::class)->instance());

    return collect($stats)->mapWithKeys(fn ($stat, $index): array => [$index => [
        'value' => (string) $stat->getValue(),
        'url' => $stat->getUrl(),
        'label' => (string) $stat->getLabel(),
        'description' => $stat->getDescription() === null ? null : (string) $stat->getDescription(),
    ]])->all();
}

it('shows by default exactly the matters still to enter: open, with no contract or a cancelled current one, and the drafts', function () {
    $this->actingAs($this->accountant, 'web');

    Livewire::test(ContractEntry::class)
        ->assertCanSeeTableRecords([$this->empty, $this->redo, $this->drafted])
        ->assertCanNotSeeTableRecords([$this->signed, $this->closedEmpty, $this->closedRedo, $this->resigned]);
});

it('counts on the home tile exactly the rows of the "to enter" filter, and links to that filter', function () {
    // Một hồ sơ đã kết thúc chưa từng có hợp đồng nữa (dữ liệu cũ, vụ không thu phí): luật cũ đếm 3, luật "Cần nhập" đếm 2.
    Matter::factory()->atStage('closed')->create(['closed_at' => now()->subYear()]);

    $this->actingAs($this->accountant, 'web');

    $tiles = fa3CeTiles();
    $url = ContractEntry::getUrl(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_TO_ENTER]]]]);

    expect($tiles[0]['value'])->toBe('2')
        ->and($tiles[0]['url'])->toBe($url);

    $page = Livewire::withQueryParams(['filters' => ['contract_status' => ['values' => [ContractEntry::STATUS_TO_ENTER]]]])
        ->test(ContractEntry::class)
        ->assertCanSeeTableRecords([$this->empty, $this->redo])
        ->assertCanNotSeeTableRecords([$this->drafted, $this->signed, $this->closedEmpty, $this->closedRedo, $this->resigned]);

    expect($page->instance()->getFilteredTableQuery()->count())->toBe(2);
});

it('names the tile and the filter after the rule, not "without a contract"', function () {
    $this->actingAs($this->accountant, 'web');

    $tiles = fa3CeTiles();
    $options = Livewire::test(ContractEntry::class)->instance()->getTable()->getFilter('contract_status')->getOptions();

    expect($tiles[0]['label'])->toBe(__('contract_entry.widget.to_enter'))
        ->and($tiles[0]['label'])->not->toBe('Hồ sơ chưa có hợp đồng')
        ->and($tiles[0]['description'])->toBe(__('contract_entry.widget.to_enter_hint'))
        ->and($options)->toHaveKey(ContractEntry::STATUS_TO_ENTER, __('contract_entry.status.to_enter'))
        ->and(__('contract_entry.widget.to_enter_hint'))->toContain('đang mở')
        ->toContain('đã huỷ');
});

it('still offers the plain "no contract" status filter, which a closed matter without a contract also matches', function () {
    $this->actingAs($this->accountant, 'web');

    Livewire::test(ContractEntry::class)
        ->filterTable('contract_status', [ContractEntry::STATUS_NONE])
        ->assertCanSeeTableRecords([$this->empty, $this->closedEmpty])
        ->assertCanNotSeeTableRecords([$this->redo, $this->drafted]);
});
