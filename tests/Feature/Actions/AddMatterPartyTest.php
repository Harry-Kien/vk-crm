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

it('lets a manager override the red block with a reason, saves the party, and records the reason in the activity log', function () {
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

    $party = app(AddMatterParty::class)->handle(
        $matter,
        $manager,
        [
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ],
        'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý thêm bên này.',
    );

    expect($party->exists)->toBeTrue()
        ->and($matter->parties()->where('name', 'Nguyễn Văn Hùng (bị đơn)')->exists())->toBeTrue();

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

    $party = app(AddMatterParty::class)->handle(
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

    expect($party->exists)->toBeTrue();

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
    $party = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        ['role' => PartyRole::Defendant, 'name' => 'Bên hoàn toàn mới', 'id_number' => '000000000001'],
    );

    expect($party->exists)->toBeTrue();
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

    $party = app(AddMatterParty::class)->handle(
        $matter,
        $lawyer,
        ['role' => PartyRole::Defendant, 'name' => 'Người Chỉ Có Tên Không Định Danh'],
        acknowledged: ConflictLevel::Green,
    );

    expect($party->exists)->toBeTrue();

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
