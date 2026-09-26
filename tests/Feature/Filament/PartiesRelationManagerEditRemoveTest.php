<?php

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
