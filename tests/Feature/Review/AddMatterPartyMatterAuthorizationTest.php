<?php

use App\Actions\AddMatterParty;
use App\Enums\Confidentiality;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Rà soát toàn hệ thống 2026-10-22, làn harden — việc 2.
 *
 * `AddMatterParty` chỉ hỏi `MatterPartyPolicy::create` (có `matter.update` nói chung), không hỏi quyền
 * trên CHÍNH vụ việc: màn hình đã chặn bằng `MatterPolicy::update`, nhưng Action — cổng thật theo quy
 * ước dự án — để một luật sư thêm bên vào vụ của người khác, kể cả vụ `restricted` họ không mở được.
 * Mọi đường hợp lệ (tab "Các bên") đều đã đòi đúng quyền này, nên lớp thứ hai không chặn ai hợp lệ.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('refuses a lawyer who may update matters in general but cannot update this matter', function (Confidentiality $confidentiality) {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['confidentiality' => $confidentiality]);

    expect($outsider->can('update', $matter))->toBeFalse();

    expect(fn () => app(AddMatterParty::class)->handle($matter, $outsider, [
        'role' => PartyRole::Related,
        'name' => 'Bên do người ngoài vụ thêm',
        'phone' => '0966000111',
    ]))->toThrow(AuthorizationException::class);

    expect($matter->parties()->where('name', 'Bên do người ngoài vụ thêm')->exists())->toBeFalse();
})->with([
    'vụ thường' => [Confidentiality::Normal],
    'vụ hạn chế' => [Confidentiality::Restricted],
]);

it('still lets the lead lawyer and a manager add a party', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    app(AddMatterParty::class)->handle($matter, $lead, ['role' => PartyRole::Related, 'name' => 'Bên luật sư phụ trách thêm', 'phone' => '0966000222']);
    app(AddMatterParty::class)->handle($matter, $manager, ['role' => PartyRole::Related, 'name' => 'Bên trưởng phòng thêm', 'phone' => '0966000333']);

    expect($matter->parties()->whereIn('name', ['Bên luật sư phụ trách thêm', 'Bên trưởng phòng thêm'])->count())->toBe(2);
});
