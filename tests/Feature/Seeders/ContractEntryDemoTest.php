<?php

use App\Enums\ContractStatus;
use App\Filament\Admin\Pages\ContractEntry;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\ContractEnteredByColleague;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

/**
 * Làn kt — dữ liệu mẫu cho trang "Hợp đồng": kế toán mẫu (`ketoan@luatvukhang.com`) thấy việc cần nhập
 * ngay khi đăng nhập — ít nhất hai hồ sơ chưa có hợp đồng — và một hợp đồng nháp do chính kế toán nhập
 * (vụ 9 của `BillingSeeder`), mà luật sư phụ trách đã nhận chuông.
 */
beforeEach(function () {
    // MatterSeeder ghi tệp PDF thật (xem DemoDataSeederTest).
    Storage::fake('private');
    Filament::setCurrentPanel('admin');

    $this->seed(DatabaseSeeder::class);
    $this->accountant = User::query()->where('email', 'ketoan@luatvukhang.com')->firstOrFail();
});

it('gives the demo accountant at least two matters to enter and one draft it entered itself', function () {
    $this->actingAs($this->accountant, 'web');

    $withoutContract = Matter::query()->listableBy($this->accountant)->whereDoesntHave('contracts')->get();
    $byAccountant = Contract::query()->where('created_by', $this->accountant->id)->get();

    expect($withoutContract->count())->toBeGreaterThanOrEqual(2)
        ->and($byAccountant)->toHaveCount(1)
        ->and($byAccountant->sole()->status)->toBe(ContractStatus::Draft);

    $draftMatter = $byAccountant->sole()->matter;

    $this->livewire(ContractEntry::class)
        ->assertCanSeeTableRecords([...$withoutContract->all(), $draftMatter]);

    expect($draftMatter->leadLawyer->notifications()->where('type', ContractEnteredByColleague::class)->count())->toBe(1);
});
