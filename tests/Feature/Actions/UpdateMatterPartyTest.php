<?php

use App\Actions\AddMatterParty;
use App\Actions\UpdateMatterParty;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Mirror của AddMatterPartyTest, cho `App\Actions\UpdateMatterParty` (M6.5 Task 9, `conflict-05`):
 * trước Task 9 không Action nào sửa được một bên đã có.
 */
it('fixes a typo in the phone number, and a later check at another matter finds the corrected match', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create([
        'role' => PartyRole::Defendant,
        'name' => 'Ông Gõ Sai Số',
    ]);
    $party->identify(null, '0900000000')->save(); // số gõ sai lúc tiếp nhận.

    app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'name' => $party->name,
        'phone' => '0912345678', // số đúng.
    ]);

    $party->refresh();
    expect($party->phone_normalized)->toBe(Normalizer::phone('0912345678'))
        ->and($party->phone_normalized)->not->toBe(Normalizer::phone('0900000000'));

    // Audit không bao giờ ghi số CCCD/điện thoại thô (R14) — chỉ tên trường đã đổi.
    $updated = Activity::query()->where('event', 'matter_party_updated')->latest('id')->first();
    expect($updated->properties->get('changed_fields'))->toContain('phone')
        ->and(collect($updated->properties->all())->flatten(5)->implode(' '))->not->toContain('0912345678');

    // Một vụ kiện SAU đó, đúng người này với đúng số MỚI, giờ khớp.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $addition = app(AddMatterParty::class)->handle($otherMatter, $lawyer, [
        'role' => PartyRole::Plaintiff->value,
        'name' => 'Ông Trùng Số Điện Thoại',
        'phone' => '0912345678',
    ], acknowledged: ConflictLevel::Yellow);

    expect($addition->result->level)->toBe(ConflictLevel::Yellow)
        ->and($addition->result->matches->pluck('matterCode')->all())->toContain($matter->code);
});

it('blocks a red conflict created by the edit, and a manager can override it with a reason', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    $matter->parties()->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Khách hàng hiện hữu']);

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => 'Bên vô hại']);

    expect(fn () => app(UpdateMatterParty::class)->handle($party, $manager, [
        'role' => PartyRole::Defendant->value,
        'name' => 'Nguyễn Văn Hùng (bị đơn)',
        'id_number' => '079012345678',
    ]))->toThrow(ConflictBlocked::class);

    $party->refresh();
    expect($party->role)->toBe(PartyRole::Related)
        ->and($party->id_number_hash)->toBeNull();

    $update = app(UpdateMatterParty::class)->handle($party, $manager, [
        'role' => PartyRole::Defendant->value,
        'name' => 'Nguyễn Văn Hùng (bị đơn)',
        'id_number' => '079012345678',
    ], 'Đã xác minh, không phải cùng một người, đồng ý lưu.');

    expect($update->overridden)->toBeTrue()
        ->and($update->party->role)->toBe(PartyRole::Defendant);
});

it('rejects a user without matter.update, regardless of the owning matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->create(['name' => 'Trước khi sửa']);

    expect(fn () => app(UpdateMatterParty::class)->handle($party, $accountant, ['role' => PartyRole::Related->value, 'name' => 'Sau khi sửa']))
        ->toThrow(AuthorizationException::class);

    expect($party->fresh()->name)->toBe('Trước khi sửa');
});

/**
 * R14 (brief): "sửa không xoá lịch sử" áp dụng ngược — một lượt sửa KHÔNG đụng tới định danh (chỉ
 * đổi address) không được phép làm mất một xác nhận/ghi đè đã có từ trước trên CÙNG cặp bên. Cặp
 * dương đi kèm test "clears a previously confirmed match…" bên dưới.
 */
it('keeps a previously acknowledged match confirmed when the edit does not touch identity', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    // `id_number` không trùng ai — chỉ để bên này KHÔNG "thiếu định danh"
    // (`hasIncompleteParties()`), thứ tự nó cũng đòi xác nhận, làm nhiễu phép thử ở đây.
    $addition = app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'name' => '  Lê   THỊ hoa ',
        'id_number' => '000000000099',
    ], acknowledged: ConflictLevel::Yellow);

    $party = $addition->party;

    // Sửa CHỈ địa chỉ — không đụng tên/CCCD/điện thoại/liên kết khách hàng. Hai ô id_number/phone
    // để TRỐNG nghĩa là "không đổi" (identifyKeepingWhenBlank), nên hash CCCD giữ nguyên.
    $update = app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'name' => $party->name,
        'address' => 'Địa chỉ mới',
    ]);

    expect($update->result->requiresAcknowledgement())->toBeFalse()
        ->and($update->result->confirmedMatches)->toHaveCount(1)
        ->and($update->result->matches)->toHaveCount(0);
});

/**
 * `RunConflictCheck::confirmedPairLevels()` phải đọc CẢ sự kiện `matter_party_updated`, không chỉ
 * `matter_opened`/`matter_party_added` — nếu không, một xác nhận ghi lại bởi CHÍNH
 * `UpdateMatterParty` (chứ không phải `AddMatterParty`/`OpenMatter`) biến mất khỏi lịch sử ngay ở
 * lần kiểm tra kế tiếp. Ở đây, xác nhận GỐC được tạo bởi một lượt SỬA (không phải một lượt thêm),
 * rồi một lượt SỬA thứ hai (không đụng định danh) phải đọc lại được đúng xác nhận đó.
 */
it('reads back a match confirmed by a previous edit (matter_party_updated), not only by add/open', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Lê Thị Hoa']);

    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Bên khác']);

    // Lượt sửa THỨ NHẤT: đổi tên để khớp "Lê Thị Hoa", xác nhận vàng — confirmed_pairs ghi vào
    // CHÍNH dòng matter_party_updated, không phải matter_party_added/matter_opened.
    app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'name' => 'Lê Thị Hoa',
        'id_number' => '000000000088',
    ], acknowledged: ConflictLevel::Yellow);

    expect(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();

    // Lượt sửa THỨ HAI: chỉ đổi địa chỉ, không đụng định danh — phải đọc lại được xác nhận từ
    // lượt TRƯỚC (ghi ở matter_party_updated) và không đòi xác nhận lại.
    $update = app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'name' => 'Lê Thị Hoa',
        'address' => 'Địa chỉ mới',
    ]);

    expect($update->result->requiresAcknowledgement())->toBeFalse()
        ->and($update->result->confirmedMatches)->toHaveCount(1);
});

/**
 * R14 (brief, NGUYÊN VĂN): "An identity-changing edit clears acknowledgements… Otherwise an old
 * acknowledgement would silently cover a different person." Cặp bên (dòng X ↔ dòng Y) từng được
 * xác nhận ở mức VÀNG khi X mới chỉ khớp tên (tier yếu nhất). Sửa X để thêm đúng số CCCD của Y —
 * NÂNG TẦNG khớp từ tên lên hash, tức từ "trùng tên tình cờ" thành "chắc chắn cùng một người" —
 * nhưng MỨC vẫn Vàng như cũ (hai bên không đối lập). `pairKey()` (theo ID DÒNG) không đổi, nên nếu
 * không có `ignoreConfirmedForPartyIds`, hệ thống sẽ đọc nhầm xác nhận CŨ (cho một khớp tên yếu) là
 * đã xử lý xong khớp MỚI (một khớp hash mạnh, thực chất khác hẳn) — false green đúng nghĩa.
 */
it('clears a previously confirmed match and requires re-acknowledgement when the edit changes identity, even at the same level', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherClient = Client::factory()->create(['id_number' => '090011223344', 'name' => 'Lê Thị Hoa']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($otherClient, PartyRole::Related)->create();

    // Khớp MỚI, chỉ theo TÊN (chưa nhập CCCD) — vàng, được xác nhận.
    $addition = app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Related->value,
        'name' => 'Lê Thị Hoa',
    ], acknowledged: ConflictLevel::Yellow);

    $party = $addition->party;

    // Sửa: thêm ĐÚNG số CCCD của "Lê Thị Hoa" — nâng tầng khớp từ tên lên hash, mức vẫn Vàng
    // (Related không đối lập ai). Đây là một quyết định nghiệp vụ MỚI, chưa ai từng xem.
    expect(fn () => app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Related->value,
        'name' => 'Lê Thị Hoa',
        'id_number' => '090011223344',
    ]))->toThrow(ConflictAcknowledgementRequired::class);

    $update = app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Related->value,
        'name' => 'Lê Thị Hoa',
        'id_number' => '090011223344',
    ], acknowledged: ConflictLevel::Yellow);

    expect($update->party->id_number_hash)->toBe(Normalizer::idNumberHash('090011223344'));
});

/**
 * `excludePartyId` (M6.5 Task 9): sửa một bên `is_our_client` đã có (đổi VAI và LIÊN KẾT khách
 * hàng cùng lúc) không được phép tự đối lập với chính BẢN CŨ của nó — bản cũ vẫn còn trong CSDL
 * cho tới lúc `save()`, và `existingParties()` sẽ nạp lại nó nếu không loại trừ tường minh.
 */
it('does not create a spurious same-matter conflict against a stale copy of the very party being edited', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $party = MatterParty::factory()->for($matter)->ourClient($clientA, PartyRole::Plaintiff)->create();

    $update = app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => true,
        'client_id' => $clientB->id,
        'name' => $clientB->name,
    ]);

    expect($update->result->level)->toBe(ConflictLevel::Green)
        ->and($update->result->matches)->toHaveCount(0)
        ->and($update->party->role)->toBe(PartyRole::Defendant)
        ->and($update->party->client_id)->toBe($clientB->id);
});

it('re-reads the client identity under lock right before saving, so a concurrent client edit is never lost', function () {
    // Hàng đợi `database` thay cho `sync` (mặc định test): mọi dispatch trong seam đua tranh dưới
    // đây (qua Client::updated() -> SyncClientPartyIdentities -> RecheckClientIdentityConflicts)
    // xảy ra BÊN TRONG khoá `conflict-check` mà chính UpdateMatterParty đang giữ — với `sync`, job
    // đó chạy NGAY TẠI CHỖ và tự xin lại ĐÚNG khoá đó, tự khoá chính mình 10 giây rồi mới thấy
    // ConflictCheckBusy (cùng lý lẽ `openMatterDrainDatabaseQueue()` ở `OpenMatterTest.php`/
    // `AddMatterPartyTest.php`).
    config(['queue.default' => 'database']);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '071000000101']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $raced = false;

    Activity::created(function (Activity $activity) use ($client, &$raced): void {
        if ($raced || $activity->event !== 'conflict_check_run') {
            return;
        }

        $raced = true;
        $client->update(['id_number' => '071000000102']);
    });

    app(UpdateMatterParty::class)->handle($party, $lawyer, [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $client->id,
        'name' => $client->name,
        'address' => 'Địa chỉ vẫn giữ',
    ]);

    expect($party->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('071000000102'));
});

it('leaves the party untouched when the check phase fails unexpectedly', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['name' => 'Chưa sửa được']);

    expect(fn () => app(UpdateMatterParty::class)->handle($party, $lawyer, ['role' => 'not-a-real-role', 'name' => 'X']))
        ->toThrow(ValueError::class);

    expect($party->fresh()->name)->toBe('Chưa sửa được');
});
