<?php

use App\Actions\RunConflictCheck;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
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
 * Fix round 2, Ruling — `recheckAffectedOpenMattersSafely()` chạy SAU KHI transaction đồng bộ định
 * danh đã trả về (không còn nằm trong cùng transaction), và bọc toàn bộ trong try/catch + report().
 * Test này giả lập một lỗi BẤT KỲ ngay TRONG lần rà (ở đây: `RunConflictCheck` ném ra) — hồ sơ
 * khách hàng vừa sửa PHẢI vẫn còn nguyên giá trị mới, không bị cuốn theo.
 */
it('never rolls back the client identity correction when the post-commit recheck throws', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '090000000041']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // Một vụ việc KHÁC, đang mở, sẽ khớp hash mới — bắt buộc để matterIdsMatchedByNewIdentity()
    // không rỗng và recheckAffectedOpenMattersSafely() thật sự gọi tới RunConflictCheck (giả lập
    // ném lỗi bên dưới mới có gì để chạm tới).
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bị đơn khác'])
        ->identify('090000000042', null)->save();

    $this->mock(RunConflictCheck::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('Sự cố giả lập — kiểm tra việc sửa định danh không bị cuốn theo.'));

    // Không được phép ném ra ngoài — người gọi thật (EditClient::save()) không có gì để bắt riêng
    // cho lỗi này, và không nên phải có.
    $client->update(['id_number' => '090000000042']);

    expect($client->fresh()->id_number)->toBe('090000000042')
        ->and(MatterParty::where('matter_id', $matter->id)->first()->id_number_hash)
        ->toBe(Normalizer::idNumberHash('090000000042'))
        // Lần rà thất bại trước khi kịp ghi — không có dòng audit "đã phát hiện xung đột" nào,
        // không thông báo nào gửi ra, đúng như một lần rà chưa từng chạy xong.
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')->exists())->toBeFalse()
        ->and($lead->notifications()->count())->toBe(0);
});

/**
 * Fix round 2, Ruling — `recheckAffectedOpenMattersSafely()` phải THẬT SỰ tranh chính khoá
 * `conflict-check` mà `OpenMatter`/`AddMatterParty` dùng, không chỉ gọi `Cache::lock()` cho có.
 * Giữ khoá đó bằng tay (đúng khuôn "turns a busy conflict-check lock..." của `OpenMatterTest.php`)
 * rồi sửa hồ sơ khách hàng: lần rà phải KHÔNG lấy được khoá trong 10 giây, `LockTimeoutException`
 * bị bắt+report()+bỏ qua (test "never rolls back..." ở trên đã chứng minh phần bắt lỗi) — nghĩa là
 * không có thông báo/dòng audit "đã phát hiện xung đột" nào, dù $otherMatter đáng lẽ khớp Đỏ. Hồ
 * sơ khách hàng vẫn phải lưu thành công.
 */
it('actually contends for the conflict-check lock, not just calls it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '090000000051']);
    $matter = Matter::factory()->create();
    MatterParty::factory()->for($matter)->ourClient($client, PartyRole::Plaintiff)->create();

    // $otherMatter khớp ĐỎ thật (không phải mock) nếu lần rà chạy được — chứng minh việc không có
    // thông báo dưới đây là do KHOÁ, không phải do không có gì để báo.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    $otherClient = Client::factory()->create(['id_number' => '090000000052']);
    MatterParty::factory()->for($otherMatter)->ourClient($otherClient, PartyRole::Defendant)->create();

    $lock = Cache::store('database')->lock('conflict-check', 30);
    expect($lock->get())->toBeTrue();

    try {
        $client->update(['id_number' => '090000000052']);
    } finally {
        $lock->release();
    }

    expect($client->fresh()->id_number)->toBe('090000000052')
        ->and(Activity::query()->where('event', 'client_identity_conflict_detected')->exists())->toBeFalse()
        ->and($otherLead->notifications()->count())->toBe(0);
});
