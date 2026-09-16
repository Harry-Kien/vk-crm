<?php

use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\PartiesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\StageLogsRelationManager;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Client;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

/**
 * Notification::assertNotified() chỉ so được tiêu đề (hoặc toàn bộ object), không đọc được nội
 * dung (body) — cần đọc trực tiếp collection Notification đã gửi để kiểm tra body có nhắc đúng mã
 * hồ sơ gây xung đột hay không (fix round 2 finding A).
 */
function sentNotification(string $title): ?Notification
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications->first(fn (Notification $notification): bool => $notification->getTitle() === $title);
}

/**
 * Task 6 brief: "trang trả 404 với vụ ngoài quyền". Đã có một bản ở MatterResourceTest, lặp lại
 * ở đây vì đây là test bắt buộc của chính task này và trang giờ có nội dung thật (trước đây trang
 * rỗng — Task 4/5 chỉ dựng route để kiểm tra route-binding).
 */
it('returns 404 for a matter outside the actors scope', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create();

    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/**
 * SPEC §7.2: mỗi dòng tiến độ hiện rõ đâu là ghi chú nội bộ (nhãn "Nội bộ"). Nhân sự có quyền
 * xem vụ việc (đội ngũ) phải thấy nhãn này. Kiểm tra "không lộ" cho ai không có quyền ở CẢ hai
 * tầng: trang 404 thẳng (không ai đọc được gì), VÀ nếu ai đó địa chỉ thẳng đến chính relation
 * manager của tab Tiến độ (bỏ qua trang), ScopesToVisibleMatters cũng phải chặn — đây là điều
 * brief yêu cầu kiểm tra riêng, không chỉ tin vào 404 của trang cha.
 */
it('shows the internal note marker to a team member but the relation manager itself hides it from an outsider', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    StageLog::factory()->for($matter)->internalOnly()->create(['internal_note' => 'Ghi chú riêng cho nội bộ về vụ này']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertSee(__('matters.stage_log_fields.internal_marker'))
        ->assertSee('Ghi chú riêng cho nội bộ về vụ này');

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($outsider, 'web');

    // Tầng 1: ScopesToVisibleMatters chặn ngay trong chính relation manager, kể cả khi nó được
    // mount trực tiếp (bỏ qua authorizeAccess() của ViewMatter).
    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertDontSee('Ghi chú riêng cho nội bộ về vụ này');

    // Tầng 2: trên thực tế, outsider còn không mở nổi trang — 404 trước khi tới tab nào cả.
    $this->actingAs($outsider, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertNotFound();
});

/**
 * TransitionMatterStage yêu cầu matter.transitionStage (SPEC §5); Assistant không có quyền này
 * (chỉ matter.view + matter.update), nên nút "Chuyển giai đoạn" (và "Thêm cập nhật" — cùng một
 * Action, cùng một điều kiện quyền, xem docblock TransitionMatterStage) phải ẩn với assistant, dù
 * assistant vẫn mở được trang vì có matter.view.
 */
it('hides the transition-stage button from an assistant but shows it to a lawyer on the team', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertTableActionHidden('transitionStage')
        ->assertTableActionHidden('addUpdate');

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertTableActionVisible('transitionStage')
        ->assertTableActionVisible('addUpdate');
});

/**
 * SPEC §4.18 / §7.2: dòng đã công bố mà khách đã xem hiện "Khách đã xem lúc …"; chưa xem hiện
 * "Khách chưa xem" và tô vàng nếu quá 5 ngày kể từ khi công bố.
 */
it('shows the viewed label once a client user has viewed a published stage log', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $log = StageLog::factory()->for($matter)->published()->create();
    StageLogView::factory()->for($log, 'stageLog')->create(['viewed_at' => '2026-09-14 21:14:00']);

    $this->actingAs($lawyer, 'web');

    $this->livewire(StageLogsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])
        ->assertSee('21:14')
        ->assertDontSee(__('matters.stage_log_fields.not_viewed'));
});

it('highlights the unread label once a published update has gone unread for more than five days', function () {
    expect(StageLogsRelationManager::readReceiptLabel(
        StageLog::factory()->published()->make(['published_at' => now()->subDays(6)])
    )['highlighted'])->toBeTrue();

    expect(StageLogsRelationManager::readReceiptLabel(
        StageLog::factory()->published()->make(['published_at' => now()->subDays(2)])
    )['highlighted'])->toBeFalse();
});

it('toggles portal publication only for someone with matter.update, and the switch actually flips', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionVisible('togglePortalPublication')
        ->callAction('togglePortalPublication');

    expect($matter->refresh()->is_published_to_portal)->toBeFalse();
});

/**
 * SPEC §6.10: "mỗi lần thêm một bên mới vào vụ việc đang chạy" phải chạy kiểm tra xung đột lợi
 * ích NGAY và mức đỏ phải chặn lưu (fix round 1, finding 1 — trước đó bên vẫn được lưu bất kể
 * mức, đúng lỗ hổng review chỉ ra). Kịch bản: một bị đơn mới trùng số căn cước với khách hàng
 * hiện hữu của vụ việc, đối lập vai (nguyên đơn/bị đơn) → mức đỏ.
 */
it('blocks a red conflict from saving a new party through the Các bên tab, and shows the result in place', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Người trùng căn cước',
    ])->identify('001099001234', null)->save();

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn mới',
        'id_number' => '001099001234',
    ])->assertHasTableActionErrors(['override_reason']);

    Notification::assertNotified(__('matters.parties.conflict_check_title_attention'));

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeFalse();
});

/** Cùng kịch bản đỏ ở trên, nhưng manager điền lý do ghi đè: bên phải được lưu (SPEC §6.10 bước 3). */
it('lets a manager override a red conflict with a reason and save the party', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Người trùng căn cước',
    ])->identify('001099001234', null)->save();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn mới',
        'id_number' => '001099001234',
        'override_reason' => 'Đã xác minh đây không phải cùng một người.',
    ])->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeTrue();
});

/**
 * Mức vàng (SPEC §11 bullet 3) không chặn vĩnh viễn nhưng đòi xác nhận: lần gửi đầu bị từ chối vì
 * chưa tích "đã xem xét", lần gửi thứ hai (cùng modal, cùng phiên Livewire) tích vào thì lưu được.
 */
it('requires acknowledgement for a yellow conflict, then saves the party once acknowledged', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $this->actingAs($lawyer, 'web');

    $livewire = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    // Tên trùng sau chuẩn hoá nhưng không có số căn cước/điện thoại nào để so khớp chắc chắn hơn
    // — chỉ lên vàng ("cần người xem xét", SPEC §6.10 bước 2).
    $livewire->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => '  Lê   THỊ hoa ',
    ])->assertHasTableActionErrors(['acknowledge_conflict']);

    expect($matter->parties()->where('name', '  Lê   THỊ hoa ')->exists())->toBeFalse();

    // Cùng modal còn đang mở (Filament giữ modal mở khi action ném lỗi form): sửa dữ liệu và gửi
    // lại action ĐANG MOUNTED, không mở một action 'create' mới — đúng luồng thật của người dùng.
    $livewire->setTableActionData([
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => '  Lê   THỊ hoa ',
        'acknowledge_conflict' => true,
    ])->callMountedTableAction()->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', '  Lê   THỊ hoa ')->exists())->toBeTrue();
});

/**
 * Fix round 1 finding 3: ô "Khách hàng" của form thêm bên không được liệt kê TOÀN BỘ khách hàng
 * văn phòng cho một lawyer chỉ có matter.update — chỉ khách hàng của những vụ việc họ đã liệt kê
 * được (Matter::listableBy), đúng ranh giới ClientPolicy::view. client.manage mới thấy toàn bộ.
 */
it('scopes the party form client picker to clients of matters the actor can already list', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $visibleClient = $matter->client;

    $strangerClient = Client::factory()->create();
    Matter::factory()->create(['client_id' => $strangerClient->id]); // vụ việc của một lawyer khác hẳn.

    $this->actingAs($lawyer, 'web');

    $options = VisibleClientOptions::forCurrentUser();

    expect($options)->toHaveKey($visibleClient->id)
        ->and($options)->not->toHaveKey($strangerClient->id);

    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->actingAs($manager, 'web');

    // client.manage (Manager) thấy toàn bộ, kể cả khách hàng "lạ" ở trên.
    expect(VisibleClientOptions::forCurrentUser())->toHaveKey($strangerClient->id);
});

/**
 * Fix round 2 finding A: đường THÀNH CÔNG (không có gì trùng) cũng phải hiện kết quả kiểm tra —
 * round 1 chỉ gọi notifyConflictCheckResult() ở hai catch, nên một lần thêm bên sạch không hiện
 * gì cả, khác hẳn brief gốc "chạy lại RunConflictCheck và hiện kết quả tại chỗ".
 */
it('shows a clear success notification when a party is added with no conflict', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bên hoàn toàn mới, không trùng ai',
        'id_number' => '000000000002',
    ])->assertHasNoTableActionErrors();

    // sentNotification() dùng session()->pull() bên trong (Filament\Notifications\Livewire\
    // Notifications::pullNotificationsFromSession()), tức là ĐỌC MỘT LẦN LÀ MẤT — gọi
    // Notification::assertNotified() trước đó cũng tiêu thụ session này, nên chỉ đọc đúng một
    // lần duy nhất ở đây, không gọi assertNotified() song song với nó trong cùng một test.
    $notification = sentNotification(__('matters.parties.conflict_check_title_clear'));
    expect($notification)->not->toBeNull()
        ->and($notification->getColor())->toBe('success')
        ->and($matter->parties()->where('name', 'Bên hoàn toàn mới, không trùng ai')->exists())->toBeTrue();
});

/**
 * Fix round 2 finding A, kịch bản chính review nêu: một manager ghi đè mức đỏ vẫn phải THẤY được
 * mình vừa ghi đè xung đột với hồ sơ nào — không chỉ lưu âm thầm.
 */
it('shows the conflicting matter code in the notification when a manager overrides a red conflict', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Người trùng căn cước',
    ])->identify('001099001234', null)->save();

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn mới',
        'id_number' => '001099001234',
        'override_reason' => 'Đã xác minh đây không phải cùng một người.',
    ])->assertHasNoTableActionErrors();

    $notification = sentNotification(__('matters.parties.conflict_check_title_attention'));
    expect($notification)->not->toBeNull()
        ->and($notification->getColor())->toBe('danger')
        ->and($notification->getBody())->toContain($otherMatter->code);
});

/**
 * Fix round 2 finding D: MatterPolicy::update() từ chối một vụ việc đã xoá mềm cho MỌI vai trò,
 * kể cả admin (khác view(), vốn cố ý cho admin xem vụ đã xoá mềm) — nên nút thêm bên phải ẩn với
 * admin trên một vụ việc đã xoá mềm, ba dòng đủ để chứng minh CreateAction->authorize() ở
 * PartiesRelationManager thật sự đọc đúng $matter->trashed(), không chỉ vai trò.
 */
it('hides the create-party action from an admin when the matter itself is soft-deleted', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter = Matter::factory()->create();
    $matter->delete();

    $this->actingAs($admin, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->assertTableActionHidden('create');
});
