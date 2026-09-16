<?php

use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Loại vụ việc kèm giai đoạn (bắt buộc để Matter::creating() không ném StageNotConfigured) và
 * một danh mục hồ sơ đang hoạt động, để test xác nhận OpenMatter có sao chép danh mục.
 */
function matterTypeWithTemplate(int $itemCount = 2): MatterType
{
    $type = MatterType::factory()->withStages()->create();
    ChecklistTemplate::factory()->withItems($itemCount)->for($type, 'matterType')->create();

    return $type;
}

/** `client_role` mặc định plaintiff (kịch bản phổ biến nhất) — bắt buộc phải có, không có mặc định trong Action (fix round 1). */
function baseAttributes(Client $client, User $leadLawyer, MatterType $type, array $overrides = []): array
{
    return [...[
        'client_id' => $client->id,
        'client_role' => PartyRole::Plaintiff,
        'matter_type_id' => $type->id,
        'title' => 'Tranh chấp hợp đồng thuê nhà',
        'description_internal' => 'Ghi chú nội bộ',
        'summary_for_client' => 'Tóm tắt gửi khách hàng',
        'lead_lawyer_id' => $leadLawyer->id,
        'is_published_to_portal' => false,
    ], ...$overrides];
}

it('blocks at red when a defendant shares an id number with an existing client, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matterCountBefore = Matter::count();
    $partyCountBefore = MatterParty::count();
    $checklistCountBefore = MatterChecklistItem::count();
    $sequenceKey = 'matter:'.now()->format('Y').':'.$type->code;
    $sequenceBefore = DB::table('code_sequences')->where('key', $sequenceKey)->value('last_number');

    expect(fn () => app(OpenMatter::class)->handle(
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
    ))->toThrow(ConflictBlocked::class);

    expect(Matter::count())->toBe($matterCountBefore)
        ->and(MatterParty::count())->toBe($partyCountBefore)
        ->and(MatterChecklistItem::count())->toBe($checklistCountBefore)
        ->and(DB::table('code_sequences')->where('key', $sequenceKey)->value('last_number'))->toBe($sequenceBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('level'))->toBe('red');

    expect(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

it('lets a manager override the red block with a reason, saves the matter, and records the reason in the activity log', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle(
        baseAttributes($newClient, $manager, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
        'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý mở vụ việc.',
    );

    expect($matter->exists)->toBeTrue()
        ->and($matter->client_id)->toBe($newClient->id)
        ->and($matter->parties()->count())->toBe(2);

    $activity = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('conflict_level'))->toBe('red')
        ->and($activity->properties->get('conflict_overridden'))->toBeTrue()
        ->and($activity->properties->get('override_reason'))->toBe('Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý mở vụ việc.')
        ->and($activity->causer?->is($manager))->toBeTrue();
});

it('still blocks a lawyer who supplies an override reason: only manager/admin may override', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $existingClient = Client::factory()->create(['id_number' => '079012345678']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matterCountBefore = Matter::count();

    expect(fn () => app(OpenMatter::class)->handle(
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Bị đơn trùng',
            'id_number' => '079012345678',
        ]],
        'Tôi là luật sư, tôi cho phép mở vụ việc này.',
    ))->toThrow(ConflictBlocked::class);

    expect(Matter::count())->toBe($matterCountBefore);
});

it('requires a non-empty override reason: a manager with a blank reason is still blocked', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $existingClient = Client::factory()->create(['id_number' => '079012345678']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    expect(fn () => app(OpenMatter::class)->handle(
        baseAttributes($newClient, $manager, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Bị đơn trùng',
            'id_number' => '079012345678',
        ]],
        '   ',
    ))->toThrow(ConflictBlocked::class);

    expect(Matter::count())->toBe(1); // chỉ $otherMatter, không có vụ nào mới được tạo.
});

it('throws ConflictAcknowledgementRequired for a yellow result without acknowledgement, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();
    $matterCountBefore = Matter::count();

    $args = [
        baseAttributes($newClient, $lawyer, $type),
        [[
            // Tên trùng sau chuẩn hoá nhưng số căn cước/điện thoại khác hẳn — chỉ lên vàng.
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ]],
    ];

    expect(fn () => app(OpenMatter::class)->handle(...$args))->toThrow(ConflictAcknowledgementRequired::class);

    expect(Matter::count())->toBe($matterCountBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity->properties->get('level'))->toBe('yellow');
    expect(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

it('saves a yellow result once the caller acknowledges it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle(
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ]],
        acknowledged: ConflictLevel::Yellow,
    );

    expect($matter->exists)->toBeTrue();

    $matterOpened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($matterOpened->properties->get('conflict_level'))->toBe('yellow');
});

it('needs no acknowledgement for a green result', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle(baseAttributes($newClient, $lawyer, $type), []);

    expect($matter->exists)->toBeTrue();
});

it('throws ConflictAcknowledgementRequired for a green result with an incomplete party, and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();
    $matterCountBefore = Matter::count();

    // Bên này chỉ có tên, không id_number lẫn phone — RunConflictCheck chỉ so khớp được theo
    // tên cho bên này (SPEC §6.10 bước 2 "cần người xem xét"). Tên không trùng ai nên kết quả
    // tổng thể vẫn XANH (matches rỗng), nhưng ConflictCheckResult::hasIncompleteParties() là
    // true, nên requiresAcknowledgement() cũng true dù level là Green.
    $args = [
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Người Chỉ Có Tên Không Định Danh',
        ]],
    ];

    expect(fn () => app(OpenMatter::class)->handle(...$args))->toThrow(ConflictAcknowledgementRequired::class);

    expect(Matter::count())->toBe($matterCountBefore);

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity->properties->get('level'))->toBe('green')
        ->and($activity->properties->get('incomplete_parties'))->toBe(['Người Chỉ Có Tên Không Định Danh']);
    expect(Activity::query()->where('event', 'matter_opened')->exists())->toBeFalse();
});

it('saves a green result with an incomplete party once the caller acknowledges it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle(
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Người Chỉ Có Tên Không Định Danh',
        ]],
        acknowledged: ConflictLevel::Green,
    );

    expect($matter->exists)->toBeTrue();

    $matterOpened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($matterOpened->properties->get('conflict_level'))->toBe('green')
        ->and($matterOpened->properties->get('incomplete_conflict_parties'))->toBe(['Người Chỉ Có Tên Không Định Danh']);
});

it('flags the repeat-client case as yellow (same client opening a second matter matches their own first matter), requiring acknowledgement', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $client = Client::factory()->create(['id_number' => '099988877766']);
    $type = matterTypeWithTemplate();

    // Vụ việc đầu tiên của khách hàng: hoàn toàn xanh, không có bên nào trùng ở đâu cả.
    $firstMatter = app(OpenMatter::class)->handle(baseAttributes($client, $lawyer, $type), []);
    expect($firstMatter->exists)->toBeTrue();

    // Vụ việc thứ hai của CHÍNH khách hàng đó: own-client party mới trùng id_number_hash với
    // own-client party của vụ thứ nhất (chính khách hàng này), cùng vai plaintiff cả hai bên nên
    // không đối lập — mức vàng (tình huống reviewer mô tả), không phải đỏ, nhưng KHÔNG được lưu
    // im lặng: phải qua cổng xác nhận như mọi mức vàng khác.
    expect(fn () => app(OpenMatter::class)->handle(baseAttributes($client, $lawyer, $type), []))
        ->toThrow(ConflictAcknowledgementRequired::class);

    expect(Matter::query()->where('client_id', $client->id)->count())->toBe(1);

    $secondMatter = app(OpenMatter::class)->handle(
        baseAttributes($client, $lawyer, $type),
        [],
        acknowledged: ConflictLevel::Yellow,
    );

    expect($secondMatter->exists)->toBeTrue()
        ->and(Matter::query()->where('client_id', $client->id)->count())->toBe(2);
});

it('requires client_role: a missing key throws a validation error and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $attributes = baseAttributes($newClient, $lawyer, $type);
    unset($attributes['client_role']);

    expect(fn () => app(OpenMatter::class)->handle($attributes, []))->toThrow(ValidationException::class);
    expect(Matter::count())->toBe(0);
});

it('detects red when the firm client is the defendant and an opposing plaintiff is an existing client', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    // existingClient đã là khách hàng của văn phòng ở một vụ khác, vai plaintiff ở đó.
    $existingClient = Client::factory()->create(['id_number' => '088877766655', 'name' => 'Đỗ Thị Mai']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient, PartyRole::Plaintiff)->create();

    // Vụ việc mới: khách hàng của văn phòng lần này là BỊ ĐƠN, và bên nguyên đơn phía đối
    // phương chính là existingClient — nếu client_role bị mặc định sai thành plaintiff (lỗi cũ),
    // đây sẽ chỉ lên vàng thay vì đỏ.
    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $caught = null;

    try {
        app(OpenMatter::class)->handle(
            baseAttributes($newClient, $manager, $type, ['client_role' => PartyRole::Defendant]),
            [[
                'role' => PartyRole::Plaintiff,
                'name' => 'Đỗ Thị Mai (nguyên đơn)',
                'id_number' => '088877766655',
            ]],
        );
    } catch (ConflictBlocked $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->result->level)->toBe(ConflictLevel::Red)
        ->and($caught->result->matches->first()->level)->toBe(ConflictLevel::Red);

    expect(Matter::query()->where('client_id', $newClient->id)->exists())->toBeFalse();
});

it('applies the active checklist template of the matter type when opening the matter', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate(3);

    $matter = app(OpenMatter::class)->handle(baseAttributes($newClient, $lawyer, $type), []);

    expect($matter->checklistItems()->count())->toBe(3);
});

it('always writes an activity entry even when the result is green, without duplicating the conflict-check entry', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    app(OpenMatter::class)->handle(baseAttributes($newClient, $lawyer, $type), []);

    expect(Activity::query()->where('event', 'conflict_check_run')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'matter_opened')->count())->toBe(1);

    $matterOpened = Activity::query()->where('event', 'matter_opened')->first();
    expect($matterOpened->properties->get('conflict_level'))->toBe('green')
        ->and($matterOpened->properties->get('conflict_overridden'))->toBeFalse();
});

it('creates an own-client matter_party for the matter client, bridging the encrypted id_number into a hash so a later matter can find it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $clientA = Client::factory()->create(['id_number' => '011122233344', 'name' => 'Phạm Văn A']);
    $type = matterTypeWithTemplate();

    $matterA = app(OpenMatter::class)->handle(baseAttributes($clientA, $lawyer, $type), []);

    $ownParty = $matterA->parties()->where('client_id', $clientA->id)->first();
    expect($ownParty)->not->toBeNull()
        ->and($ownParty->is_our_client)->toBeTrue()
        ->and($ownParty->role)->toBe(PartyRole::Plaintiff)
        ->and($ownParty->id_number_hash)->toBe(Normalizer::idNumberHash('011122233344'));

    // Không có Action nào khác kiểm tra việc này: nếu OpenMatter bỏ sót bước dựng own-client
    // party, clientA sẽ vô hình với RunConflictCheck (clients không có cột hash), và vụ việc thứ
    // hai dưới đây (bị đơn trùng số căn cước với clientA) sẽ KHÔNG bị chặn — sai với SPEC §11.
    $clientB = Client::factory()->create();

    expect(fn () => app(OpenMatter::class)->handle(
        baseAttributes($clientB, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Phạm Văn A (bị đơn)',
            'id_number' => '011122233344',
        ]],
    ))->toThrow(ConflictBlocked::class);
});
