<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
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
        ->and($match->level)->toBe(ConflictLevel::Red)
        ->and($match->tier)->toBe(ConflictMatchTier::Hash);
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
        ->and($result->matches->first()->level)->toBe(ConflictLevel::Yellow)
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Name);
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

it('still reports a match whose matter has been soft-deleted, with its code, instead of dropping it silently', function () {
    $existingClient = Client::factory()->create(['id_number' => '044455566677']);
    $deletedMatter = Matter::factory()->create();
    MatterParty::factory()->for($deletedMatter)->ourClient($existingClient)->create();
    $deletedMatter->delete();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '044455566677');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    // Matter::forceDeleting bị chặn — vụ việc không bao giờ thật sự biến mất, chỉ ẩn đi. Đây là
    // kiểm tra lịch sử: một dòng "xanh giả" vì matter đã xoá mềm là chính xác điều Task 7 review
    // gọi là vi phạm đạo đức nghề nghiệp (SPEC §6.10 là chức năng duy nhất nơi bỏ sót là lỗi
    // nghiêm trọng hơn báo động giả).
    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($deletedMatter->code);
});

it('still counts a matching party row that has itself been soft-deleted', function () {
    $existingClient = Client::factory()->create(['id_number' => '033322211100']);
    $otherMatter = Matter::factory()->create();
    $historicalParty = MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();
    $historicalParty->delete();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '033322211100');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($otherMatter->code);
});

it('bypasses the client portal scope so a red conflict still surfaces while a client is authenticated on the portal guard', function () {
    $existingClient = Client::factory()->create(['id_number' => '087766554433']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $clientUser = ClientUser::factory()->create();
    $this->actingAs($clientUser, 'client');

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '087766554433');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($otherMatter->code);
});

it('detects a red conflict for a new party added to an existing matter even when only that new party is passed in', function () {
    $existingClient = Client::factory()->create(['id_number' => '076655443322']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($existingClient)->create();

    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true]);

    // Caller chỉ truyền đúng bên MỚI thêm, không kèm bên khách hàng đã có sẵn của vụ — Action
    // phải tự nạp $matter->parties để biết ai là khách hàng mới, không được phép trả về vàng vì
    // caller quên truyền đủ.
    $newOpposingParty = proposedParty(PartyRole::Defendant, 'Bên mới trùng', idNumber: '076655443322');

    $result = app(RunConflictCheck::class)->handle(collect([$newOpposingParty]), $matter);

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue();
});

it('flags a party with neither id number nor phone as incomplete even when the result is green', function () {
    // Không gọi identify() với dữ liệu thật: id_number_hash và phone_normalized đều null, nên
    // Action chỉ so khớp được theo tên — mức tin cậy thấp nhất, không đủ để tin tưởng kết quả xanh.
    $party = proposedParty(PartyRole::Plaintiff, 'Người Chỉ Có Tên', isOurClient: true);

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->hasIncompleteParties())->toBeTrue()
        ->and($result->incompleteParties())->toBe(['Người Chỉ Có Tên']);

    $activity = Activity::query()->latest('id')->first();
    expect($activity->properties->get('incomplete_parties'))->toBe(['Người Chỉ Có Tên']);
});

it('does not flag a party that has both a phone and an id number as incomplete', function () {
    $party = proposedParty(PartyRole::Plaintiff, 'Người Đầy Đủ', idNumber: '001122334455', phone: '0911222333', isOurClient: true);

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->hasIncompleteParties())->toBeFalse()
        ->and($result->incompleteParties())->toBe([]);
});

it('deduplicates identical matches when two proposed parties match the same historical row', function () {
    $existingClient = Client::factory()->create(['id_number' => '065544332211']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    // Hai dòng khác nhau trên form vô tình cùng khớp một bản ghi lịch sử duy nhất.
    $duplicateA = proposedParty(PartyRole::Defendant, 'Bên trùng A', idNumber: '065544332211');
    $duplicateB = proposedParty(PartyRole::Defendant, 'Bên trùng B', idNumber: '065544332211');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $duplicateA, $duplicateB]));

    expect($result->matches)->toHaveCount(1);
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
