<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

/**
 * Đính chính fix round 2, I1: trước đây test này khẳng định một dòng đã xoá mềm VẪN lên Đỏ, với lý
 * lẽ "RunConflictCheck cố tình đọc cả bên đã xoá mềm". Lý lẽ đó SAI kể từ phán quyết R14 đầy đủ
 * (round 2 sửa nốt nửa còn lại của round 1) — một bên đã GỠ (xoá mềm) không còn là dữ liệu đối
 * chiếu xung đột Ở BẤT KỲ ĐÂU, kể cả sau khi đồng bộ lại định danh của nó. Đồng bộ lại ảnh chụp
 * của một dòng đã xoá mềm giờ CHỈ còn là vệ sinh dữ liệu (đúng nếu dòng đó có ngày được khôi phục),
 * không còn liên quan gì tới kết quả RunConflictCheck.
 */
it('re-syncs soft-deleted party rows too, purely for data hygiene — a soft-deleted party never matches (I1/R14)', function () {
    $client = Client::factory()->create(['id_number' => '066011112222']);
    $matter = Matter::factory()->create();
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();
    $party->delete();

    $client->update(['id_number' => '066033334444']);

    expect(MatterParty::withTrashed()->find($party->id)->id_number_hash)
        ->toBe(Normalizer::idNumberHash('066033334444'));

    $result = app(RunConflictCheck::class)->handle(
        collect([ourNewClientParty(), opposingPartyWith(idNumber: '066033334444')])
    );

    expect($result->level)->toBe(ConflictLevel::Green);
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

/**
 * Fix round 4 (NB-1, phán quyết (b)) — không có dòng nào để đồng bộ KHÔNG có nghĩa là không có gì
 * để rà. Đúng lúc này một `OpenMatter`/`AddMatterParty` khác có thể đang giữ khoá `conflict-check`
 * và sắp lưu bên ĐẦU TIÊN của khách hàng này (lần đồng bộ ở đây chạy TRƯỚC khi dòng đó tồn tại, nên
 * thấy 0 dòng). Job chỉ mang `clientId` và tự đọc lại mọi thứ khi nó chạy — dưới CÙNG khoá đó, tức
 * là sau khi bên kia đã lưu — nên xếp nó cả khi 0 dòng là đủ để định danh mới được đối chiếu. Vẫn
 * không ghi dòng nhật ký đồng bộ nào (test ngay trên): không có gì đã được đồng bộ để mà kể lại.
 *
 * `Queue::fake()` được phép ở đây (khác test rollback bên dưới): câu hỏi duy nhất là "có xếp job
 * không", không dính gì tới ngữ nghĩa `afterCommit()` mà `QueueFake` bỏ qua.
 */
it('still queues the identity recheck when the client has no party rows yet', function () {
    Queue::fake();

    $client = Client::factory()->create(['id_number' => '088000111333']);

    $client->update(['id_number' => '088000999777']);

    Queue::assertPushed(
        RecheckClientIdentityConflicts::class,
        fn (RecheckClientIdentityConflicts $job): bool => $job->clientId === $client->getKey(),
    );
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
 * R13(e)/`conflict-04` (M6.5 Task 8, fix round 1 C2) — tái hiện ĐÚNG kịch bản của phát hiện gốc
 * (probe T7), không phải bản rút gọn của round 0: khách hàng A gõ sai CCCD lúc tiếp nhận; văn
 * phòng mở một vụ KHÁC (M2, luật sư phụ trách RIÊNG) kiện đúng người đó (nhập tay, không
 * `client_id` — "Ông D"), lúc đó không thấy gì trùng vì hash sai. Trợ lý sửa CCCD của A cho ĐÚNG:
 * dòng `matter_parties` của A (ở M1) giờ trùng hash với "Ông D" (ở M2) — C2 đòi CẢ HAI vụ việc
 * được rà lại và báo, không chỉ M1 (vụ việc của CHÍNH A).
 *
 * **Fix round 1 ruling: đi qua màn hình `EditClient` THẬT, với một trợ lý — không gọi thẳng
 * `$client->update()`.** Trợ lý có `client.manage` (SPEC §5) và là vai duy nhất sửa hồ sơ khách
 * hàng thường ngày; đi qua Livewire khẳng định lại rằng `Client::updated()` → `SyncClientPartyIdentities`
 * thật sự móc vào ĐÚNG đường màn hình dùng (`EditRecord::save()` gọi `$record->update()`), không
 * chỉ vào một lời gọi Eloquent trực tiếp mà một test có thể tự bịa ra.
 */
it('notifies the lead lawyer and every manager who can view the matter when a resync reveals a new conflict, for every matter the new identity now matches', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $client = Client::factory()->create(['id_number' => '090000000001', 'name' => 'Khách hàng A']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // M2: "Ông D", nhập tay, KHÔNG client_id — đúng hình dạng probe T7 gốc, và đúng lý do C2 tồn
    // tại: một bên không mang client_id vẫn phải được tìm thấy qua hash MỚI, không chỉ qua
    // client_id của A.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Ông D'])
        ->identify('090000000002', null)->save();

    // Trước khi sửa: hoàn toàn xanh, đúng như phát hiện gốc mô tả.
    $before = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);
    expect($before->level)->toBe(ConflictLevel::Green);

    $this->actingAs($assistant, 'web');

    $this->livewire(EditClient::class, ['record' => $client->getKey()])
        ->fillForm(['id_number' => '090000000002'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->fresh()->id_number)->toBe('090000000002');

    // Cả hai luật sư phụ trách đều nhận đúng MỘT thông báo (của vụ việc của chính họ); manager
    // được xem cả hai vụ (không restricted) nên nhận HAI — một cho mỗi vụ.
    expect($lead->notifications()->count())->toBe(1)
        ->and($otherLead->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(2);

    $matterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $matter->getMorphClass())->where('subject_id', $matter->id)->first();
    $otherMatterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->first();

    expect($matterAudit)->not->toBeNull()
        ->and($matterAudit->properties->get('client_id'))->toBe($client->id)
        ->and(collect($matterAudit->properties->get('notified_user_ids'))->sort()->values()->all())
        ->toBe(collect([$lead->id, $manager->id])->sort()->values()->all());

    expect($otherMatterAudit)->not->toBeNull()
        ->and($otherMatterAudit->properties->get('client_id'))->toBe($client->id)
        ->and(collect($otherMatterAudit->properties->get('notified_user_ids'))->sort()->values()->all())
        ->toBe(collect([$otherLead->id, $manager->id])->sort()->values()->all());

    // SPEC §10.5: không ghi số CCCD thô ở BẤT KỲ dòng nhật ký nào, kể cả hai dòng mới này.
    $everything = Activity::query()->get()->toJson();
    expect($everything)->not->toContain('090000000001')
        ->and($everything)->not->toContain('090000000002');
});

/**
 * R13(e)/I2 (fix round 1): `matterIdsMatchedByNewIdentity()` có HAI nhánh độc lập, hash và điện
 * thoại (`orWhereIn`) — mọi test khác ở trên đổi `id_number` nên chỉ chạm nhánh hash. Test này cố
 * tình để `id_number` luôn `null` và chỉ đổi SỐ ĐIỆN THOẠI, để chứng minh nhánh điện thoại tự nó
 * tìm ra vụ việc KHÁC — không "ăn theo" nhánh hash. Nếu nhánh điện thoại bị tắt (mutation probe),
 * test này phải đỏ dù test hash ở trên vẫn xanh.
 */
it('notifies a different matter matched only through the phone branch, not the hash branch', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();

    // `id_number` VẪN đổi (khác `null`) để nhánh hash của truy vấn thật sự chạy (`whereIn` có điều
    // kiện) — nếu để `id_number` là `null` như bản nháp đầu, `$hashes` rỗng và CẢ HAI nhánh bị bỏ
    // qua, khiến where() lồng thành RỖNG và Laravel bỏ qua toàn bộ điều kiện đó (đã xác nhận bằng
    // `toSql()`: còn mỗi `where deleted_at is null`) — tức là khớp với MỌI dòng, làm test "xanh giả"
    // dù nhánh điện thoại bị tắt. Số CCCD mới ở đây không trùng ai, nên nhánh hash không tự tìm ra
    // `$otherMatter` — CHỈ nhánh điện thoại mới tìm ra được nó.
    $client = Client::factory()->create(['id_number' => '090000000041', 'phone' => '0900000041']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // M2: bên nhập tay, không client_id, với số điện thoại MỚI của khách hàng A — chưa trùng gì
    // trước khi sửa, vì A hiện chưa có số đó.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn điện thoại'])
        ->identify(null, '0900000042')->save();

    $client->update(['id_number' => '090000000043', 'phone' => '0900000042']);

    expect($otherLead->notifications()->count())->toBe(1);

    $otherMatterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->first();

    expect($otherMatterAudit)->not->toBeNull()
        ->and($otherMatterAudit->properties->get('notified_user_ids'))->toContain($otherLead->id);
});

/**
 * Fix round 1, minor ruling — truy vấn rà lại (`matterIdsMatchedByNewIdentity()`) phải bỏ
 * `ClientPortalScope`, cùng lý do `RunConflictCheck` đã phải bỏ scope này ở MỌI truy vấn
 * `MatterParty` của nó (xem docblock lớp đó). `Client::updated()` chạy đồng bộ trong CHÍNH request
 * gọi `update()` — nếu request đó tình cờ có một khách hàng đang đăng nhập guard `client` (hai
 * panel dùng chung cookie phiên, xem docblock `ClientPortalScope`), scope sẽ TỰ kích hoạt trên MỌI
 * truy vấn `MatterParty` không tường minh bỏ nó, và vụ việc KHÁC (không phải của khách hàng đang
 * đăng nhập portal) sẽ biến mất khỏi kết quả rà — im lặng bỏ sót đúng thứ C2 tồn tại để sửa.
 *
 * **Fix round 3 — phát hiện thêm một điểm cùng lỗ hổng, ở CHÍNH `Client`.** Đi tìm test này (đo
 * lại sau khi lần rà trở thành job) lộ ra `recheckForQueuedClient()`'s `Client::withTrashed()->
 * find($clientId)` CŨNG cần bỏ `ClientPortalScope` — `Client` mang scope này qua trait
 * `RestrictedToClientPortal` (một điều bản round 1 bỏ sót vì grep chỉ tìm chữ "ClientPortalScope"
 * trong `Client.php`, không thấy nó đến từ trait). Không có bản sửa đó, `find()` âm thầm trả về
 * `null` cho một khách hàng có thật, và toàn bộ lần rà bỏ cuộc ngay từ bước đầu — test NÀY (đi qua
 * đúng đường `$client->update()` thật, không gọi thẳng job) là nơi phát hiện ra bug đó.
 */
it('bypasses the client portal scope on the resync recheck query too, not just on RunConflictCheck', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();

    $client = Client::factory()->create(['id_number' => '090000000051']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000052', null)->save();

    // Một khách hàng KHÁC (không liên quan) tình cờ đang đăng nhập guard `client` khi request này
    // chạy — đúng kịch bản hai panel dùng chung cookie phiên mô tả ở docblock ClientPortalScope.
    $unrelatedClientUser = ClientUser::factory()->create();
    $this->actingAs($unrelatedClientUser, 'client');

    $client->update(['id_number' => '090000000052']);

    expect($otherLead->notifications()->count())->toBe(1);

    $otherMatterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->first();

    expect($otherMatterAudit)->not->toBeNull()
        ->and($otherMatterAudit->properties->get('notified_user_ids'))->toContain($otherLead->id);
});

/** Vụ `restricted`: manager không nhận (R3) dù đang hoạt động, vì họ không Gate::view() được. */
it('does not notify a manager of a restricted matter when a resync reveals a new conflict, only the lead lawyer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $client = Client::factory()->create(['id_number' => '090000000011']);
    $matter = Matter::factory()->restricted()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // M2 KHÔNG restricted, và có luật sư phụ trách riêng: nó cũng bị rà lại (C2), và manager cũng
    // được báo cho M2 — điều đó không liên quan gì tới việc test này khẳng định (M1 hạn chế loại
    // manager), nên kiểm tra trên đúng dòng audit của M1, không đếm thông báo gộp cả hai vụ.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000012', null)->save();

    $client->update(['id_number' => '090000000012']);

    $matterAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $matter->getMorphClass())->where('subject_id', $matter->id)->first();

    expect($matterAudit)->not->toBeNull()
        ->and($matterAudit->properties->get('notified_user_ids'))->toBe([$lead->id])
        ->and($matterAudit->properties->get('notified_user_ids'))->not->toContain($manager->id);
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

/**
 * R8/fix round 1 C2: một vụ việc đã đóng (`closed_at` không rỗng) không TỰ nó được kiểm tra lại —
 * nhưng ĐÓNG vụ việc CỦA khách hàng A không được phép làm "im lặng tuyệt đối" một vụ việc KHÁC
 * (M2, đang mở) cũng vừa trùng hash mới của A. Bản round 0 chỉ dò `matterIds` từ CHÍNH các bên
 * `client_id = A`, nên khi vụ việc duy nhất đó đóng, không còn gì để rà — test cũ (đã thay bằng
 * test này) ghim đúng cái im lặng đó lại như một hành vi ĐÚNG. C2 dò theo hash/điện thoại MỚI
 * trên TOÀN BỘ `matter_parties` nên M2 được tìm thấy độc lập với việc M1 (của A) còn mở hay không.
 */
it('does not notify for the resynced client’s own closed matter, but still notifies for a different open matter the new identity now matches', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();

    $client = Client::factory()->create(['id_number' => '090000000031']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id, 'closed_at' => now()]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000032', null)->save();

    $client->update(['id_number' => '090000000032']);

    // M1 (của A) đã đóng: không nhận thông báo, không dòng audit cho nó.
    expect($lead->notifications()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')
            ->where('subject_type', $matter->getMorphClass())->where('subject_id', $matter->id)->exists())->toBeFalse();

    // M2 vẫn đang mở: PHẢI được rà lại và báo, dù M1 đã đóng.
    expect($otherLead->notifications()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')
            ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->exists())->toBeTrue();
});

/**
 * Fix round 3, N1/Minor 2 — lần rà giờ là job `RecheckClientIdentityConflicts`, dispatch
 * `afterCommit()` ở cuối `SyncClientPartyIdentities::handle()`. Đây là "bảo đảm transaction ngoài"
 * mà round 2 chỉ có được nhờ QUY ƯỚC (gọi tuần tự sau một `DB::transaction()` riêng của CHÍNH
 * Action — không biết gì về một transaction NGOÀI bao quanh cả lời gọi `$client->update()`), giờ
 * là CẤU TRÚC thật: bọc `$client->update()` trong một transaction riêng của TEST rồi CHỦ Ý rollback
 * — job không được phép chạy, dù dữ liệu để nó "có gì đó để báo" đã được dựng sẵn.
 *
 * **Vì sao khẳng định qua Mockery `->never()`, không qua một dòng CSDL (notification/audit).**
 * Với hàng đợi `sync` (mặc định bộ test), một dispatch KHÔNG `afterCommit()` vẫn chạy `handle()`
 * NGAY LẬP TỨC, cùng kết nối, cùng transaction đang mở — TỰ nó cũng bị cuốn theo khi transaction
 * đó rollback (đã kiểm chứng bằng tay: dòng notification/audit "biến mất" y hệt CẢ HAI trường hợp,
 * có `afterCommit()` hay không — SQL rollback xoá đều, không phân biệt được gì). `->never()` của
 * Mockery là một khẳng định Ở TẦNG PHP, không phải CSDL — không bị rollback "xoá" theo, nên đây là
 * tín hiệu DUY NHẤT thật sự phân biệt được "job không hề chạy" với "job chạy rồi bị cuốn theo".
 *
 * Không dùng `Queue::fake()` (đã kiểm chứng bằng tay: `QueueFake::push()` không đi qua
 * `Queue::enqueueUsing()`/`shouldDispatchAfterCommit()` của Laravel, nên nó GHI NHẬN job ngay lập
 * tức bất kể transaction có rollback hay không — một false negative khác cho đúng thứ test này
 * cần đo).
 */
it('does not dispatch the identity recheck job when the enclosing transaction rolls back', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $client = Client::factory()->create(['id_number' => '090000000061']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // $otherMatter khớp ĐỎ thật nếu job chạy được — chứng minh RunConflictCheck có việc thật để
    // làm nếu job THẬT SỰ chạy tới đó, không phải một mock trơ trọi không có gì để chạm vào.
    $otherMatter = Matter::factory()->create();
    $otherClient = Client::factory()->create(['id_number' => '090000000062']);
    MatterParty::factory()->for($otherMatter)->ourClient($otherClient, PartyRole::Defendant)->create();

    $this->mock(RunConflictCheck::class)->shouldReceive('handle')->never();

    try {
        DB::transaction(function () use ($client): void {
            $client->update(['id_number' => '090000000062']);

            throw new RuntimeException('Huỷ có chủ đích — job không được phép chạy sau việc này.');
        });
    } catch (RuntimeException) {
        // Mong đợi — xem để lộ rõ ý định thay vì một catch im lặng không giải thích.
    }

    expect($client->fresh()->id_number)->not->toBe('090000000062');
});

/**
 * Fix round 3, N1 — mặt còn lại của cùng cơ chế: khi transaction đồng bộ định danh THẬT SỰ commit
 * (không phải nested/savepoint dưới `RefreshDatabase`), job phải THẬT SỰ chạy qua đúng đường
 * dispatch (không gọi thẳng `handle()`/`recheckForQueuedClient()` như các test khác của N1), và
 * phải báo đúng vụ việc bị ảnh hưởng — "the lock is later free → the recheck runs and notifies"
 * của phán quyết N1, đo ở ĐÚNG tầng dispatch thay vì tầng job (job's lock/retry/failed() có tệp
 * riêng, `RecheckClientIdentityConflictsTest.php`).
 *
 * **Không cần `DB::commit()` (đính chính fix round 4, minor).** Bản round 3 gọi `DB::commit()` để
 * "thoát" transaction bọc của `RefreshDatabase`, với tiền đề `afterCommit()` chỉ chạy callback khi
 * level về 0 — tiền đề đó SAI dưới bộ test. `RefreshDatabase` thay `db.transactions` bằng
 * `Illuminate\Foundation\Testing\DatabaseTransactionsManager`, lớp này (1) bỏ qua transaction bọc
 * của test khi chọn nơi gắn callback (`callbackApplicableTransactions()` bỏ đúng số kết nối đang
 * bọc), nên một dispatch ở level 1 chạy NGAY, và (2) chạy callback khi một transaction lồng commit
 * về level 1 (`afterCommitCallbacksShouldBeExecuted()` so với 1, không phải 0). Ở đây transaction
 * đồng bộ định danh của `SyncClientPartyIdentities` commit về level 1, lời dispatch sau đó thấy
 * không còn transaction nào "của ứng dụng" đang mở — đúng hình dạng của level 0 ngoài đời — và
 * job `sync` chạy tại chỗ. Bỏ `DB::commit()` cũng bỏ luôn hệ quả phụ của nó
 * (`RefreshDatabaseState::$migrated` bị đặt lại, buộc bài test kế tiếp `migrate:fresh`).
 */
it('runs the identity recheck job through the real dispatch path once the transaction truly commits', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '090000000071']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    $otherClient = Client::factory()->create(['id_number' => '090000000072']);
    MatterParty::factory()->for($otherMatter)->ourClient($otherClient, PartyRole::Defendant)->create();

    $client->update(['id_number' => '090000000072']);

    $conflictAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)->first();

    expect($conflictAudit)->not->toBeNull()
        ->and($otherLead->notifications()->count())->toBe(1);
});
