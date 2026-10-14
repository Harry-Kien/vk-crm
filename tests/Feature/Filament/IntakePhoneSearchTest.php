<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — danh sách Tiếp nhận tìm được theo số điện thoại,
 * gõ kiểu nào cũng được, và vẫn chỉ trong những bản ghi người xem được thấy. Hàm toàn cục mang tiền tố
 * `ips…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function ipsRecord(User $actor, string $name, string $phone): IntakeRequest
{
    return app(RecordIntake::class)->handle($actor, [
        'contact_name' => $name, 'contact_phone' => $phone,
        'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ])->intake;
}

it('finds a call by its phone number written any way, within what the viewer may see', function (string $typed) {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $mine = ipsRecord($assistant, 'Người Gọi Một', '0912 345 678');
    $other = ipsRecord($assistant, 'Người Gọi Hai', '0988 000 111');
    $hidden = ipsRecord(User::factory()->withRole(Role::Assistant)->create(), 'Người Của Người Khác', '0912345678');
    $this->actingAs($assistant, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->searchTable($typed)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other, $hidden]);
})->with(['local' => ['0912345678'], 'spaced' => ['0912 345 678'], 'international' => ['+84 912 345 678'], 'tail' => ['345678']]);

it('does not search the phone column with fewer than four digits, which would match nearly every number', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $mine = ipsRecord($assistant, 'Người Gọi Một', '0912 345 678');
    $this->actingAs($assistant, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->searchTable('345')
        ->assertCanNotSeeTableRecords([$mine]);
});
