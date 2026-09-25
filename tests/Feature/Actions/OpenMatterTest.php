<?php

use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Exceptions\OurClientPartyNeedsClient;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Normalizer;
use App\Support\OpenMatterResult;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
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
        $lawyer,
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
        $manager,
        baseAttributes($newClient, $manager, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
        'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý mở vụ việc.',
    )->matter;

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
        $lawyer,
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
        $manager,
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
        $lawyer,
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
        $lawyer,
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ]],
        acknowledged: ConflictLevel::Yellow,
    )->matter;

    expect($matter->exists)->toBeTrue();

    $matterOpened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($matterOpened->properties->get('conflict_level'))->toBe('yellow');
});

it('needs no acknowledgement for a green result', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle($lawyer, baseAttributes($newClient, $lawyer, $type), [])->matter;

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
        $lawyer,
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
        $lawyer,
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Người Chỉ Có Tên Không Định Danh',
        ]],
        acknowledged: ConflictLevel::Green,
    )->matter;

    expect($matter->exists)->toBeTrue();

    $matterOpened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($matterOpened->properties->get('conflict_level'))->toBe('green')
        ->and($matterOpened->properties->get('incomplete_conflict_parties'))->toBe(['Người Chỉ Có Tên Không Định Danh']);
});

/**
 * R13(a)/`conflict-02` (M6.5 Task 8, was a real bug — see the mutation probe in
 * `RunConflictCheckTest.php` for the red evidence). Before this fix, a returning client's own
 * `matter_parties` row from their FIRST matter always matched the own-client party `OpenMatter`
 * builds for their SECOND matter (same `id_number_hash`, same client), so every single matter a
 * lawyer opened for a repeat client came back yellow — a gate that is always up teaches people to
 * click through it. A client revisiting the firm is not a conflict with themselves: the matching
 * row is `is_our_client` with the SAME `client_id`, and that self-match is now excluded.
 */
it('lets a lawyer open a second matter for a returning client cleanly, with no acknowledgement needed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $client = Client::factory()->create(['id_number' => '099988877766']);
    $type = matterTypeWithTemplate();

    // Vụ việc đầu tiên của khách hàng: hoàn toàn xanh, không có bên nào trùng ở đâu cả.
    $firstMatter = app(OpenMatter::class)->handle($lawyer, baseAttributes($client, $lawyer, $type), [])->matter;
    expect($firstMatter->exists)->toBeTrue();

    // Vụ việc thứ hai của CHÍNH khách hàng đó: own-client party mới trùng id_number_hash với
    // own-client party của vụ thứ nhất (chính khách hàng này) — R13(a) loại trừ đúng trường hợp
    // này khỏi kết quả tìm kiếm, nên lần mở vụ thứ hai phải xanh sạch, không cần xác nhận gì cả.
    $secondOpening = app(OpenMatter::class)->handle($lawyer, baseAttributes($client, $lawyer, $type), []);

    expect($secondOpening->result->level)->toBe(ConflictLevel::Green)
        ->and($secondOpening->result->matches)->toBeEmpty()
        ->and($secondOpening->result->requiresAcknowledgement())->toBeFalse()
        ->and($secondOpening->matter->exists)->toBeTrue()
        ->and(Matter::query()->where('client_id', $client->id)->count())->toBe(2);
});

it('requires client_role: a missing key throws a validation error and saves nothing', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $attributes = baseAttributes($newClient, $lawyer, $type);
    unset($attributes['client_role']);

    expect(fn () => app(OpenMatter::class)->handle($lawyer, $attributes, []))->toThrow(ValidationException::class);
    expect(Matter::count())->toBe(0);
});

/**
 * Fix round 1, minor ruling: "The OpenMatter Action itself must reject client_role =
 * opposing_counsel, not only the form." `MatterForm::clientRolePartyOptions()` (R13f) đã loại giá
 * trị này khỏi ô chọn — đó là ranh giới HIỂN THỊ của panel, không phải cổng thật. Gọi thẳng Action
 * với giá trị đó (đường mà một console/job/import/request bị chỉnh sửa có thể đi) phải bị từ chối
 * ở ĐÂY, không tin caller đã đi qua đúng form.
 */
it('refuses client_role = opposing_counsel at the Action itself, not only at the form', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $attributes = baseAttributes($newClient, $lawyer, $type, ['client_role' => PartyRole::OpposingCounsel]);

    expect(fn () => app(OpenMatter::class)->handle($lawyer, $attributes, []))->toThrow(ValidationException::class);
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
            $manager,
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

    $matter = app(OpenMatter::class)->handle($lawyer, baseAttributes($newClient, $lawyer, $type), [])->matter;

    expect($matter->checklistItems()->count())->toBe(3);
});

it('always writes an activity entry even when the result is green, without duplicating the conflict-check entry', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    app(OpenMatter::class)->handle($lawyer, baseAttributes($newClient, $lawyer, $type), []);

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

    $matterA = app(OpenMatter::class)->handle($lawyer, baseAttributes($clientA, $lawyer, $type), [])->matter;

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
        $lawyer,
        baseAttributes($clientB, $lawyer, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Phạm Văn A (bị đơn)',
            'id_number' => '011122233344',
        ]],
    ))->toThrow(ConflictBlocked::class);
});

/**
 * Fix round 3, Critical: `handle()` trả về BÌNH THƯỜNG ở hai đường rất khác nhau — mức xanh sạch,
 * và mức ĐỎ đã được manager ghi đè. Khi nó chỉ trả một `Matter`, caller không phân biệt được hai
 * đường đó và `CreateMatter` suy ra "không ném gì ⟹ xanh sạch", rồi hiện "không tìm thấy bản ghi
 * trùng nào" MÀU XANH cho chính người vừa ghi đè một xung đột mức đỏ họ chưa từng được xem.
 *
 * Nên `handle()` trả `OpenMatterResult` (cùng khuôn `AddMatterPartyResult`), mang theo kết quả
 * kiểm tra, có ghi đè hay không, và lý do ghi đè. Test này so cả hai đường trong một chỗ: nếu
 * thiếu bất kỳ mảnh nào, màn hình lại phải đoán.
 */
it('returns the conflict-check result, the override flag and the reason alongside the matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    $type = matterTypeWithTemplate();

    // Đường xanh sạch.
    $clean = app(OpenMatter::class)->handle(
        $manager,
        baseAttributes(Client::factory()->create(), $manager, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Bị đơn không trùng ai',
            'id_number' => '012345678901',
        ]],
    );

    expect($clean)->toBeInstanceOf(OpenMatterResult::class)
        ->and($clean->matter->exists)->toBeTrue()
        ->and($clean->result->level)->toBe(ConflictLevel::Green)
        ->and($clean->result->matches)->toBeEmpty()
        ->and($clean->overridden)->toBeFalse()
        ->and($clean->overrideReason)->toBeNull();

    // Đường đỏ đã ghi đè: Action cũng trả về bình thường, nhưng kết quả KHÔNG hề giống đường trên.
    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    MatterParty::factory()->for(Matter::factory()->create())->ourClient($existingClient)->create();

    $overridden = app(OpenMatter::class)->handle(
        $manager,
        baseAttributes(Client::factory()->create(), $manager, $type),
        [[
            'role' => PartyRole::Defendant,
            'name' => 'Nguyễn Văn Hùng (bị đơn)',
            'id_number' => '079012345678',
        ]],
        '  Đã trao đổi với khách hàng, xác nhận không phải cùng một người.  ',
    );

    expect($overridden->matter->exists)->toBeTrue()
        ->and($overridden->result->level)->toBe(ConflictLevel::Red)
        ->and($overridden->result->matches)->not->toBeEmpty()
        ->and($overridden->overridden)->toBeTrue()
        // Lý do đã được trim, đúng giá trị Action ghi vào activity log — màn hình hiện lại được
        // chính xác cái đã lưu, không phải cái người dùng gõ kèm khoảng trắng thừa.
        ->and($overridden->overrideReason)->toBe('Đã trao đổi với khách hàng, xác nhận không phải cùng một người.');
});

/**
 * Fix round 3, finding I-6: một bên TRONG DANH SÁCH bên (repeater của form tạo vụ việc) được
 * đánh dấu "là khách hàng của văn phòng" kèm `client_id` phải được dựng y hệt own-client party —
 * tên và định danh lấy từ hồ sơ `Client` thật, KHÔNG lấy từ form. `AddMatterParty` đã làm đúng
 * từ fix round 2 (finding C) và commit 3859c7a; `OpenMatter` chỉ áp quy tắc đó cho khách hàng
 * CHÍNH của vụ việc, còn bên trong repeater vẫn giữ nguyên tên/số căn cước/điện thoại form gửi
 * lên — và màn hình tạo vụ việc mới mở ra đúng đường đó.
 *
 * Hậu quả không phải chuyện thẩm mỹ: `id_number_hash` là cây cầu DUY NHẤT qua `clients.id_number`
 * (mã hoá, không có cột hash), nên một hash dựng từ số gõ sai là chính xác cách một lần kiểm tra
 * xung đột sau này bỏ sót bên này — im lặng, vĩnh viễn.
 */
it('rebuilds a repeater party flagged as our client from the Client record, ignoring mismatched form values', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $otherOwnClient = Client::factory()->create([
        'name' => 'Công ty TNHH Hoàng Long',
        'id_number' => '055566677788',
        'phone' => '028 3822 1234',
    ]);
    $type = matterTypeWithTemplate();

    $opening = app(OpenMatter::class)->handle(
        $lawyer,
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Plaintiff,
            'name' => 'Gõ sai tên hoàn toàn',
            'is_our_client' => true,
            'client_id' => $otherOwnClient->id,
            // Dữ liệu form khác hẳn hồ sơ Client thật — phải bị Action bỏ qua hoàn toàn.
            'id_number' => '000000000000',
            'phone' => '0999999999',
        ]],
    )->matter;

    $party = $opening->parties()->where('client_id', $otherOwnClient->id)->first();

    expect($party)->not->toBeNull()
        ->and($party->name)->toBe('Công ty TNHH Hoàng Long')
        ->and($party->id_number_hash)->toBe(Normalizer::idNumberHash('055566677788'))
        ->and($party->phone_normalized)->toBe(Normalizer::phone('028 3822 1234'))
        ->and($party->id_number_hash)->not->toBe(Normalizer::idNumberHash('000000000000'));
});

/**
 * Hệ quả của việc bên trong repeater cũng được dựng lại từ hồ sơ `Client`: một `client_id` không
 * có hồ sơ tương ứng phải NỔ, đúng như khách hàng chính vẫn nổ từ trước (`firstOrFail`). Im lặng
 * bỏ qua rồi quay về tin form sẽ dựng ra một bên "là khách hàng của văn phòng" không có hồ sơ nào
 * phía sau — đúng thứ mà quy tắc này sinh ra để chặn.
 */
it('refuses an our-client repeater party whose client_id has no Client row', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    expect(fn () => app(OpenMatter::class)->handle(
        $lawyer,
        baseAttributes($newClient, $lawyer, $type),
        [[
            'role' => PartyRole::Plaintiff,
            'name' => 'Bên không có hồ sơ',
            'is_our_client' => true,
            'client_id' => 999999,
        ]],
    ))->toThrow(ModelNotFoundException::class);

    expect(Matter::query()->where('client_id', $newClient->id)->exists())->toBeFalse();
});

/**
 * Fix M3 (review toàn nhánh, finding 1): actor là THAM SỐ, không phải phiên đăng nhập.
 *
 * Ba test dưới đây cố tình cho phiên đăng nhập và actor là HAI người KHÁC NHAU, với quyền trái
 * ngược nhau, để phân biệt được hai cách cài đặt: một Action đọc `Auth::guard('web')->user()`
 * sẽ theo phiên, một Action đúng hợp đồng sẽ theo tham số. Không có test nào kiểu này thì việc
 * đổi `$actor = Auth::...` thành tham số đi qua mà không ai biết — mọi test cũ đều `actingAs`
 * đúng người mình truyền vào, nên hai cách cài đặt cho cùng kết quả.
 */
it('authorizes the create gate against the actor parameter, not the user in the session', function () {
    // Phiên là kế toán (KHÔNG có matter.create); actor truyền vào là luật sư (CÓ).
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($accountant, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle($lawyer, baseAttributes($newClient, $lawyer, $type), [])->matter;

    expect($matter->exists)->toBeTrue();

    // Cả hai dòng nhật ký của CÙNG một thao tác phải chỉ về cùng một người — chính actor, không
    // phải người đang có phiên. Đây là lý do tồn tại của dòng conflict_check_run (SPEC §6.10
    // bước 4: chứng minh đã kiểm tra, và BỞI AI).
    $opened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    $checked = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();

    expect($opened->causer?->is($lawyer))->toBeTrue()
        ->and($checked->causer?->is($lawyer))->toBeTrue()
        ->and($checked->properties->get('actor_explicit'))->toBeTrue();
});

it('refuses when the actor may not create matters even though the session user may', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->actingAs($lawyer, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    expect(fn () => app(OpenMatter::class)->handle($accountant, baseAttributes($newClient, $lawyer, $type), []))
        ->toThrow(AuthorizationException::class);

    expect(Matter::count())->toBe(0);
});

it('reads the red-override role from the actor, not from the session', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();

    $existingClient = Client::factory()->create(['id_number' => '079012345678', 'name' => 'Nguyễn Văn Hùng']);
    $otherMatter = Matter::factory()->create();
    MatterParty::factory()->for($otherMatter)->ourClient($existingClient)->create();

    $type = matterTypeWithTemplate();
    $reason = 'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý mở vụ việc.';
    $defendant = [[
        'role' => PartyRole::Defendant,
        'name' => 'Nguyễn Văn Hùng (bị đơn)',
        'id_number' => '079012345678',
    ]];

    // Phiên là manager, actor là luật sư: quyền ghi đè phải đọc theo actor → vẫn bị chặn.
    $this->actingAs($manager, 'web');
    $clientForLawyer = Client::factory()->create();

    expect(fn () => app(OpenMatter::class)->handle(
        $lawyer,
        baseAttributes($clientForLawyer, $lawyer, $type),
        $defendant,
        $reason,
    ))->toThrow(ConflictBlocked::class);

    expect(Matter::query()->where('client_id', $clientForLawyer->id)->exists())->toBeFalse();

    // Chiều ngược lại: phiên là luật sư, actor là manager → ghi đè được, và dòng nhật ký ghi
    // đúng manager là người đã ghi đè.
    $this->actingAs($lawyer, 'web');
    $clientForManager = Client::factory()->create();

    $matter = app(OpenMatter::class)->handle(
        $manager,
        baseAttributes($clientForManager, $manager, $type),
        $defendant,
        $reason,
    )->matter;

    expect($matter->exists)->toBeTrue();

    $opened = Activity::query()->where('event', 'matter_opened')->latest('id')->first();
    expect($opened->properties->get('conflict_overridden'))->toBeTrue()
        ->and($opened->causer?->is($manager))->toBeTrue();
});

it('stamps created_by on the matter and its parties from the actor, not from the session', function () {
    // Cùng lý do như stage_logs.created_by: Action đã biết actor, nên hai cột "ai tạo" không được
    // suy luận từ phiên đang mở — phiên có thể là người khác (admin thao tác hộ) hoặc không có.
    $admin = User::factory()->withRole(Role::Admin)->create();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($admin, 'web');

    $newClient = Client::factory()->create();
    $type = matterTypeWithTemplate();

    $matter = app(OpenMatter::class)->handle($lawyer, baseAttributes($newClient, $lawyer, $type), [
        [
            'role' => PartyRole::Defendant,
            'name' => 'Bên bị đơn không trùng ai',
            'id_number' => '099988877766',
            'phone' => '0900000111',
        ],
    ])->matter;

    expect($matter->created_by)->toBe($lawyer->id)
        ->and($matter->updated_by)->toBe($lawyer->id)
        ->and($matter->parties()->pluck('created_by')->unique()->all())->toBe([$lawyer->id]);
});

/**
 * I-2 (Important, fix round 4), nhánh "mở vụ việc" của cùng lỗ hổng — xem docblock bản sinh đôi ở
 * `AddMatterPartyTest`. Một bên trong danh sách bên tuyên bố "là khách hàng của văn phòng" mà không
 * chỉ ra hồ sơ nào thì định danh rơi về dữ liệu gõ tay, và `SyncClientPartyIdentities` (lọc theo
 * `client_id`) không bao giờ sửa lại được dòng đó.
 */
it('refuses to open a matter when an other-party claims to be our client without a client record', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $client = Client::factory()->create();
    $type = matterTypeWithTemplate();
    $matterCountBefore = Matter::count();

    expect(fn () => app(OpenMatter::class)->handle(
        actor: $lawyer,
        attributes: baseAttributes($client, $lawyer, $type),
        parties: [[
            'role' => PartyRole::Related->value,
            'is_our_client' => true,
            'client_id' => null,
            'name' => 'Tên gõ tay',
            'id_number' => '079012345678',
        ]],
    ))->toThrow(OurClientPartyNeedsClient::class);

    expect(Matter::count())->toBe($matterCountBefore);
});

/**
 * Fix round 1, minor ruling: `LockTimeoutException` (khoá `conflict-check` không lấy được sau 10
 * giây — xem docblock lớp "Về khoá R13(g)") phải thành một lời từ chối tiếng Việt, không phải một
 * trang lỗi 500. Giữ khoá thật (cùng tên, cùng cache store) trước khi gọi `handle()`, để lần gọi
 * này THẬT SỰ không lấy được khoá — bài test này chờ ĐÚNG 10 giây thật (thời gian chờ của Action).
 */
it('turns a busy conflict-check lock into a Vietnamese refusal, not a 500', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($lawyer, 'web');

    $client = Client::factory()->create();
    $type = matterTypeWithTemplate();
    $matterCountBefore = Matter::count();

    $lock = Cache::store('database')->lock('conflict-check', 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(OpenMatter::class)->handle($lawyer, baseAttributes($client, $lawyer, $type), []))
            ->toThrow(ConflictCheckBusy::class, __('exceptions.conflict_check_busy'));

        expect(Matter::count())->toBe($matterCountBefore);
    } finally {
        $lock->release();
    }
});
