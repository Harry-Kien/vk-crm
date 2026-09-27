<?php

use App\Actions\RemoveMatterParty;
use App\Actions\RunConflictCheck;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Task 9 (`conflict-05`, `conflict-09`, `conflict-10`, brief R14) — test màn hình đi qua Livewire
 * (CLAUDE.md/brief: "test cho một màn hình phải đi qua Livewire… không gọi thẳng Action"), mirror
 * đúng bốn gạch đầu dòng của brief.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/** @return Collection<int, Notification> */
function editRemovePartyNotifications(): Collection
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications;
}

/**
 * Brief Test bullet 1: "Sửa số điện thoại gõ sai thành đúng: phone_normalized đổi, và lần kiểm tra
 * ở vụ khác sau đó khớp đúng."
 */
it('fixes a typo in the phone number through the edit-party screen action, and a later matter matches it', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Ông Gõ Sai Số']);
    $party->identify(null, '0900000000')->save();

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('editParty', $party, data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Ông Gõ Sai Số',
        'phone' => '0912345678',
    ])->assertHasNoTableActionErrors();

    expect($party->fresh()->phone_normalized)->toBe(Normalizer::phone('0912345678'));

    $otherMatter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $result = app(RunConflictCheck::class)->handle(
        collect([(new MatterParty(['role' => PartyRole::Plaintiff, 'name' => 'Trùng số']))->identify(null, '0912345678')]),
        $otherMatter,
        $lawyer,
    );

    expect($result->matches->pluck('matterCode')->all())->toContain($matter->code);
});

/**
 * Brief Test bullet 2 (nửa "gỡ"): "Gỡ bên nhập nhầm: bên đó không còn sinh khớp ở lần kiểm tra
 * sau", đi qua đúng đường màn hình (nút "Gỡ", lý do bắt buộc).
 */
it('removes a party through the remove-party screen action, taking it out of the table and out of matching', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Nhập nhầm']);
    $party->identify('001099001234', null)->save();

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    // Lý do rỗng bị từ chối trước, ngay trên đúng đường màn hình.
    $component->callTableAction('removeParty', $party, data: ['reason' => '   '])
        ->assertHasTableActionErrors(['reason']);

    expect($party->fresh()->trashed())->toBeFalse();

    // Action VẪN đang mounted sau lỗi validation ở trên (modal còn mở) — tiếp tục đúng cách
    // Livewire giữ trạng thái đó (`setTableActionData()->callMountedTableAction()`), không gọi
    // lại `callTableAction()` một lần nữa (Filament sẽ đọc nhầm thành một action LỒNG bên trong
    // chính nó, cùng công thức `createParty()` dùng ở `ViewMatterTest.php`).
    $component->setTableActionData(['reason' => 'Nhập nhầm, chưa từng là bên này.'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$party]);

    expect($party->fresh()->trashed())->toBeTrue();

    $result = app(RunConflictCheck::class)->handle(
        collect([(new MatterParty(['role' => PartyRole::Plaintiff, 'name' => 'Trùng CCCD']))->identify('001099001234', null)]),
        Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]),
        $lawyer,
    );

    expect($result->matches->pluck('matterCode')->all())->not->toContain($matter->code);
});

/**
 * Brief Test bullet 3: "Nhập (+84) 912 345 678 và +84 (0) 912-345-678: lưu được, phone_normalized
 * = 84912345678." (`conflict-10`) — đi qua đúng modal "thêm bên", vì đây là nơi phát hiện được ghi
 * lại (regex mặc định của ->tel() áp cho cả hai form, đã sửa ở cả hai).
 */
it('accepts phone numbers with a leading country-code parenthesis through the add-party screen action', function (string $typed) {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bên nhập số quốc tế',
        'phone' => $typed,
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Bên nhập số quốc tế')->first()?->phone_normalized)
        ->toBe('84912345678');
})->with([
    '(+84) 912 345 678',
    '+84 (0) 912-345-678',
]);

/**
 * Brief Test bullet 4: "Thông báo có nhiều khớp render thành nhiều dòng (assert markup)."
 * (`conflict-09`) — hai khớp phải cách nhau bằng `<br>` thật trong HTML của thông báo, không phải
 * một chuỗi "\n" thô chạy liền.
 */
it('renders a notification with more than one conflict match as separate lines, not one run-on paragraph', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);

    $firstClient = Client::factory()->create(['id_number' => '001099001234', 'name' => 'Người Một']);
    $firstMatter = Matter::factory()->create();
    MatterParty::factory()->for($firstMatter)->ourClient($firstClient, PartyRole::Plaintiff)->create();

    $secondClient = Client::factory()->create(['phone' => '0900112233', 'name' => 'Người Hai']);
    $secondMatter = Matter::factory()->create();
    MatterParty::factory()->for($secondMatter)->ourClient($secondClient, PartyRole::Plaintiff)->create();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => false,
        'name' => 'Bên khớp hai hồ sơ',
        'id_number' => '001099001234',
        'phone' => '0900112233',
    ])->assertHasTableActionErrors(['acknowledge_conflict']);

    $notification = editRemovePartyNotifications()->first();
    $body = (string) $notification->getBody();

    expect(substr_count($body, '<br>'))->toBeGreaterThanOrEqual(1)
        ->and($body)->toContain($firstMatter->code)
        ->and($body)->toContain($secondMatter->code)
        // Không còn "\n" thô nối hai dòng: mỗi khớp phải đứng SAU một thẻ <br>, không dính liền.
        ->and(preg_match('/'.preg_quote($firstMatter->code, '/').'[^<]*\n[^<]*'.preg_quote($secondMatter->code, '/').'/', $body))
        ->toBe(0);
});

/**
 * Fix round 1, C1 — hai tab cùng nhìn một bên. Gọi thẳng `editParty()`/`removeParty()` (private)
 * trên MỘT instance component ĐÃ MOUNT modal cho `$party` — đúng hình dạng "tab B giữ một tham
 * chiếu Model cũ, được dựng TRƯỚC lúc tab A gỡ nó". Không đi qua `callMountedTableAction()`: Filament
 * tự resolve lại bản ghi của MỘT action đã mount qua `resolveTableAction()` mỗi lần gọi (không
 * cache tham chiếu cũ), và khi bản ghi biến mất (bị `SoftDeletingScope` loại), NÓ tự ném
 * `ActionNotResolvableException` và lặng lẽ HUỶ MOUNT action — một lớp bảo vệ RIÊNG của framework,
 * đứng TRƯỚC mã của chính lớp này, nên không bao giờ chạm tới `editParty()`/`removeParty()` để mà
 * kiểm được đúng nhánh `catch (MatterPartyAlreadyRemoved)` vừa thêm. Gọi thẳng hai hàm private này
 * (qua `ReflectionMethod`) mới THẬT SỰ tái hiện được đúng điều Task 9 phải tự lo: hai Action
 * (`UpdateMatterParty`/`RemoveMatterParty`) TỰ chúng nhận một `MatterParty` không còn tồn tại dưới
 * khoá, và lớp màn hình này dịch đúng ngoại lệ đó thành một Notification, không phải một exception
 * lọt ra ngoài.
 */
function invokePrivate(object $instance, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($instance, ...$args);
}

it('shows a notification, not an exception, when editing a party that another tab already removed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Bên A']);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    // "Tab A": gỡ đúng bên đó qua đúng đường màn hình.
    app(RemoveMatterParty::class)->handle($party, $lawyer, 'Nhập nhầm, gỡ từ tab khác.');

    // "Tab B": gửi lại modal SỬA đang mở cho $party — vẫn tham chiếu PHP object CŨ, y hệt những gì
    // `->action()` của `editPartyAction()` nhận được nếu Filament không tự huỷ mount trước đó.
    invokePrivate($component->instance(), 'editParty', $party, [
        'role' => PartyRole::Related->value,
        'name' => 'Bên A sửa',
    ]);

    $notification = editRemovePartyNotifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->getTitle())->toBe(__('matters.parties.already_removed'))
        ->and($party->fresh()->name)->toBe('Bên A');
});

it('shows a notification, not an exception, when removing a party that another tab already removed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Defendant, 'name' => 'Bên B']);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    app(RemoveMatterParty::class)->handle($party, $lawyer, 'Nhập nhầm, gỡ lần đầu từ tab khác.');

    invokePrivate($component->instance(), 'removeParty', $party, ['reason' => 'Gỡ lần hai, từ tab này.']);

    $notification = editRemovePartyNotifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->getTitle())->toBe(__('matters.parties.already_removed'));
});

// -------------------------------------------------------------------------------------------
// Final review X2 (A-I1): bên của CHÍNH khách hàng vụ việc (is_our_client, client_id =
// matters.client_id) không gỡ được (RemoveMatterParty) — nên cũng không được "gỡ vòng" bằng cách
// SỬA nó: tắt công tắc "là khách hàng của văn phòng" hay trỏ sang một khách hàng khác. Vai trò,
// địa chỉ, ghi chú vẫn sửa được. Kèm A-M2: chỉ kiểm tra tầm nhìn khách hàng khi client_id ĐỔI.
// -------------------------------------------------------------------------------------------

/** @return array{0: User, 1: Matter, 2: MatterParty} */
function matterWithOwnClientParty(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create(['name' => 'Khách của vụ']);
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'client_id' => $client->id]);
    $party = MatterParty::factory()->for($matter)->ourClient($client)->create();

    return [$lawyer, $matter, $party];
}

function partiesManagerFor(Matter $matter)
{
    return test()->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

it('refuses switching the matter\'s own client party off "our client" through the edit screen', function () {
    [$lawyer, $matter, $party] = matterWithOwnClientParty();
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)->callTableAction('editParty', $party, data: [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => false,
        'name' => 'Khách của vụ',
    ])->assertHasTableActionErrors(['client_id']);

    $fresh = $party->fresh();
    expect($fresh->is_our_client)->toBeTrue()
        ->and($fresh->client_id)->toBe($matter->client_id);
});

it('refuses re-pointing the matter\'s own client party at another client through the edit screen', function () {
    [$lawyer, $matter, $party] = matterWithOwnClientParty();
    $other = Client::factory()->create();
    // Một khách khác mà luật sư thấy được — để lời từ chối là của luật mới, không phải của tầm nhìn.
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'client_id' => $other->id]);
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)->callTableAction('editParty', $party, data: [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $other->id,
        'name' => 'Khách của vụ',
    ])->assertHasTableActionErrors(['client_id']);

    expect($party->fresh()->client_id)->toBe($matter->client_id);
});

it('still lets staff edit the role, address and note of the matter\'s own client party, with the two locked fields disabled', function () {
    [$lawyer, $matter, $party] = matterWithOwnClientParty();
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)
        ->mountTableAction('editParty', $party)
        ->assertTableActionDataSet(['is_our_client' => true, 'client_id' => $matter->client_id])
        ->assertFormFieldIsDisabled('is_our_client', 'mountedActionSchema0')
        ->assertFormFieldIsDisabled('client_id', 'mountedActionSchema0')
        ->setTableActionData([
            'role' => PartyRole::Defendant->value,
            'address' => 'Địa chỉ mới',
            'note' => 'Ghi chú mới',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $fresh = $party->fresh();
    expect($fresh->role)->toBe(PartyRole::Defendant)
        ->and($fresh->address)->toBe('Địa chỉ mới')
        ->and($fresh->note)->toBe('Ghi chú mới')
        ->and($fresh->is_our_client)->toBeTrue()
        ->and($fresh->client_id)->toBe($matter->client_id);
});

it('keeps the two fields editable on a co-client party that is not the matter\'s own client', function () {
    [$lawyer, $matter] = matterWithOwnClientParty();
    $coClient = Client::factory()->create();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'client_id' => $coClient->id]);
    $coParty = MatterParty::factory()->for($matter)->ourClient($coClient)->create();
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)
        ->mountTableAction('editParty', $coParty)
        ->assertFormFieldIsEnabled('is_our_client', 'mountedActionSchema0')
        ->assertFormFieldIsEnabled('client_id', 'mountedActionSchema0');
});

/** A-M2: một đồng-khách-hàng mà luật sư không thấy hồ sơ vẫn sửa được — client_id không đổi. */
it('lets a lawyer edit a co-client party whose client they cannot see, when the client link does not change', function () {
    [$lawyer, $matter] = matterWithOwnClientParty();
    $hiddenClient = Client::factory()->create(['name' => 'Đồng khách ẩn']);
    $coParty = MatterParty::factory()->for($matter)->ourClient($hiddenClient)->create();
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)->callTableAction('editParty', $coParty, data: [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $hiddenClient->id,
        'name' => 'Đồng khách ẩn',
        'note' => 'Đã gọi điện',
    ])->assertHasNoTableActionErrors();

    expect($coParty->fresh()->note)->toBe('Đã gọi điện');
});

it('still refuses re-pointing a party at a client the lawyer cannot see', function () {
    [$lawyer, $matter] = matterWithOwnClientParty();
    $hiddenClient = Client::factory()->create();
    $party = MatterParty::factory()->for($matter)->create(['role' => PartyRole::Related, 'name' => 'Bên thường']);
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)->callTableAction('editParty', $party, data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => true,
        'client_id' => $hiddenClient->id,
        'name' => 'Bên thường',
    ])->assertHasTableActionErrors(['client_id']);

    expect($party->fresh()->client_id)->toBeNull();
});

/** A-M2: bên trỏ tới một khách hàng đã xoá mềm không còn 404 với người sửa được vụ việc. */
it('lets staff edit a party linked to a soft-deleted client without a 404', function () {
    [$lawyer, $matter] = matterWithOwnClientParty();
    $gone = Client::factory()->create(['name' => 'Khách đã xoá']);
    $coParty = MatterParty::factory()->for($matter)->ourClient($gone)->create();
    $gone->delete();
    $this->actingAs($lawyer, 'web');

    partiesManagerFor($matter)->callTableAction('editParty', $coParty, data: [
        'role' => PartyRole::Plaintiff->value,
        'is_our_client' => true,
        'client_id' => $gone->id,
        'name' => 'Khách đã xoá',
        'address' => 'Địa chỉ đã sửa',
    ])->assertHasNoTableActionErrors();

    expect($coParty->fresh()->address)->toBe('Địa chỉ đã sửa');
});
