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
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
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

it('keeps red when the our-client role exists only on an unsaved party inside an Eloquent collection', function () {
    $existingClient = Client::factory()->create(['id_number' => '098877665544']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($existingClient)->create();

    // Vụ đang mở KHÔNG có bên khách hàng nào đã lưu sẵn — cả khách hàng mới lẫn bên đối lập đều
    // là các bên chưa lưu, thêm cùng lúc, đúng hình dạng OpenMatter sẽ dùng trước khi lưu lần đầu.
    $matter = Matter::factory()->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng mới', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '098877665544');

    // Cố ý dùng hình dạng Eloquent Collection ($matter->parties()->get() rồi push thêm bên chưa
    // lưu) — đúng hình dạng khuyến nghị ở docblock lớp và đúng hình dạng đã lộ ra lỗi
    // Collection::merge() ở fix round 2: Eloquent Collection::getDictionary() bỏ qua hẳn phần tử
    // có getKey() === null khi dựng dictionary, nên vai "khách hàng mới" (chỉ có trên bên chưa
    // lưu) từng biến mất khỏi $ourClientRoles một cách im lặng.
    $parties = $matter->parties()->get()->push($ourNewClient)->push($opposingParty);
    expect($parties)->toBeInstanceOf(Collection::class);

    $result = app(RunConflictCheck::class)->handle($parties, $matter);

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue();
});

it('bypasses the client portal scope on the existing-matter role lookup too, not just on the match search', function () {
    $existingClient = Client::factory()->create(['id_number' => '076544332211']);
    $conflictMatter = Matter::factory()->create();
    MatterParty::factory()->for($conflictMatter)->ourClient($existingClient)->create();

    $matter = Matter::factory()->create();
    // Bên khách hàng của vụ đang xét ĐÃ LƯU — vai "khách hàng mới" chỉ có thể lấy được bằng cách
    // Action tự truy vấn $matter->parties(). Nếu truy vấn đó không bỏ ClientPortalScope, nó trả
    // về rỗng trong khi guard client đang đăng nhập, y hệt lỗi ở finding 2 fix round 1 — chỉ khác
    // là lần này ở truy vấn nạp vai, không phải truy vấn tìm bản ghi trùng.
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true]);

    $clientUser = ClientUser::factory()->create();
    $this->actingAs($clientUser, 'client');

    // Chỉ truyền đúng bên mới thêm — buộc Action phải tự nạp $matter->parties() để biết ai là
    // khách hàng mới, đúng đường đi bị lỗi ở round 2.
    $newOpposingParty = proposedParty(PartyRole::Defendant, 'Bên mới trùng', idNumber: '076544332211');

    $result = app(RunConflictCheck::class)->handle(collect([$newOpposingParty]), $matter);

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue();
});

it('requires acknowledgement for a green result that still has an incomplete party, instead of rendering a plain green form', function () {
    // Không gọi identify() với dữ liệu thật => chỉ so khớp được theo tên, không tìm thấy gì
    // (level xanh) — nhưng caller không được phép coi đây là "xanh thật" nếu chỉ đọc
    // requiresAcknowledgement()/isBlocking().
    $party = proposedParty(PartyRole::Plaintiff, 'Người Chỉ Có Tên Nữa', isOurClient: true);

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->isBlocking())->toBeFalse()
        ->and($result->hasIncompleteParties())->toBeTrue()
        ->and($result->requiresAcknowledgement())->toBeTrue();
});

it('does not require acknowledgement for a genuinely green result with no incomplete parties', function () {
    $party = proposedParty(PartyRole::Plaintiff, 'Người Đầy Đủ Và Xanh', idNumber: '009988776655', phone: '0900112233', isOurClient: true);

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->level)->toBe(ConflictLevel::Green)
        ->and($result->requiresAcknowledgement())->toBeFalse();
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

it('re-checks the matter’s already-existing parties too, not just the newly passed one, so a conflict that only becomes red later is still caught', function () {
    // Vụ B: Y là khách hàng của văn phòng (is_our_client), vai mặc định nguyên đơn.
    $yClient = Client::factory()->create(['id_number' => '087654321098', 'name' => 'Y bên kia']);
    $matterB = Matter::factory()->create();
    MatterParty::factory()->for($matterB)->ourClient($yClient)->create();

    // Vụ A: bị đơn Y được thêm TRƯỚC, vào lúc vụ A CHƯA có bên khách hàng nào — nên lần kiểm tra
    // lúc đó (nếu có chạy) không thể lên đỏ, vì chưa có ai để "đối lập". Y trùng số căn cước với
    // chính khách hàng Y ở vụ B.
    $matterA = Matter::factory()->create();
    MatterParty::factory()->for($matterA)->create(['role' => PartyRole::Defendant, 'name' => 'Y bên kia (bị đơn)'])
        ->identify('087654321098', null)
        ->save();

    // Sau đó khách hàng mới X (nguyên đơn) được thêm vào vụ A. Caller chỉ truyền đúng bên MỚI —
    // đúng như docblock lớp cho phép — không kèm bị đơn Y đã có sẵn.
    $newOurClient = proposedParty(PartyRole::Plaintiff, 'X khách hàng mới', isOurClient: true);

    $result = app(RunConflictCheck::class)->handle(collect([$newOurClient]), $matterA);

    // Vụ A giờ đã đủ điều kiện đỏ: Y đối lập X (defendant/plaintiff) VÀ Y là khách hàng của văn
    // phòng ở vụ B — nhưng chỉ phát hiện được nếu Y (đã có sẵn trong vụ A) cũng được xét lại, chứ
    // không chỉ X (bên vừa truyền vào).
    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->pluck('matterCode')->all())->toContain($matterB->code);
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

it('deduplicates identical matches when the exact same proposed party would otherwise be counted twice', function () {
    $existingClient = Client::factory()->create(['id_number' => '065544332211']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    // Hai dòng KHÁC NHAU trên form, cùng tên, cùng vai, cùng định danh — vô tình gõ trùng cùng
    // một bị đơn hai lần. Đây vẫn là một khớp DUY NHẤT theo mọi trường hiển thị (kể cả bên phía
    // mình gây ra khớp, R13d), nên vẫn phải gộp lại.
    $duplicateA = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '065544332211');
    $duplicateB = proposedParty(PartyRole::Defendant, 'Bên trùng', idNumber: '065544332211');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $duplicateA, $duplicateB]));

    expect($result->matches)->toHaveCount(1);
});

/**
 * R13(d)/`conflict-07` (M6.5 Task 8): trước bản sửa này, hai bên KHÁC NHAU của form cùng khớp một
 * bản ghi lịch sử bị gộp thành MỘT dòng — người xem xét mất dấu rằng CẢ HAI bên mình vừa nhập đều
 * đáng ngờ, không chỉ một. `unique()` giờ tính cả `ourPartyRole`/`ourPartyName` vào khoá gộp, nên
 * hai bên khác nhau (dù khớp cùng một bản ghi) giờ giữ lại thành hai dòng riêng, mỗi dòng nêu đúng
 * bên phía mình đã gây ra nó.
 */
it('keeps a separate match per distinct our-side party even when they match the same historical row', function () {
    $existingClient = Client::factory()->create(['id_number' => '065544332211']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng khác', isOurClient: true);
    $duplicateA = proposedParty(PartyRole::Defendant, 'Bên trùng A', idNumber: '065544332211');
    $duplicateB = proposedParty(PartyRole::Defendant, 'Bên trùng B', idNumber: '065544332211');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $duplicateA, $duplicateB]));

    expect($result->matches)->toHaveCount(2)
        ->and($result->matches->pluck('ourPartyName')->sort()->values()->all())->toBe(['Bên trùng A', 'Bên trùng B']);
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

it('assigns the phone tier to a match found only by phone number', function () {
    // Tầng điện thoại (SPEC §6.10 bước 2, "rất khả nghi") trước đây không có test nào chạm tới,
    // dù nó là tầng MẠNH DUY NHẤT khi một bên không có số căn cước — tình trạng bình thường của
    // bên đối lập.
    $existingClient = Client::factory()->create([
        'id_number' => '012345678901',
        'phone' => '0912345678',
        'name' => 'Đặng Thị Mai',
    ]);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    // Không có số căn cước, tên khác hẳn: chỉ còn số điện thoại để khớp.
    $party = proposedParty(PartyRole::Related, 'Người liên quan không rõ nhân thân', phone: '0912345678');

    $result = app(RunConflictCheck::class)->handle(collect([$party]));

    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->matches)->toHaveCount(1);

    $match = $result->matches->first();
    expect($match->tier)->toBe(ConflictMatchTier::Phone)
        ->and($match->matterCode)->toBe($otherMatter->code)
        ->and($match->partyName)->toBe('Đặng Thị Mai');
});

it('blocks at red level when a phone-only match is our client in another matter and the roles oppose', function () {
    $existingClient = Client::factory()->create([
        'id_number' => '023456789012',
        'phone' => '0987654321',
        'name' => 'Hoàng Văn Bình',
    ]);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng mới hoàn toàn', isOurClient: true);
    // Bên đối lập không có số căn cước — đúng tình trạng thường gặp — nên chỉ tầng điện thoại
    // có thể phát hiện rằng đây chính là khách hàng của văn phòng ở vụ khác.
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bị đơn chưa rõ giấy tờ', phone: '0987654321');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue()
        ->and($result->matches)->toHaveCount(1)
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Phone)
        ->and($result->matches->first()->level)->toBe(ConflictLevel::Red);
});

it('still reaches red on the phone tier when the opposing party’s number was typed without its leading zero', function () {
    // Bản xuất Excel ăn mất số 0 đứng đầu: '0909111222' về thành '909111222'. Trước khi
    // Normalizer::phone() biết dạng này, hai cách viết cho ra hai giá trị khác nhau và tầng
    // điện thoại — tầng mạnh duy nhất khi không có số căn cước — trả về XANH.
    $existingClient = Client::factory()->create([
        'id_number' => '034567890123',
        'phone' => '0909111222',
        'name' => 'Vũ Thị Hạnh',
    ]);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $ourNewClient = proposedParty(PartyRole::Plaintiff, 'Khách hàng mới hoàn toàn', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bị đơn nhập từ bảng tính', phone: '909111222');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue()
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Phone)
        ->and($result->matches->pluck('matterCode')->all())->toContain($otherMatter->code);
});

/**
 * R13(b)/`conflict-03` (M6.5 Task 8) — brief test (b), "hai khách hàng của văn phòng ... chưa
 * từng có vụ nào, ở hai phía nguyên đơn và bị đơn trong cùng form mở vụ: đỏ". Trước bản sửa này
 * `matchesFor()` chỉ tìm bản ghi trùng ở CÁC VỤ KHÁC; hai bên đề xuất trong cùng lượt mở vụ này
 * chưa nằm trong DB nên không có gì để truy vấn, và kết quả là XANH SẠCH dù hệ thống có đủ dữ kiện
 * (cả hai đều `is_our_client`, vai đối lập). Đây là hình dạng "mở vụ việc" ($matter = null).
 */
it('flags red for two of our own clients in opposing roles within the same brand-new matter being opened, with no history involved', function () {
    $x = Client::factory()->create(['name' => 'Khách X mới']);
    $w = Client::factory()->create(['name' => 'Khách W mới']);

    $ourClientX = proposedParty(PartyRole::Plaintiff, $x->name, isOurClient: true, client: $x);
    $ourClientW = proposedParty(PartyRole::Defendant, $w->name, isOurClient: true, client: $w);

    $result = app(RunConflictCheck::class)->handle(collect([$ourClientX, $ourClientW]));

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->isBlocking())->toBeTrue()
        ->and($result->matches)->not->toBeEmpty()
        ->and($result->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeTrue();
});

/** Cùng R13(b), hình dạng "thêm bên vào vụ việc đang chạy" ($matter đã tồn tại). */
it('flags red for two of our own clients in opposing roles when the second one is added to an existing matter', function () {
    $x = Client::factory()->create();
    $w = Client::factory()->create();

    $matter = Matter::factory()->create();
    $ourClientX = MatterParty::factory()->for($matter)->ourClient($x, PartyRole::Plaintiff)->create();

    $newOurClientW = proposedParty(PartyRole::Defendant, 'Khách W mới', isOurClient: true, client: $w);

    $result = app(RunConflictCheck::class)->handle(collect([$newOurClientW]), $matter);

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeTrue();

    // R13(a) áp lại: chính khách hàng đó xuất hiện hai lần (client_id giống nhau) không tự xung
    // đột với chính mình, kể cả ở vai đối lập.
    $sameClientAgain = proposedParty(PartyRole::Defendant, 'Khách X (lần hai)', isOurClient: true, client: $x);
    $resultSameClient = app(RunConflictCheck::class)->handle(collect([$sameClientAgain]), $matter);

    expect($resultSameClient->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeFalse();
});

/**
 * R13(c)/`conflict-01` (M6.5 Task 8) — brief test (c) một nửa (nửa còn lại, xuyên suốt hai Action
 * thật qua Livewire, ở `tests/Feature/Filament/ConflictCheckFlowTest.php`). Đây là hợp đồng THUẦN
 * của `RunConflictCheck` với chính cơ chế `confirmed_pairs`: một khớp đã được ghi vào
 * `matter_party_added`/`matter_opened` ở một lần chạy TRƯỚC (đúng những gì `OpenMatter`/
 * `AddMatterParty` ghi ở bước lưu) không còn được tính vào `level`/`matches` ở lần chạy SAU trên
 * CÙNG vụ việc, nhưng vẫn xuất hiện trong `confirmedMatches`.
 */
it('excludes an already-confirmed pair from the new matches on a later run of the same matter, but keeps it visible', function () {
    $existingClient = Client::factory()->create(['id_number' => '051122334455']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $matter = Matter::factory()->create();
    // Có đủ định danh (không rơi vào incompleteParties()) để lần chạy thứ hai thật sự đo đúng MỘT
    // biến: hiệu lực của confirmed_pairs, không lẫn với cảnh báo "thiếu định danh" của một test
    // giả lập lại toàn bộ $matter->parties() làm $parties — điều AddMatterParty thật không làm
    // (nó chỉ truyền đúng bên mới).
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true])
        ->identify('090000000001', '0900000001')->save();
    $opposingParty = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn trùng']);
    $opposingParty->identify('051122334455', null)->save();

    $firstRun = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

    expect($firstRun->level)->toBe(ConflictLevel::Red)
        ->and($firstRun->matches)->toHaveCount(1)
        ->and($firstRun->confirmedMatches)->toBeEmpty();

    $pairKey = $firstRun->matches->first()->pairKey();
    expect($pairKey)->not->toBeNull();

    // Đúng những gì OpenMatter/AddMatterParty ghi vào matter_opened/matter_party_added ở bước lưu
    // sau khi một manager ghi đè (hoặc một xác nhận vàng) đã được chấp nhận cho lần chạy TRƯỚC —
    // fix round 1 (C1): lưu CẢ mức đã chấp nhận, không chỉ chữ ký.
    Audit::record('matter_party_added', $matter, [
        'confirmed_pairs' => [['pair_key' => $pairKey, 'level' => ConflictLevel::Red->value]],
    ]);

    $secondRun = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

    expect($secondRun->level)->toBe(ConflictLevel::Green)
        ->and($secondRun->isBlocking())->toBeFalse()
        ->and($secondRun->requiresAcknowledgement())->toBeFalse()
        ->and($secondRun->matches)->toBeEmpty()
        ->and($secondRun->confirmedMatches)->toHaveCount(1)
        ->and($secondRun->confirmedMatches->first()->matterCode)->toBe($otherMatter->code)
        ->and($secondRun->confirmedMatches->first()->level)->toBe(ConflictLevel::Red);
});

/**
 * R13(d)/`conflict-07` (M6.5 Task 8) — brief test (d): kết quả nêu đúng bên phía mình gây ra khớp,
 * KHÔNG phải tên/vai của bên tìm thấy. Hai bên trong cùng vụ việc đang mở khớp cùng một hồ sơ theo
 * hai vai KHÁC NHAU (`Related` và `Defendant`) để phân biệt rõ "bên tìm thấy" (luôn `Plaintiff` ở
 * hồ sơ kia) khỏi "bên phía mình" (vai của chính bên trong form).
 */
it('labels each match with the role and name of the party on our own side that caused it, not the found party', function () {
    $existingClient = Client::factory()->create(['id_number' => '061122334455', 'name' => 'Người bị trùng ở hồ sơ khác']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient, PartyRole::Plaintiff)->create();

    $ourNewClient = proposedParty(PartyRole::Related, 'Khách hàng của mình', isOurClient: true);
    $opposingParty = proposedParty(PartyRole::Defendant, 'Bị đơn gõ tay', idNumber: '061122334455');

    $result = app(RunConflictCheck::class)->handle(collect([$ourNewClient, $opposingParty]));

    $match = $result->matches->firstWhere('ourPartyName', 'Bị đơn gõ tay');

    expect($match)->not->toBeNull()
        ->and($match->ourPartyRole)->toBe(PartyRole::Defendant)
        ->and($match->ourPartyName)->toBe('Bị đơn gõ tay')
        // Bên TÌM THẤY (party_role/party_name) vẫn phải là hồ sơ kia, không đổi theo bên phía mình.
        ->and($match->partyRole)->toBe(PartyRole::Plaintiff)
        ->and($match->partyName)->toBe('Người bị trùng ở hồ sơ khác');
});

/**
 * C1 (Critical, fix round 1, `conflict-01`) — probe 1 của phán quyết: "acknowledge a Yellow, then
 * add a party that turns the same pair RED → blocks". Cùng HAI dòng (`$party` và bên khách hàng cũ
 * của `$otherMatter`) khớp Vàng lúc vai còn `related` (không đối lập), được xác nhận; sau đó vai
 * của CHÍNH dòng đó đổi sang `defendant` (đối lập với khách hàng chính của vụ) — cùng pairKey (hai
 * ID dòng không đổi), nhưng mức giờ là Đỏ. Trước bản sửa C1, `confirmed_pairs` chỉ lưu chữ ký (không
 * lưu mức) nên cặp này bị đọc nhầm là "đã xử lý" và hiện Xanh — đúng lỗ hổng phải chặn lại được.
 */
it('does not let a previously-confirmed Yellow suppress the same pair once it escalates to Red', function () {
    $existingClient = Client::factory()->create(['id_number' => '052233445566']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient, PartyRole::Plaintiff)->create();

    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true])
        ->identify('090000000011', '0900000011')->save();
    // Vai ban đầu `related` — không đối lập, nên dù trùng hash vẫn chỉ Vàng (xem docblock lớp).
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => 'Bên sẽ đổi vai']);
    $party->identify('052233445566', null)->save();

    $firstRun = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($firstRun->level)->toBe(ConflictLevel::Yellow)
        ->and($firstRun->matches)->toHaveCount(1);

    $pairKey = $firstRun->matches->first()->pairKey();
    expect($pairKey)->not->toBeNull();

    Audit::record('matter_party_added', $matter, [
        'confirmed_pairs' => [['pair_key' => $pairKey, 'level' => ConflictLevel::Yellow->value]],
    ]);

    // CÙNG hai dòng (pairKey không đổi), vai đổi sang defendant — giờ đối lập với khách hàng
    // chính (Plaintiff) của vụ.
    $party->role = PartyRole::Defendant;
    $party->save();

    $secondRun = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

    expect($secondRun->level)->toBe(ConflictLevel::Red)
        ->and($secondRun->isBlocking())->toBeTrue()
        ->and($secondRun->matches)->toHaveCount(1)
        ->and($secondRun->matches->first()->pairKey())->toBe($pairKey)
        ->and($secondRun->confirmedMatches)->toBeEmpty();
});

/**
 * Fix round 1, minor ruling — khoá `unique()` ở `handle()` phải gồm `pairKey()`, không chỉ NĂM
 * trường hiển thị (mã hồ sơ, vai, tên, mức, tầng, vai/tên phía mình). Hai dòng `matter_parties` Ở
 * PHÍA MÌNH khác nhau thật (nhập trùng: hai khách hàng cùng tên, cùng vai) cùng khớp một bản ghi
 * lịch sử ĐỎ — giống hệt nhau ở cả bảy trường hiển thị, kể cả `ourPartyName`, nhưng là HAI cặp
 * (dòng, dòng tìm thấy) thật khác nhau, nên phải giữ CẢ HAI. Nếu khoá gộp không có `pairKey()`,
 * dòng thứ hai biến mất — và nếu nó là dòng VỪA thêm trong khi dòng kia đã được ghi đè, mất nó
 * nghĩa là một xung đột MỚI bị gộp nhầm vào một xung đột ĐÃ xử lý.
 */
it('keeps two matches separate when our own side has a genuine duplicate row matching the same historical party', function () {
    $existingClient = Client::factory()->create(['id_number' => '051122334455']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient, PartyRole::Plaintiff)->create();

    $matter = Matter::factory()->create();
    // Hai dòng THẬT khác nhau, nhập trùng bởi trợ lý: cùng vai, cùng tên — nhưng là hai bản ghi
    // matter_parties riêng, nên phải có hai pairKey() khác nhau khi cùng khớp một bản ghi.
    $duplicateA = MatterParty::factory()->for($matter)
        ->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn nhập trùng'])
        ->identify('051122334455', null);
    $duplicateA->save();
    $duplicateB = MatterParty::factory()->for($matter)
        ->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn nhập trùng'])
        ->identify('051122334455', null);
    $duplicateB->save();

    $result = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->matches)->toHaveCount(2);

    $pairKeys = $result->matches->map(fn ($match) => $match->pairKey())->all();
    expect($pairKeys[0])->not->toBeNull()
        ->and($pairKeys[1])->not->toBeNull()
        ->and($pairKeys[0])->not->toBe($pairKeys[1]);
});

/**
 * I1 (ruling, fix round 1) — `sameMatterOppositionMatches()` (R13b) giờ tham gia R13(c) như mọi
 * khớp khác: một manager ghi đè một xung đột "hai khách hàng của mình đối lập trong cùng vụ" một
 * lần, rồi luật sư phụ trách thêm một bên KHÔNG liên quan — không được chặn lại.
 */
it('does not re-block an overridden same-matter opposition when an unrelated party is added afterwards', function () {
    $x = Client::factory()->create();
    $w = Client::factory()->create();

    $matter = Matter::factory()->create();
    $ourClientX = MatterParty::factory()->for($matter)->ourClient($x, PartyRole::Plaintiff)->create();
    $ourClientW = MatterParty::factory()->for($matter)->ourClient($w, PartyRole::Defendant)->create();

    $firstRun = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($firstRun->level)->toBe(ConflictLevel::Red)
        ->and($firstRun->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeTrue();

    // sameMatterOppositionMatches() dựng MỘT ConflictMatch cho MỖI CHIỀU của cặp đối lập (X nhìn
    // W, và W nhìn X) — hai pairKey khác nhau (thứ tự id đảo ngược). Ghi đè thật qua OpenMatter
    // ghi CẢ hai (chúng đều nằm trong cùng $result->matches ở bước lưu); test này phải làm y hệt,
    // không chỉ ghi MỘT chiều.
    $sameMatterMatches = $firstRun->matches->filter(fn ($match) => $match->tier === ConflictMatchTier::SameMatter);
    expect($sameMatterMatches)->toHaveCount(2);

    Audit::record('matter_opened', $matter, [
        'confirmed_pairs' => $sameMatterMatches->map(fn ($match) => [
            'pair_key' => $match->pairKey(),
            'level' => ConflictLevel::Red->value,
        ])->all(),
    ]);

    $unrelatedParty = proposedParty(PartyRole::Related, 'Nhân chứng không liên quan', idNumber: '099888777666');

    $secondRun = app(RunConflictCheck::class)->handle(collect([$unrelatedParty]), $matter);

    expect($secondRun->level)->toBe(ConflictLevel::Green)
        ->and($secondRun->isBlocking())->toBeFalse()
        ->and($secondRun->matches)->toBeEmpty()
        ->and($secondRun->confirmedMatches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeTrue();
});

/** I1 (ruling, fix round 1, R14): một bên đã GỠ (xoá mềm) khỏi CHÍNH vụ việc không còn tham gia sameMatterOppositionMatches(). */
it('no longer produces a same-matter match once the party has been soft-deleted from the matter', function () {
    $x = Client::factory()->create();
    $w = Client::factory()->create();

    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($x, PartyRole::Plaintiff)->create();
    $ourClientW = MatterParty::factory()->for($matter)->ourClient($w, PartyRole::Defendant)->create();

    $before = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($before->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeTrue();

    $ourClientW->delete();

    $after = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($after->matches->contains(fn ($match) => $match->tier === ConflictMatchTier::SameMatter))->toBeFalse()
        ->and($after->level)->toBe(ConflictLevel::Green);
});

/**
 * I2 (fix round 1) — mutation probe riêng cho NHÁNH `orWhereNull('client_id')` của R13(a). Đây là
 * nhánh chỉ hoạt động khi bên ĐANG XÉT (không phải bên tìm thấy) là `is_our_client` kèm
 * `client_id` thật (đúng điều kiện `when()` mở khối loại trừ) — nên bên hỏng phải nằm ở phía TÌM
 * THẤY: một dòng dữ liệu cũ/hỏng (`is_our_client = true` nhưng `client_id` rỗng — không thể tạo
 * qua `BuildsMatterParties` hôm nay, nhưng có thể tồn tại từ trước) ở một vụ KHÁC, trùng hash một
 * cách tình cờ với khách hàng A đang được kiểm tra. Không có nhánh `orWhereNull`, SQL ba trạng
 * thái khiến `client_id != $party->client_id` không bao giờ TRUE với `client_id IS NULL`, nên
 * dòng hỏng biến mất khỏi kết quả một cách im lặng — đúng lỗ hổng cần chặn.
 */
it('still matches a legacy party row that claims our-client but has no client_id, instead of silently excluding it', function () {
    $a = Client::factory()->create(['id_number' => '071900000001']);

    $otherMatter = Matter::factory()->create();
    $legacyRow = MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Defendant,
        'is_our_client' => true,
        'client_id' => null,
    ]);
    $legacyRow->identify('071900000001', null)->save();

    $ourClientA = proposedParty(PartyRole::Plaintiff, $a->name, idNumber: $a->id_number, isOurClient: true, client: $a);

    $result = app(RunConflictCheck::class)->handle(collect([$ourClientA]));

    expect($result->matches)->not->toBeEmpty()
        ->and($result->matches->pluck('tier')->all())->toContain(ConflictMatchTier::Hash);
});

/**
 * I2 (fix round 1) — mutation probe riêng cho NHÁNH `orWhere('client_id', '!=', ...)` của R13(a):
 * một khách hàng KHÁC (client_id khác) trùng định danh vẫn phải khớp — không bị loại nhầm bởi
 * phép loại trừ self-match, vốn chỉ được áp cho CÙNG một client_id.
 */
it('still matches a different client sharing the same identity, instead of treating every our-client row as a self-match', function () {
    $a = Client::factory()->create(['id_number' => '071900000002']);
    $b = Client::factory()->create(); // client_id khác — không phải "chính mình".

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($a, PartyRole::Plaintiff)->create();

    $matter = Matter::factory()->create();
    $bParty = proposedParty(PartyRole::Defendant, $b->name, isOurClient: true, client: $b);
    $bParty->identify('071900000002', null);

    $result = app(RunConflictCheck::class)->handle(collect([$bParty]), $matter);

    expect($result->matches)->not->toBeEmpty()
        ->and($result->matches->pluck('tier')->all())->toContain(ConflictMatchTier::Hash);
});
