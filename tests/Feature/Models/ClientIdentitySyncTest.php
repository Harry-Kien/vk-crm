<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * `matter_parties.id_number_hash` / `phone_normalized` là ảnh chụp định danh của khách hàng tại
 * thời điểm bên đó được tạo — và là CẦU NỐI DUY NHẤT để kiểm tra xung đột lợi ích đọc được định
 * danh, vì `clients.id_number` đã mã hoá (SPEC §10.5) nên không truy vấn được. Khi hồ sơ khách
 * hàng được sửa (sửa số căn cước nhập sai lúc tiếp nhận), ảnh chụp cũ phải đi theo, nếu không
 * tầng "chắc chắn" của SPEC §6.10 bước 2 mù vĩnh viễn với chính khách hàng đó.
 */

/** Bên đối lập đề xuất, chưa lưu — đúng hình dạng OpenMatter dựng từ form trước khi lưu. */
function opposingPartyWith(?string $idNumber = null, ?string $phone = null): MatterParty
{
    return (new MatterParty([
        'role' => PartyRole::Defendant,
        'name' => 'Bị đơn ở vụ mới',
        'is_our_client' => false,
    ]))->identify($idNumber, $phone);
}

function ourNewClientParty(): MatterParty
{
    return new MatterParty([
        'role' => PartyRole::Plaintiff,
        'name' => 'Khách hàng mới hoàn toàn',
        'is_our_client' => true,
    ]);
}

it('re-syncs the identity snapshot on every party row when the client id number is corrected', function () {
    $client = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Lý Thị Sai Số']);
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();

    $client->update(['id_number' => '079012345699']);

    expect($party->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('079012345699'));

    // Và hệ quả nghiệp vụ thật: số ĐÚNG giờ tìm ra được khách hàng này ở vụ cũ.
    $result = app(RunConflictCheck::class)->handle(
        collect([ourNewClientParty(), opposingPartyWith(idNumber: '079012345699')])
    );

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Hash)
        ->and($result->matches->pluck('matterCode')->all())->toContain($matter->code);
});

it('re-syncs soft-deleted party rows too, because the conflict check reads them as history', function () {
    $client = Client::factory()->create(['id_number' => '066011112222']);
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();
    $party->delete();

    $client->update(['id_number' => '066033334444']);

    expect(MatterParty::withTrashed()->find($party->id)->id_number_hash)
        ->toBe(Normalizer::idNumberHash('066033334444'));

    // RunConflictCheck cố tình đọc cả bên đã xoá mềm; một dòng cũ gây vàng giả rẻ hơn rất nhiều
    // so với một xung đột bị bỏ sót, nên việc đồng bộ phải áp dụng cùng một sự bất đối xứng đó.
    $result = app(RunConflictCheck::class)->handle(
        collect([ourNewClientParty(), opposingPartyWith(idNumber: '066033334444')])
    );

    expect($result->level)->toBe(ConflictLevel::Red);
});

it('re-syncs the normalized phone when the client phone is corrected', function () {
    $client = Client::factory()->create(['id_number' => '011000111222', 'phone' => '0901000111']);
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();

    $client->update(['phone' => '0901000999']);

    expect($party->fresh()->phone_normalized)->toBe('84901000999');

    $result = app(RunConflictCheck::class)->handle(
        collect([ourNewClientParty(), opposingPartyWith(phone: '0901000999')])
    );

    expect($result->level)->toBe(ConflictLevel::Red)
        ->and($result->matches->first()->tier)->toBe(ConflictMatchTier::Phone);
});

it('only touches the party rows that point at the client whose record changed', function () {
    $client = Client::factory()->create(['id_number' => '022000111222', 'phone' => '0902000111']);
    $otherClient = Client::factory()->create(['id_number' => '033000111222', 'phone' => '0903000111']);
    $otherParty = MatterParty::factory()->ourClient($otherClient)->create();

    // Bên nhập tay từ form (client_id null): định danh KHÔNG đến từ hồ sơ khách hàng nào, nên
    // không được phép bị ghi đè khi một hồ sơ khách hàng khác đổi.
    $formParty = MatterParty::factory()->identify('044000111222', '0904000111')->create();

    $client->update(['id_number' => '022000999888']);

    expect($otherParty->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('033000111222'))
        ->and($formParty->fresh()->id_number_hash)->toBe(Normalizer::idNumberHash('044000111222'))
        ->and($formParty->fresh()->phone_normalized)->toBe('84904000111');
});

it('records the re-sync in the activity log with the row count and never the id number itself', function () {
    $client = Client::factory()->create(['id_number' => '055000111222']);
    MatterParty::factory()->ourClient($client)->create();
    MatterParty::factory()->ourClient($client)->create();

    $client->update(['id_number' => '055000999888']);

    $resync = Activity::query()->where('event', 'client_identity_resynced')->latest('id')->first();

    expect($resync)->not->toBeNull()
        ->and($resync->properties->get('parties_resynced'))->toBe(2)
        ->and($resync->subject_id)->toBe($client->id);

    // SPEC §10.5: số căn cước không được xuất hiện ở bất kỳ đâu trong nhật ký, ở bất kỳ dạng nào.
    $everything = Activity::query()->get()->toJson();
    expect($everything)->not->toContain('055000111222')
        ->and($everything)->not->toContain('055000999888')
        ->and($everything)->not->toContain(Normalizer::idNumberHash('055000999888'));
});

it('does not re-sync or log when the client is saved without changing its identity columns', function () {
    // `id_number` dùng cast `encrypted`, mã hoá không tất định: cùng một giá trị cho ra hai chuỗi
    // ciphertext khác nhau. Test này ghim hành vi thật của isDirty/wasChanged trên cột như vậy —
    // Laravel giải mã hai phía trước khi so sánh, nên ghi lại đúng số cũ KHÔNG được coi là đổi.
    $client = Client::factory()->create(['id_number' => '077000111222', 'name' => 'Tên không đổi']);
    MatterParty::factory()->ourClient($client)->create();

    // Ghi lại đúng số cũ, và đổi một cột KHÔNG nằm trong ảnh chụp định danh.
    $client->update(['id_number' => '077000111222', 'note' => 'Ghi chú nội bộ mới']);

    expect($client->wasChanged('id_number'))->toBeFalse()
        ->and($client->wasChanged('note'))->toBeTrue()
        ->and(Activity::query()->where('event', 'client_identity_resynced')->exists())->toBeFalse();
});

it('does not log a re-sync for a client that has no party rows at all', function () {
    $client = Client::factory()->create(['id_number' => '088000111222']);

    $client->update(['id_number' => '088000999888']);

    expect(Activity::query()->where('event', 'client_identity_resynced')->exists())->toBeFalse();
});

it('re-syncs the party name when the client is renamed, so the name tier still matches', function () {
    // Tầng tên chỉ cho ra mức vàng, nhưng lệch vẫn là bỏ sót: một khách hàng đổi tên để lại các
    // dòng bên mang tên cũ, và lần kiểm tra sau tra theo tên MỚI sẽ không thấy gì.
    $client = Client::factory()->create(['name' => 'Công ty TNHH Tên Cũ', 'id_number' => null, 'phone' => null]);
    $matter = Matter::factory()->create(['client_id' => $client->id]);

    $party = MatterParty::factory()->create([
        'matter_id' => $matter->id,
        'client_id' => $client->id,
        'is_our_client' => true,
        'role' => PartyRole::Plaintiff,
        'name' => $client->name,
        'id_number_hash' => null,
        'phone_normalized' => null,
    ]);

    $client->update(['name' => 'Công ty TNHH Tên Mới']);

    expect($party->fresh()->name)->toBe('Công ty TNHH Tên Mới')
        ->and($party->fresh()->name_normalized)->toBe(Normalizer::name('Công ty TNHH Tên Mới'));

    // Và phép kiểm tra thật sự nhìn thấy tên mới ở vai đối lập.
    $result = app(RunConflictCheck::class)->handle(collect([
        ourNewClientParty(),
        (new MatterParty([
            'role' => PartyRole::Defendant,
            'name' => 'Công ty TNHH Tên Mới',
            'is_our_client' => false,
        ])),
    ]));

    // Tầng tên trần luôn dừng ở mức vàng, kể cả khi vai đối lập (SPEC §11): một cái tên trùng
    // chưa đủ để khẳng định cùng một người, nên nó gọi người xem xét chứ không chặn.
    expect($result->level)->toBe(ConflictLevel::Yellow)
        ->and($result->matches[0]->tier)->toBe(ConflictMatchTier::Name);
});

/**
 * R13(e)/`conflict-04` (M6.5 Task 8) — "thời điểm thứ ba" mà PROGRESS từng để ngỏ, giờ đã có
 * quyết định. Tái hiện đúng kịch bản của phát hiện gốc: khách hàng A gõ sai CCCD lúc tiếp nhận;
 * văn phòng mở một vụ KHÁC kiện đúng người đó (nhập tay, không đánh dấu là khách hàng của văn
 * phòng — "Ông D"), lúc đó không thấy gì trùng vì hash sai. Trợ lý sửa CCCD của A cho ĐÚNG: dòng
 * `matter_parties` của A giờ trùng hash với "Ông D" — một xung đột MỚI mà không ai từng thấy.
 */
it('notifies the lead lawyer and every manager who can view the matter when a resync reveals a new conflict', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $client = Client::factory()->create(['id_number' => '090000000001', 'name' => 'Khách hàng A']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Ông D'])
        ->identify('090000000002', null)->save();

    // Trước khi sửa: hoàn toàn xanh, đúng như phát hiện gốc mô tả.
    $before = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($before->level)->toBe(ConflictLevel::Green);

    $client->update(['id_number' => '090000000002']);

    expect($lead->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(1);

    $audit = Activity::query()->where('event', 'client_identity_conflict_detected')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject_id)->toBe($matter->id)
        ->and($audit->properties->get('client_id'))->toBe($client->id)
        ->and(collect($audit->properties->get('notified_user_ids'))->sort()->values()->all())
        ->toBe(collect([$lead->id, $manager->id])->sort()->values()->all());

    // SPEC §10.5: không ghi số CCCD thô ở BẤT KỲ dòng nhật ký nào, kể cả dòng mới này.
    $everything = Activity::query()->get()->toJson();
    expect($everything)->not->toContain('090000000001')
        ->and($everything)->not->toContain('090000000002');
});

/** Vụ `restricted`: manager không nhận (R3) dù đang hoạt động, vì họ không Gate::view() được. */
it('does not notify a manager of a restricted matter when a resync reveals a new conflict, only the lead lawyer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $client = Client::factory()->create(['id_number' => '090000000011']);
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000012', null)->save();

    $client->update(['id_number' => '090000000012']);

    expect($lead->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0);
});

/** Không có gì MỚI (kết quả vẫn xanh) thì không thông báo, không audit — không làm loãng nhật ký. */
it('does not notify or log anything when a resync does not reveal any new conflict', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    User::factory()->withRole(Role::Manager)->create();

    $client = Client::factory()->create(['id_number' => '090000000021', 'name' => 'Khách sạch']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $client->update(['id_number' => '090000000099']);

    expect($lead->notifications()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')->exists())->toBeFalse();
});

/** R8: "đang mở" — một vụ việc đã đóng (`closed_at` không rỗng) không được kiểm tra lại. */
it('does not recheck or notify for a matter that is already closed', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();

    $client = Client::factory()->create(['id_number' => '090000000031']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'closed_at' => now()]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000032', null)->save();

    $client->update(['id_number' => '090000000032']);

    expect($lead->notifications()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')->exists())->toBeFalse();
});
