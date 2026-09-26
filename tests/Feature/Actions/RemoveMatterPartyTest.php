<?php

use App\Actions\RemoveMatterParty;
use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * `App\Actions\RemoveMatterParty` (M6.5 Task 9, brief R14): "gỡ một bên là xoá mềm kèm lý do bắt
 * buộc… bên đã gỡ KHÔNG còn trong dữ liệu đối chiếu xung đột."
 */
it('soft-deletes the party with a reason, recorded in the audit without any raw id number', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['name' => 'Nhập nhầm'])
        ->identify('001099001234', null);
    $party->save();

    $removed = app(RemoveMatterParty::class)->handle($party, $lawyer, 'Nhập nhầm bên này, chưa từng là bên thật.');

    expect($removed->trashed())->toBeTrue()
        ->and(MatterParty::query()->whereKey($party->id)->exists())->toBeFalse()
        ->and(MatterParty::withTrashed()->whereKey($party->id)->exists())->toBeTrue();

    $audit = Activity::query()->where('event', 'matter_party_removed')->latest('id')->first();
    expect($audit->properties->get('reason'))->toBe('Nhập nhầm bên này, chưa từng là bên thật.')
        ->and(collect($audit->properties->all())->flatten(5)->implode(' '))->not->toContain('001099001234');
});

it('requires a non-empty reason, and leaves the party in place', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create();

    expect(fn () => app(RemoveMatterParty::class)->handle($party, $lawyer, '   '))
        ->toThrow(ValidationException::class);

    expect($party->fresh()->trashed())->toBeFalse();
});

it('rejects a user without matter.update, regardless of the owning matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->create();

    expect(fn () => app(RemoveMatterParty::class)->handle($party, $accountant, 'Bất kỳ lý do nào'))
        ->toThrow(AuthorizationException::class);

    expect($party->fresh()->trashed())->toBeFalse();
});

/**
 * Brief: "Bên của chính khách hàng (is_our_client trỏ về khách của vụ) không gỡ được ở đây, vì nó
 * sửa qua hồ sơ khách hàng." Cặp dương: một khách hàng KHÁC của văn phòng đứng vai đồng nguyên đơn
 * (`is_our_client` nhưng KHÔNG phải khách của HỒ SƠ) vẫn gỡ được bình thường.
 */
it('refuses to remove the party that is the matter\'s own client, but allows removing another firm client standing as a co-party', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $mainClient = Client::factory()->create();
    $matter = Matter::factory()->for($mainClient)->create(['lead_lawyer_id' => $lawyer->id]);

    $ownClientParty = MatterParty::factory()->for($matter)->ourClient($mainClient, PartyRole::Plaintiff)->create();
    $coClient = Client::factory()->create();
    $coParty = MatterParty::factory()->for($matter)->ourClient($coClient, PartyRole::Plaintiff)->create();

    expect(fn () => app(RemoveMatterParty::class)->handle($ownClientParty, $lawyer, 'Muốn gỡ khách hàng chính'))
        ->toThrow(ValidationException::class);
    expect($ownClientParty->fresh()->trashed())->toBeFalse();

    $removedCoParty = app(RemoveMatterParty::class)->handle($coParty, $lawyer, 'Đồng nguyên đơn nhập nhầm, chưa từng là bên.');
    expect($removedCoParty->trashed())->toBeTrue();
});

/**
 * Brief Test: "Gỡ bên nhập nhầm: bên đó không còn sinh khớp ở lần kiểm tra sau." Cả hai trục: một
 * xung đột LỊCH SỬ (bên đã gỡ khớp với một vụ KHÁC) và một xung đột CÙNG VỤ VIỆC (R13b, hai khách
 * hàng của văn phòng đối lập nhau ngay trong vụ đang gỡ) — cùng test để khoá cả hai bằng một lần
 * chạy trước/sau.
 */
it('a removed party no longer produces a match afterwards, both cross-matter and same-matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    // Trục LỊCH SỬ: bên nhập nhầm khớp CCCD với một khách hàng ở vụ khác.
    $historicalMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $historicalParty = MatterParty::factory()->for($historicalMatter)->create(['role' => PartyRole::Defendant])
        ->identify('033322211100', null);
    $historicalParty->save();

    $otherClient = Client::factory()->create(['id_number' => '033322211100']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($otherClient, PartyRole::Plaintiff)->create();

    $historicalBefore = app(RunConflictCheck::class)->handle(collect(), $historicalMatter, $lawyer);
    expect($historicalBefore->level)->not->toBe(ConflictLevel::Green);

    app(RemoveMatterParty::class)->handle($historicalParty, $lawyer, 'Nhập nhầm, chưa từng là bên.');

    $historicalAfter = app(RunConflictCheck::class)->handle(collect(), $historicalMatter, $lawyer);
    expect($historicalAfter->level)->toBe(ConflictLevel::Green);

    // Trục CÙNG VỤ VIỆC (R13b): hai khách hàng của văn phòng đối lập ngay trong vụ đang gỡ.
    $sameMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $plaintiff = MatterParty::factory()->for($sameMatter)->ourClient(Client::factory()->create(), PartyRole::Plaintiff)->create();
    $defendant = MatterParty::factory()->for($sameMatter)->ourClient(Client::factory()->create(), PartyRole::Defendant)->create();

    $sameMatterBefore = app(RunConflictCheck::class)->handle(collect(), $sameMatter, $lawyer);
    expect($sameMatterBefore->level)->toBe(ConflictLevel::Red);

    app(RemoveMatterParty::class)->handle($defendant, $lawyer, 'Nhập nhầm vai bị đơn, chưa từng là bên.');

    $sameMatterAfter = app(RunConflictCheck::class)->handle(collect(), $sameMatter, $lawyer);
    expect($sameMatterAfter->level)->toBe(ConflictLevel::Green);
});

/**
 * Bất biến CŨ (Task 8, giữ nguyên): một VỤ VIỆC xoá mềm (khác hẳn một BÊN đã gỡ) vẫn sinh khớp —
 * đây KHÔNG phải việc của RemoveMatterParty (nó chỉ xoá BÊN), ghi lại ở đây để đối chiếu ngay cạnh
 * bất biến MỚI phía trên, tránh nhầm lẫn hai trục.
 */
it('still matches when the MATTER itself (not the party) has been soft-deleted, unlike a removed party', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '055544433322']);
    $deletedMatter = Matter::factory()->create();
    MatterParty::factory()->for($deletedMatter)->ourClient($client, PartyRole::Plaintiff)->create();
    $deletedMatter->delete();

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant])->identify('055544433322', null)->save();

    $result = app(RunConflictCheck::class)->handle(collect(), $matter, $lawyer);

    expect($result->level)->not->toBe(ConflictLevel::Green)
        ->and($result->allMatches()->pluck('matterCode')->all())->toContain($deletedMatter->code);
});
