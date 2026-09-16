<?php

use App\Actions\AddMatterParty;
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

    app(AddMatterParty::class)->handle(
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
        ->and($checked->properties->get('actor_explicit'))->toBeTrue();
});
