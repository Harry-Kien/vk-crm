<?php

use App\Actions\AddMatterParty;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Exceptions\OurClientPartyNeedsClient;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Mirror của OpenMatterTest, cho nhánh thứ hai của cùng quy tắc SPEC §6.10 mà OpenMatter thực
 * hiện cho lúc mở vụ việc: "mỗi lần thêm một bên mới vào vụ việc đang chạy". Fix round 1 (review
 * Task 6), finding 1: trước bản sửa này, PartiesRelationManager lưu bên mới TRƯỚC khi biết kết
 * quả kiểm tra — một bên gây mức đỏ vẫn được lưu, không chặn, không ghi đè, không xác nhận.
 */
it('blocks a red conflict from saving a new party, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $partyCountBefore = MatterParty::count();

    expect(fn () => app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        [
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ],
    ))->toThrow(ConflictBlocked::class);

    expect(MatterParty::count())->toBe($partyCountBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('level'))->toBe('red');

    expect(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();
});

/**
 * Fix round 2 finding A: AddMatterParty::handle() phải trả lại KẾT QUẢ kiểm tra cùng với bên đã
 * lưu, không chỉ MatterParty trần — nếu không, đường THÀNH CÔNG (kể cả sau khi ghi đè mức đỏ) mất
 * hẳn cách hiển thị bên nào đã gây xung đột. Assert trực tiếp trên $addition->result ở đây, thay
 * vì chỉ suy luận qua activity log, để thay đổi kiểu trả về được test bắt được nếu ai đó gỡ lại.
 */
it('lets a manager override the red block with a reason, saves the party, and returns the conflicting matter in the result', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $manager,
        [
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ],
        'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý thêm bên này.',
    );

    expect($addition->party->exists)->toBeTrue()
        ->and($matter->parties()->where('name', 'Nguyễn Văn Hùng (bị đơn)')->exists())->toBeTrue()
        ->and($addition->result->level)->toBe(ConflictLevel::Red)
        ->and($addition->result->matches->first()->matterCode)->toBe($otherMatter->code);

    $activity = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('conflict_level'))->toBe('red')
        ->and($activity->properties->get('conflict_overridden'))->toBeTrue()
        ->and($activity->properties->get('override_reason'))->toBe('Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý thêm bên này.')
        ->and($activity->causer?->is($manager))->toBeTrue();
});

it('still blocks a lawyer who supplies an override reason: only manager/admin may override', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $existingClient = Client::factory()->create(['id_number' => '079012345678']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();
    $matter->parties()->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Khách hàng hiện hữu']);

    $partyCountBefore = MatterParty::count();

    expect(fn () => app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        ['role' => PartyRole::Defendant, 'name' => 'Bị đơn trùng', 'id_number' => '079012345678'],
        'Tôi là luật sư, tôi cho phép thêm bên này.',
    ))->toThrow(ConflictBlocked::class);

    expect(MatterParty::count())->toBe($partyCountBefore);
});

it('requires a non-empty override reason: a manager with a blank reason is still blocked', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);

    $existingClient = Client::factory()->create(['id_number' => '079012345678']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();
    $matter->parties()->create(['role' => PartyRole::Plaintiff, 'is_our_client' => true, 'name' => 'Khách hàng hiện hữu']);

    expect(fn () => app(AddMatterParty::class)->handle(
        $matter,
        $manager,
        ['role' => PartyRole::Defendant, 'name' => 'Bị đơn trùng', 'id_number' => '079012345678'],
        '   ',
    ))->toThrow(ConflictBlocked::class);

    expect($matter->parties()->where('name', 'Bị đơn trùng')->exists())->toBeFalse();
});

it('throws ConflictAcknowledgementRequired for a yellow result without acknowledgement, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $partyCountBefore = MatterParty::count();

    $args = [
        $matter,
        $lawyer,
        [
            // Tên trùng sau chuẩn hoá nhưng số căn cước/điện thoại khác hẳn — chỉ lên vàng.
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ],
    ];

    expect(fn () => app(AddMatterParty::class)->handle(...$args))->toThrow(ConflictAcknowledgementRequired::class);

    expect(MatterParty::count())->toBe($partyCountBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity->properties->get('level'))->toBe('yellow');
    expect(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();
});

it('saves a yellow result once the caller acknowledges it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        [
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ],
        acknowledged: ConflictLevel::Yellow,
    );

    expect($addition->party->exists)->toBeTrue()
        ->and($addition->result->level)->toBe(ConflictLevel::Yellow);

    $activity = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();
    expect($activity->properties->get('conflict_level'))->toBe('yellow');
});

it('needs no acknowledgement for a green result', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    // id_number cho đủ định danh (khác OpenMatterTest — Action này không tự dựng own-client party
    // từ Client như OpenMatter, nên một bên KHÔNG có id_number/phone luôn là "thiếu định danh"
    // (hasIncompleteParties()), đòi xác nhận dù không trùng ai — đúng thiết kế RunConflictCheck,
    // không phải điều muốn kiểm tra ở đây).
    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        ['role' => PartyRole::Defendant, 'name' => 'Bên hoàn toàn mới', 'id_number' => '000000000001'],
    );

    expect($addition->party->exists)->toBeTrue()
        ->and($addition->result->level)->toBe(ConflictLevel::Green)
        ->and($addition->result->requiresAcknowledgement())->toBeFalse();
});

it('throws ConflictAcknowledgementRequired for a green result with an incomplete party, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $partyCountBefore = MatterParty::count();

    // Bên này chỉ có tên, không id_number lẫn phone — RunConflictCheck chỉ so khớp được theo tên
    // cho bên này. Tên không trùng ai nên kết quả tổng thể vẫn XANH, nhưng
    // ConflictCheckResult::hasIncompleteParties() là true nên requiresAcknowledgement() cũng true.
    $args = [$matter, $lawyer, ['role' => PartyRole::Defendant, 'name' => 'Người Chỉ Có Tên Không Định Danh']];

    expect(fn () => app(AddMatterParty::class)->handle(...$args))->toThrow(ConflictAcknowledgementRequired::class);

    expect(MatterParty::count())->toBe($partyCountBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity->properties->get('level'))->toBe('green')
        ->and($activity->properties->get('incomplete_parties'))->toBe(['Người Chỉ Có Tên Không Định Danh']);
});

it('saves a green result with an incomplete party once the caller acknowledges it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        ['role' => PartyRole::Defendant, 'name' => 'Người Chỉ Có Tên Không Định Danh'],
        acknowledged: ConflictLevel::Green,
    );

    expect($addition->party->exists)->toBeTrue()
        ->and($addition->result->hasIncompleteParties())->toBeTrue();

    $activity = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();
    expect($activity->properties->get('conflict_level'))->toBe('green')
        ->and($activity->properties->get('incomplete_conflict_parties'))->toBe(['Người Chỉ Có Tên Không Định Danh']);
});

it('rejects a party from someone without matter.update, regardless of the owning matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $matter = Matter::factory()->create();

    expect(fn () => app(AddMatterParty::class)->handle($matter, $accountant, ['role' => PartyRole::Defendant, 'name' => 'Bên mới']))
        ->toThrow(AuthorizationException::class);

    expect($matter->parties()->where('name', 'Bên mới')->exists())->toBeFalse();
});

/**
 * Fix round 1 finding 1/5: giai đoạn kiểm tra và giai đoạn lưu là hai transaction RIÊNG — nếu
 * RunConflictCheck ném lỗi bất ngờ, giai đoạn kiểm tra tự rollback và KHÔNG có MatterParty nào
 * được lưu (khác lỗ hổng cũ: lưu trước, kiểm tra sau, nên một lỗi giữa chừng để lại một bên đã
 * lưu mà không có dòng conflict_check_run tương ứng).
 */
it('leaves nothing saved when the check phase fails unexpectedly', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $partyCountBefore = MatterParty::count();

    // role không hợp lệ khiến PartyRole::from() ném \ValueError NGAY trong giai đoạn kiểm tra
    // (buildParty() chạy trước RunConflictCheck), trước khi bất kỳ điều gì được lưu.
    expect(fn () => app(AddMatterParty::class)->handle($matter, $lawyer, ['role' => 'not-a-real-role', 'name' => 'X']))
        ->toThrow(ValueError::class);

    expect(MatterParty::count())->toBe($partyCountBefore);
});

/**
 * Fix round 2 finding C: is_our_client=true VỚI client_id phải lấy định danh từ hồ sơ Client thật
 * (giống OpenMatter::buildOwnClientParty()), KHÔNG tin id_number/phone do form gửi lên cho bên
 * này — nếu không, một bên "là khách hàng của văn phòng" có thể mang id_number_hash SAI hồ sơ gốc
 * (gõ nhầm, hoặc cố tình), và một hash sai là chính xác cách một lần kiểm tra xung đột trong tương
 * lai bỏ sót bên này.
 */
it('trusts the Client record for identity when is_our_client is true, ignoring mismatched form values', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $client = Client::factory()->create(['id_number' => '055566677788', 'phone' => '0911222333']);

    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        [
            'role' => PartyRole::Plaintiff,
            'is_our_client' => true,
            'client_id' => $client->id,
            'name' => $client->name,
            // Dữ liệu form khác hẳn hồ sơ Client thật — phải bị Action bỏ qua hoàn toàn.
            'id_number' => '000000000000',
            'phone' => '0999999999',
        ],
    );

    expect($addition->party->id_number_hash)->toBe(Normalizer::idNumberHash('055566677788'))
        ->and($addition->party->phone_normalized)->toBe(Normalizer::phone('0911222333'))
        ->and($addition->party->id_number_hash)->not->toBe(Normalizer::idNumberHash('000000000000'));
});

/**
 * Fix M3 (review toàn nhánh, finding 2): dòng `conflict_check_run` và dòng `matter_party_added`
 * mô tả CÙNG một thao tác, nên phải chỉ về CÙNG một người. Trước bản sửa này chỉ dòng thứ hai
 * nhận `$actor` tường minh, dòng thứ nhất rơi về `auth()` ambient — hai dòng bằng chứng của cùng
 * một lần thêm bên có thể ghi hai người khác nhau (job, lệnh console, hoặc đơn giản là một phiên
 * không thuộc về actor). Test cho phiên và actor là hai người KHÁC NHAU để phân biệt được.
 */
it('attributes the conflict-check row to the actor passed in, not to the user in the session', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $someoneElse = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($someoneElse, 'web');

    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $addition = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        [
            'role' => PartyRole::Defendant,
            'name' => 'Bị đơn không trùng ai',
            'id_number' => '012345678901',
            'phone' => '0912345678',
        ],
    );

    $checked = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    $added = Activity::query()->where('event', 'matter_party_added')->latest('id')->first();

    expect($checked->causer?->is($lawyer))->toBeTrue()
        ->and($added->causer?->is($lawyer))->toBeTrue()
        ->and($checked->properties->get('actor_explicit'))->toBeTrue()
        // Cùng một lập luận, áp cho CỘT chứ không chỉ cho nhật ký: `MatterParty` dùng
        // `HasBlameable`, nên nếu Action không gán tường minh thì `created_by` được điền từ
        // `auth('web')` ambient — ở đây là $someoneElse, một người không hề thêm bên nào. Dòng
        // `matter_parties` là một phần hồ sơ pháp lý, không phải nhật ký phụ trợ.
        ->and($addition->party->fresh()->created_by)->toBe($lawyer->id)
        ->and($addition->party->fresh()->updated_by)->toBe($lawyer->id);
});

/**
 * I-2 (Important, fix round 4). `is_our_client = true` KHÔNG kèm `client_id` lọt qua nhánh trả sớm
 * của `BuildsMatterParties` và rơi về định danh gõ tay — đúng sự mù im lặng mà cả trait tồn tại để
 * ngăn: số căn cước gõ tay băm ra một hash mà hồ sơ `Client` thật không bao giờ băm ra, nên lần
 * kiểm tra xung đột SAU sẽ không nhìn thấy bên này. Tệ hơn, `SyncClientPartyIdentities` lọc theo
 * `client_id`, nên dòng này nằm ngoài vòng đồng bộ vĩnh viễn: nó không bao giờ tự sửa lại.
 *
 * Luật thuộc về trait chứ không chỉ về form: một seeder, một job hay một lệnh console cũng không
 * được phép ghi ra một dòng tuyên bố "đây là khách hàng của văn phòng" mà không chỉ ra được hồ sơ
 * nào. Xem docblock `BuildsMatterParties`.
 */
it('refuses a party that claims to be our client without naming a client record', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    expect(fn () => app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        [
            'role' => PartyRole::Related->value,
            'is_our_client' => true,
            'client_id' => null,
            'name' => 'Tên gõ tay',
            'id_number' => '079012345678',
        ],
    ))->toThrow(OurClientPartyNeedsClient::class);

    expect($matter->parties()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();
});

/**
 * Fix round 1, minor ruling: mirror của test cùng tên ở `OpenMatterTest.php` — `LockTimeoutException`
 * (khoá `conflict-check` không lấy được sau 10 giây) phải thành `ConflictCheckBusy`, không phải
 * một trang lỗi 500. Chờ thật ~10 giây (thời gian chờ của Action).
 */
it('turns a busy conflict-check lock into a Vietnamese refusal, not a 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $lock = Cache::store('database')->lock('conflict-check', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(AddMatterParty::class)->handle($matter, $lawyer, [
            'role' => PartyRole::Related->value,
            'name' => 'Bên bất kỳ',
        ]))->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));

        expect($matter->parties()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

/**
 * Fix round 3, ruling — cùng lỗ hổng đua tranh hash cũ đã sửa ở `OpenMatter` bước 5, áp cho
 * `AddMatterParty` bước 4 (xem docblock `OpenMatter::refreshOwnClientIdentitiesUnderLock()` cho lý
 * lẽ đầy đủ, không lặp lại ở đây). `$party` (khách hàng của văn phòng) được dựng ở bước 2, khoá
 * Client release ngay khi transaction đó commit — nếu một `EditClient::save()` khác chen vào trước
 * khi bước 4 lưu, bên vừa thêm phải mang HASH MỚI, không phải ảnh chụp cũ từ bước 2.
 *
 * **Seam mô phỏng đua tranh: sự kiện `Activity::created` cho đúng dòng `conflict_check_run`.**
 * Dòng đó được `RunConflictCheck::handle()` ghi ở CUỐI bước 2 — đúng lúc `$party` đã được dựng
 * (với ảnh chụp CŨ) nhưng bước 4 (lưu) còn chưa chạy. Không cần một hook giả lập riêng. Seam chỉ
 * bắn MỘT lần: lần rà lại (fix round 4) cũng ghi `conflict_check_run` khi hàng đợi chạy, và không
 * được "sửa xen ngang" thêm lần nữa ở đó.
 *
 * **Fix round 4 (NB-1)** — cùng lý lẽ với test cùng tên ở `OpenMatterTest.php` (bước 5): lưu đúng
 * hash chưa đủ, vì kết quả kiểm tra ở bước 2 tính trên định danh CŨ. Định danh mới trùng "Ông Z" mà
 * văn phòng đang kiện ở một vụ khác đang mở; hai đường độc lập — (b) `SyncClientPartyIdentities`
 * của lần sửa (0 dòng lúc đó) và (a) khối làm mới ở bước 4 — cùng xếp một lần rà, và khi hàng đợi
 * chạy, vụ kia phải bị gắn cờ. Hàng đợi `database` thay cho `sync` vì mọi dispatch ở đây xảy ra
 * bên trong khoá `conflict-check` mà Action đang giữ (xem docblock
 * `openMatterQueuedRecheckClientIds()` ở `OpenMatterTest.php`).
 */
it('re-reads the client identity under lock at step 4, so a concurrent identity edit between check and save is never lost', function () {
    config(['queue.default' => 'database']);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '071000000001']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant]);

    // Vụ KHÁC đang mở: văn phòng đại diện khách hàng P kiện "Ông Z", người mang đúng số CCCD MỚI mà
    // lần sửa xen ngang sắp gán cho khách hàng vừa được thêm vào vụ này.
    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)
        ->ourClient(Client::factory()->create(['id_number' => '071000000009']), PartyRole::Plaintiff)->create();
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Ông Z'])
        ->identify('071000000002', null)->save();

    $raced = false;

    Activity::created(function (Activity $activity) use ($client, &$raced): void {
        if ($raced || $activity->event !== 'conflict_check_run') {
            return;
        }

        $raced = true;

        // Giả lập một EditClient::save() xen ngang NGAY GIỮA lúc kiểm tra xong (bước 2) và lúc bên
        // được lưu (bước 4).
        $client->update(['id_number' => '071000000002']);
    });

    $addition = app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $client->getKey(),
        'name' => $client->name,
    ]);

    $savedParty = $matter->parties()->where('client_id', $client->getKey())->first();

    expect($savedParty->id_number_hash)->toBe(Normalizer::idNumberHash('071000000002'))
        ->and($addition->result->level)->toBe(ConflictLevel::Green)
        ->and(addMatterPartyQueuedRecheckClientIds())->toBe([$client->getKey(), $client->getKey()]);

    addMatterPartyDrainDatabaseQueue();

    $otherAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)
        ->latest('id')->first();

    expect($otherAudit)->not->toBeNull()
        ->and($otherAudit->properties->get('level'))->toBe(ConflictLevel::Red->value)
        ->and($otherAudit->properties->get('client_id'))->toBe($client->getKey())
        ->and($otherAudit->properties->get('notified_user_ids'))->toContain($otherLead->id)
        ->and($otherLead->notifications()->exists())->toBeTrue();
});

/**
 * Fix round 4 (NB-1, phán quyết (a)) — mirror của test cùng ý ở `OpenMatterTest.php`: khối làm mới
 * ở bước 4 phải TỰ xếp lần rà, không dựa vào `SyncClientPartyIdentities`. Lần sửa xen ngang đi qua
 * query builder nên không kích hoạt `Client::updated()` — chỉ bước 4 còn biết định danh đã đổi.
 */
it('queues the identity recheck itself when the identity changed between check and save through a write that fired no model event', function () {
    config(['queue.default' => 'database']);

    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $otherLead = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['id_number' => '071000000011', 'phone' => '0907100011']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant]);

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $otherLead->id]);
    MatterParty::factory()->for($otherMatter)->create(['role' => PartyRole::Defendant, 'name' => 'Bà T'])
        ->identify(null, '0907100012')->save();

    $raced = false;

    Activity::created(function (Activity $activity) use ($client, &$raced): void {
        if ($raced || $activity->event !== 'conflict_check_run') {
            return;
        }

        $raced = true;

        Client::query()->whereKey($client->getKey())->update(['phone' => '0907100012']);
    });

    $addition = app(AddMatterParty::class)->handle($matter, $lawyer, [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $client->getKey(),
        'name' => $client->name,
    ]);

    expect($addition->party->phone_normalized)->toBe(Normalizer::phone('0907100012'))
        ->and($addition->result->level)->toBe(ConflictLevel::Green)
        ->and(addMatterPartyQueuedRecheckClientIds())->toBe([$client->getKey()]);

    addMatterPartyDrainDatabaseQueue();

    $otherAudit = Activity::query()->where('event', 'client_identity_conflict_detected')
        ->where('subject_type', $otherMatter->getMorphClass())->where('subject_id', $otherMatter->id)
        ->first();

    expect($otherAudit)->not->toBeNull()
        ->and($otherAudit->properties->get('notified_user_ids'))->toContain($otherLead->id)
        ->and($otherLead->notifications()->count())->toBe(1);
});

/**
 * Bản riêng của tệp này (không gọi hàm cùng việc ở `OpenMatterTest.php`): ParaTest chia việc theo
 * tệp trên các tiến trình riêng, nên hàm toàn cục của tệp khác không tồn tại ở đây.
 *
 * @return array<int, int> `clientId` của từng job rà lại đang xếp hàng, theo thứ tự xếp.
 */
function addMatterPartyQueuedRecheckClientIds(): array
{
    return DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(fn (string $payload) => unserialize(json_decode($payload, true)['data']['command']))
        ->filter(fn (object $job): bool => $job instanceof RecheckClientIdentityConflicts)
        ->map(fn (RecheckClientIdentityConflicts $job): int => $job->clientId)
        ->values()
        ->all();
}

/** Bản riêng của `openMatterDrainDatabaseQueue()` (`OpenMatterTest.php`) — xem docblock ở đó. */
function addMatterPartyDrainDatabaseQueue(): void
{
    for ($guard = 0; $guard < 50; $guard++) {
        if (! DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->exists()) {
            break;
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
}
