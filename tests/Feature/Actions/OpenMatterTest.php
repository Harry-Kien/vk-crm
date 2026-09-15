<?php

use App\Actions\OpenMatter;
use App\Enums\PartyRole;
use App\Enums\Role;
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

function baseAttributes(Client $client, User $leadLawyer, MatterType $type, array $overrides = []): array
{
    return [...[
        'client_id' => $client->id,
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
        ->and($activity->properties->get('override_reason'))->toBe('Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người, đồng ý mở vụ việc.');
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

it('warns at yellow for a normalized-name-only match and still saves after the caller acknowledges', function () {
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
            // Tên trùng sau chuẩn hoá nhưng số căn cước/điện thoại khác hẳn — chỉ lên vàng.
            'role' => PartyRole::Defendant,
            'name' => '  Lê   THỊ hoa ',
            'id_number' => '033344455566',
            'phone' => '0977888999',
        ]],
    );

    expect($matter->exists)->toBeTrue();

    $activity = Activity::query()->where('event', 'conflict_check_run')->latest('id')->first();
    expect($activity->properties->get('level'))->toBe('yellow');
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
