<?php

use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Rà soát toàn hệ thống 2026-10-22, làn matter — nút "Chuyển giai đoạn" không được là đường cụt.
 *
 * Giai đoạn kết thúc không khai báo `allowed_next` (StagePresets: `closed` → `[]`), và chỉ quản trị viên
 * được đi ngoài `allowed_next` để mở lại vụ (SPEC §6.2 bước 1; làn fm). Luật sư phụ trách một vụ đã kết thúc
 * vẫn thấy nút "Chuyển giai đoạn", mở ra một ô "Giai đoạn mới" không có lựa chọn nào — bấm gì cũng không gửi
 * được. Nút ẩn khi người xem không có giai đoạn nào để chọn; "Thêm cập nhật" vẫn còn.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('hides the transition button from a lawyer on a closed matter, keeping the add-update button', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableActionHidden('transitionStage')
        ->assertTableActionVisible('addUpdate');
});

it('still shows the transition button to an admin on a closed matter, who may reopen it', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('closed')->create(['lead_lawyer_id' => $lawyer->id, 'closed_at' => now()->subDay()]);

    $this->actingAs($admin, 'web');

    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableActionVisible('transitionStage');
});

it('still shows the transition button to the lawyer while the matter is open', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->atStage('first_instance')->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableActionVisible('transitionStage');
});
