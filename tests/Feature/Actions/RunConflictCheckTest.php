<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Xây một bên "đề xuất" chưa lưu (->exists === false), giống hệt những gì OpenMatter sẽ tạo
 * từ dữ liệu form trước khi lưu vụ việc (SPEC §6.10: kiểm tra chạy TRƯỚC khi lưu).
 */
function proposedParty(
    PartyRole $role,
    string $name,
    ?string $idNumber = null,
    ?string $phone = null,
    bool $isOurClient = false,
    ?Client $client = null,
): MatterParty {
    return (new MatterParty([
        'role' => $role,
        'name' => $name,
        'is_our_client' => $isOurClient,
        'client_id' => $client?->id,
    ]))->identify($idNumber, $phone);
}

it('blocks at red level when a party shares an id number with an existing client in another matter and roles oppose', function () {
    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Trần Thị Lan', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Nguyễn Văn Hùng (bị đơn)', idNumber: '079012345678');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue()
        ->and($result->matches)->toHaveCount(1);

    $match = $result->matches->first();
    expect($match->matterCode)->toBe($otherMatter->code)
        ->and($match->matterTypeName)->toBe($otherMatter->matterType->name)
        ->and($match->partyRole)->toBe(PartyRole::Plaintiff)
        ->and($match->partyName)->toBe('Nguyễn Văn Hùng')
        ->and($match->level)->toBe(ConflictLevel::Red);
});

it('logs the check to the activity log even when the result is green', function () {
    $lawyer = User::factory()->create();
    $this->actingAs($lawyer);

    $party = proposedParty(PartyRole::Plaintiff, 'Người Không Trùng Ai Cả', idNumber: '001199988877', phone: '0911222333');

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->matches)->toBeEmpty();

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('conflict_check_run')
        ->and($activity->causer?->is($lawyer))->toBeTrue()
        ->and($activity->properties->get('level'))->toBe('green')
        ->and($activity->properties->get('matches'))->toBe([]);
});

it('warns at yellow level for a normalized-name match even when id number and phone differ, and does not block', function () {
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    // Tên trùng sau chuẩn hoá (bỏ dấu, viết thường, khoảng trắng) nhưng số căn cước và điện
    // thoại khác hẳn — vẫn ở vai đối lập (defendant) để chứng minh tên không đủ để lên đỏ.
    $opposingByNameOnly = proposedParty(PartyRole::Defendant, '  Lê   THỊ hoa ', idNumber: '033344455566', phone: '0977888999');

    $result = app(RunConflictCheck::class)->handle(collect([$opposingByNameOnly]));

    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->isBlocking())->toBeFalse()
        ->and($result->requiresAcknowledgement())->toBeTrue()
        ->and($result->matches)->toHaveCount(1)
        ->and($result->matches->first()->level)->toBe(ConflictLevel::Yellow);
});

it('treats related, third-party and opposing-counsel roles as never opposing, so a matching client only warns yellow', function () {
    $existingClient = Client::factory()->create(['id_number' => '055566677788']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng mới', isOurClient: true);
    $relatedParty = proposedParty(PartyRole::Related, 'Người liên quan', idNumber: '055566677788');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $relatedParty]));

    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->isBlocking())->toBeFalse();
});

it('never carries a matter title, summary or internal description in the result', function () {
    $secretTitle = 'TIEU DE TUYET MAT KHONG DUOC LO XXQ123';
    $existingClient = Client::factory()->create(['id_number' => '022233344455']);
    $otherMatter = Matter::factory()->create([
        'title' => $secretTitle,
        'description_internal' => 'Noi dung noi bo tuyet mat',
        'summary_for_client' => 'Tom tat rieng tu cho khach',
    ]);
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '022233344455');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    $serialized = json_encode($result->toArray());

    expect($result->matches)->not->toBeEmpty()
        ->and($serialized)->not->toContain($secretTitle)
        ->and($serialized)->not->toContain('tuyet mat')
        ->and($serialized)->not->toContain('rieng tu');
});

it('runs without listableBy and still returns the matter code for a matter the acting user cannot open', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    $existingClient = Client::factory()->create(['id_number' => '066677788899']);
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($restricted)->ourClient($existingClient)->create();

    $this->actingAs($outsider);

    // Điều kiện tiên quyết của test: outsider thật sự không có quyền mở vụ restricted này.
    expect($outsider->can('view', $restricted))->toBeFalse()
        ->and(Matter::query()->listableBy($outsider)->whereKey($restricted->id)->exists())->toBeFalse();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '066677788899');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->matches->pluck('matterCode')->all())->toContain($restricted->code);
});

it('is read-only: never creates or modifies a Matter or MatterParty row', function () {
    $existingClient = Client::factory()->create(['id_number' => '011122233344']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $matterCountBefore = Matter::count();
    $partyCountBefore = MatterParty::count();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '011122233344');

    app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect(Matter::count())->toBe($matterCountBefore)
        ->and(MatterParty::count())->toBe($partyCountBefore);
});

it('excludes the matter’s own existing parties from matching themselves when checking a new party on an existing matter', function () {
    $matter = Matter::factory()->create();
    $ourClientParty = MatterParty::factory()->for($matter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
    ])->identify('088899911122', '0933111222');
    $ourClientParty->save();

    // Bên mới thêm vào CÙNG vụ việc này, trùng định danh với chính bên đã có trong vụ — không
    // phải là xung đột với vụ khác nên phải bị loại khỏi kết quả tìm kiếm.
    $newPartySameMatter = proposedParty(PartyRole::Defendant, 'Trùng chính mình', idNumber: '088899911122', phone: '0933111222');

    $parties = $matter->parties()->get()->push($newPartySameMatter);

    $result = app(RunConflictCheck::class)->handle($parties, $matter);

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->matches)->toBeEmpty();
});

it('ignores a matching party whose matter has been soft-deleted instead of crashing', function () {
    $existingClient = Client::factory()->create(['id_number' => '044455566677']);
    $deletedMatter = Matter::factory()->create();
    MatterParty::factory()->for($deletedMatter)->ourClient($existingClient)->create();
    $deletedMatter->delete();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '044455566677');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->matches)->toBeEmpty();
});

it('aggregates to the highest level found across all checked parties', function () {
    $existingClient = Client::factory()->create(['id_number' => '099988877766']);
    $redMatter = Matter::factory()->create();
    MatterParty::factory()->for($redMatter)->ourClient($existingClient)->create();

    $yellowMatter = Matter::factory()->create();
    MatterParty::factory()->for($yellowMatter)->create(['name' => 'Phạm Văn Yellow']);

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $redParty = proposedParty(PartyRole::Defendant, 'Bên trùng đỏ', idNumber: '099988877766');
    $yellowParty = proposedParty(PartyRole::Related, 'Pham Van Yellow');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $redParty, $yellowParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches)->toHaveCount(2)
        ->and($result->matches->pluck('level')->map(fn ($l) => $l->value)->sort()->values()->all())->toBe(['red', 'yellow']);
});
