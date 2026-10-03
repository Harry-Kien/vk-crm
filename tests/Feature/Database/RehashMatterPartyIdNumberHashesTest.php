<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Normalizer;
use Illuminate\Support\Facades\DB;

/**
 * M8 Task 4 (SPEC §10.5): migration `2026_10_01_000001_rehash_matter_party_id_number_hashes.php`
 * đưa `matter_parties.id_number_hash` từ `sha256` TRẦN sang HMAC-SHA256 khoá bằng `APP_KEY` — đúng
 * giá trị `Normalizer::idNumberHash()` tính từ bản sửa này.
 *
 * Migration đã chạy xong trên CSDL rỗng trước mọi test (RefreshDatabase), nên test dựng lại TRẠNG
 * THÁI CŨ bằng cách ghi thẳng `sha256` trần vào cột (đúng thứ bản trước ghi), rồi gọi lại `up()`/
 * `down()` của chính tệp migration — cùng thành ngữ `BackfillClientUsersActivatedAtTest`.
 */
function rehashMatterPartyIdNumberHashesMigration(): object
{
    return require database_path('migrations/2026_10_01_000001_rehash_matter_party_id_number_hashes.php');
}

/** Đưa một dòng về đúng hình dạng trước Task 4: `sha256` trần của dãy chữ số. */
function withBareSha256(MatterParty $party, ?string $idNumber): MatterParty
{
    DB::table('matter_parties')->where('id', $party->id)->update([
        'id_number_hash' => $idNumber === null ? null : hash('sha256', (string) preg_replace('/\D+/', '', $idNumber)),
    ]);

    return $party;
}

function storedHashOf(MatterParty $party): ?string
{
    return DB::table('matter_parties')->where('id', $party->id)->value('id_number_hash');
}

it('rehashes a client-linked party from the client record, to exactly what Normalizer computes now', function () {
    $client = Client::factory()->create(['id_number' => '079 188 123 456']);
    $party = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create(), '079188123456');

    expect(storedHashOf($party))->toBe(hash('sha256', '079188123456'));

    rehashMatterPartyIdNumberHashesMigration()->up();

    expect(storedHashOf($party))->toBe(Normalizer::idNumberHash('079188123456'))
        ->and(storedHashOf($party))->toBe(hash_hmac('sha256', '079188123456', (string) config('app.key')));
});

/**
 * Bên đối lập không liên kết khách hàng: số thô CHƯA BAO GIỜ được lưu, nên không có gì để tính
 * lại. `NULL` — chấp nhận được vì chưa có dữ liệu thật (ra mắt bị chặn bởi M8); giữ `sha256` trần
 * là giữ đúng chỗ lộ migration này tồn tại để đóng.
 */
it('nulls the hash of a party with no client record, whose raw number was never stored', function () {
    $opposing = withBareSha256(
        MatterParty::factory()->for(Matter::factory()->create())->identify('001199007788', '0911222333')->create(),
        '001199007788',
    );

    rehashMatterPartyIdNumberHashesMigration()->up();

    $row = DB::table('matter_parties')->where('id', $opposing->id)->first();

    expect($row->id_number_hash)->toBeNull()
        // Chỉ cột băm CCCD đổi: số điện thoại chuẩn hoá và tên giữ nguyên.
        ->and($row->phone_normalized)->toBe('84911222333')
        ->and($row->name)->toBe($opposing->name);
});

it('treats a legacy our-client row without a client record like any other row with no raw source', function () {
    $legacy = MatterParty::factory()->for(Matter::factory()->create())->create(['is_our_client' => true, 'client_id' => null]);
    withBareSha256($legacy, '071900000001');

    rehashMatterPartyIdNumberHashesMigration()->up();

    expect(storedHashOf($legacy))->toBeNull();
});

it('rehashes soft-deleted parties and parties of soft-deleted clients too', function () {
    $client = Client::factory()->create(['id_number' => '079188000111']);
    $removedParty = MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    withBareSha256($removedParty, '079188000111');
    $removedParty->delete();

    $formerClient = Client::factory()->create(['id_number' => '079188000222']);
    $partyOfFormerClient = MatterParty::factory()->for(Matter::factory()->create())->ourClient($formerClient)->create();
    withBareSha256($partyOfFormerClient, '079188000222');
    $formerClient->delete();

    $removedOpposing = MatterParty::factory()->for(Matter::factory()->create())->identify('001199000333', null)->create();
    withBareSha256($removedOpposing, '001199000333');
    $removedOpposing->delete();

    rehashMatterPartyIdNumberHashesMigration()->up();

    expect(storedHashOf($removedParty))->toBe(Normalizer::idNumberHash('079188000111'))
        ->and(storedHashOf($partyOfFormerClient))->toBe(Normalizer::idNumberHash('079188000222'))
        ->and(storedHashOf($removedOpposing))->toBeNull();
});

/** Bất biến "bên khách hàng phản chiếu hồ sơ khách hàng" — kể cả một dòng đã lệch từ trước. */
it('mirrors the client record for every client-linked row, including a drifted empty one and a client without a number', function () {
    $client = Client::factory()->create(['id_number' => '079188000444']);
    $drifted = MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create();
    withBareSha256($drifted, null);

    $noNumber = Client::factory()->create(['id_number' => null]);
    $partyWithoutNumber = MatterParty::factory()->for(Matter::factory()->create())->ourClient($noNumber)->create();
    withBareSha256($partyWithoutNumber, '079188000555');

    rehashMatterPartyIdNumberHashesMigration()->up();

    expect(storedHashOf($drifted))->toBe(Normalizer::idNumberHash('079188000444'))
        ->and(storedHashOf($partyWithoutNumber))->toBeNull();
});

it('is idempotent on client-linked rows', function () {
    $client = Client::factory()->create(['id_number' => '079188000666']);
    $party = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create(), '079188000666');

    rehashMatterPartyIdNumberHashesMigration()->up();
    rehashMatterPartyIdNumberHashesMigration()->up();

    expect(storedHashOf($party))->toBe(Normalizer::idNumberHash('079188000666'));
});

/**
 * Sau migration, tầng "chắc chắn" (R13, `ConflictMatchTier::Hash`) của kiểm tra xung đột lợi ích
 * vẫn thấy khách hàng cũ — giá trị migration ghi đúng là giá trị ứng dụng tính khi so.
 */
it('keeps the id-number tier of the conflict check matching an existing client after the rehash', function () {
    $existing = Client::factory()->create(['id_number' => '079188000777', 'name' => 'Khách hàng cũ']);
    $otherMatter = Matter::factory()->create();
    withBareSha256(MatterParty::factory()->for($otherMatter)->ourClient($existing)->create(), '079188000777');

    rehashMatterPartyIdNumberHashesMigration()->up();

    $newClient = (new MatterParty(['role' => PartyRole::Plaintiff, 'name' => 'Khách hàng mới', 'is_our_client' => true]))->identify(null, null);
    $opposing = (new MatterParty(['role' => PartyRole::Defendant, 'name' => 'Bị đơn trùng số']))->identify('079 188 000 777', null);

    $result = app(RunConflictCheck::class)->handle(collect([$newClient, $opposing]));

    expect($result->matches->pluck('tier')->all())->toContain(ConflictMatchTier::Hash)
        ->and($result->matches->pluck('matterCode')->all())->toContain($otherMatter->code);
});

/**
 * Một `clients.id_number` không giải mã được bằng `APP_KEY` hiện tại nghĩa là máy đang chạy SAI
 * khoá (sinh khoá mới, chép nhầm `.env`). Đi tiếp thì migration xoá hash của MỌI bên khách hàng —
 * kiểm tra xung đột lợi ích mù vĩnh viễn — nên nó từ chối, và không ghi gì cả.
 */
it('refuses to run, and changes nothing, when a client id number cannot be decrypted with the current APP_KEY', function () {
    $readable = Client::factory()->create(['id_number' => '079188000888']);
    $readableParty = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->ourClient($readable)->create(), '079188000888');

    $unreadable = Client::factory()->create(['id_number' => '079188000999']);
    $unreadableParty = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->ourClient($unreadable)->create(), '079188000999');
    DB::table('clients')->where('id', $unreadable->id)->update(['id_number' => 'khong-phai-ban-ma-cua-khoa-nay']);

    expect(fn () => rehashMatterPartyIdNumberHashesMigration()->up())
        ->toThrow(RuntimeException::class, 'APP_KEY');

    expect(storedHashOf($readableParty))->toBe(hash('sha256', '079188000888'))
        ->and(storedHashOf($unreadableParty))->toBe(hash('sha256', '079188000999'));
});

/**
 * `down()` đưa bên khách hàng về `sha256` trần — đúng thứ mã TRƯỚC Task 4 tính khi so, để một lần
 * lùi phiên bản không làm kiểm tra xung đột lợi ích mù im lặng với mọi khách hàng. Bên không có
 * nguồn số thô giữ `NULL`: không có gì để khôi phục.
 */
it('restores the pre-Task-4 bare hash for client-linked rows on down(), leaving rows without a raw source null', function () {
    $client = Client::factory()->create(['id_number' => '079188001000']);
    $party = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->ourClient($client)->create(), '079188001000');
    $opposing = withBareSha256(MatterParty::factory()->for(Matter::factory()->create())->identify('001199001000', null)->create(), '001199001000');

    $migration = rehashMatterPartyIdNumberHashesMigration();
    $migration->up();
    $migration->down();

    expect(storedHashOf($party))->toBe(hash('sha256', '079188001000'))
        ->and(storedHashOf($opposing))->toBeNull();
});
