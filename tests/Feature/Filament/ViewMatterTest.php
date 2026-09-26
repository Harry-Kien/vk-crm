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
use App\Models\MatterParty;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * R5 (roles-05, M6.5 Task 10): trợ lý có `matter.update` nhưng không có `stageLog.publish` — bật
 * công tắc công bố cả vụ việc là "quyết định đưa gì ra cho khách" (xem
 * MatterPolicy::setPortalPublication()), nên nút này giờ ẩn với trợ lý, kể cả khi họ đứng trong
 * đội ngũ vụ việc.
 */
it('hides the toggle-portal-publication button from an assistant', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $this->livewire(ViewMatter::class, ['record' => $matter->getKey()])
        ->assertActionHidden('togglePortalPublication');
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

    // C-1: một mức đỏ bị chặn có tiêu đề RIÊNG. Nó không phải một lời nhắc "cần xem xét", nó là
    // một lời từ chối — và không có gì được lưu.
    Notification::assertNotified(__('matters.parties.conflict_blocked_title'));

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeFalse();
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

/**
 * I-5 (Important), nửa của tab "Các bên" — cùng bản sửa, cùng một hàm với `CreateMatter`.
 * `client_id` của bên mới được `VisibleClientOptions` giới hạn khi HIỂN THỊ nhưng chưa từng được
 * kiểm tra lại phía máy chủ, trong khi `BuildsMatterParties` lấy TÊN và định danh của một bên
 * `is_our_client` thẳng từ hồ sơ `Client` thật — nên một id giả mạo ghi TÊN THẬT của một khách
 * hàng ngoài tầm nhìn lên dòng bên này.
 *
 * Gọi thẳng `createParty()` có chủ đích: `Select::options()` của Filament tự cài một luật `in:`
 * dựng từ danh sách tuỳ chọn, nên qua đường form thì id giả mạo bị chặn ở bước xác thực và không
 * bao giờ tới được cổng dưới đây — một test đi qua modal sẽ xanh kể cả khi cổng bị xoá sạch. Đây
 * là lớp phòng thủ thứ hai, và nó chỉ có giá trị nếu có test đỏ được khi nó biến mất.
 */
it('refuses a forged client id on the add-party form, in the layer below Filaments own option rule', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $stranger = Client::factory()->create(['name' => 'KHACHHANGNGOAITAMNHIN']);

    $this->actingAs($lawyer, 'web');

    expect(VisibleClientOptions::forCurrentUser())->not->toHaveKey($stranger->id);

    $manager = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->instance();

    $createParty = Closure::bind(
        fn (array $data) => $this->createParty($data),
        $manager,
        PartiesRelationManager::class,
    );

    expect(fn () => $createParty([
        'role' => PartyRole::Related->value,
        'is_our_client' => true,
        'client_id' => $stranger->id,
        'name' => 'Tên do người gửi tự đặt',
    ]))->toThrow(NotFoundHttpException::class);

    expect($matter->parties()->count())->toBe(0)
        ->and(MatterParty::query()->where('name', 'KHACHHANGNGOAITAMNHIN')->exists())->toBeFalse();
});

/**
 * Toàn bộ giao diện là tiếng Việt qua `__()`/`lang/vi` (CLAUDE.md). Mục "Đội ngũ" của tab Tổng
 * quan là màn hình người dùng nhìn thấy NGAY SAU mỗi lần mở vụ việc, nên một nhãn tiếng Anh lọt
 * vào đây là thứ đập vào mắt đầu tiên.
 */
it('renders the team section of the matter page entirely in Vietnamese', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web')
        ->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))
        ->assertOk()
        ->assertSee(__('matters.overview_sections.team'))
        // Ba nhãn `hiddenLabel()` vẫn nằm trong DOM với lớp `fi-sr-only` — giấu khỏi mắt, KHÔNG
        // giấu khỏi trình đọc màn hình. Không có `label()` thì Filament tự suy ra từ tên thuộc
        // tính: "Team", "Name", "Role in matter".
        ->assertSee(__('matters.team_fields.members'))
        ->assertSee(__('matters.team_fields.name'))
        ->assertSee(__('matters.team_fields.role_in_matter'))
        ->assertDontSee('Role in matter');
});

/**
 * I-2 (Important, fix round 4), nửa MÀN HÌNH. Luật thật sự nằm ở `BuildsMatterParties` (nó đúng cả
 * với seeder/job/console — xem docblock trait đó); test này chỉ khoá chuyện người dùng được NGHE
 * luật đó bằng một lỗi gắn đúng ô, chứ không phải bằng một ngoại lệ nghiệp vụ dội lên giữa màn
 * hình — và khoá luôn rằng không có bên nào lọt vào bảng.
 */
it('will not add a party marked as our client without a client record', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Related->value,
        'is_our_client' => true,
        'client_id' => null,
        'name' => 'Tên gõ tay',
        'id_number' => '079012345678',
    ])->assertHasTableActionErrors(['client_id']);

    expect($matter->parties()->count())->toBe(0);
});

/**
 * Mọi Notification mà request vừa rồi đã gửi — cần CẢ màu, CẢ nội dung, CẢ SỐ LƯỢNG, thứ
 * `Notification::assertNotified()` không đọc được. Session này bị `pull()` (đọc một lần là mất),
 * nên mỗi test chỉ được gọi hàm này ĐÚNG MỘT LẦN, và không gọi kèm `assertNotified()`.
 *
 * @return Collection<int, Notification>
 */
function partyNotifications(): Collection
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications;
}

/**
 * Cặp vụ việc dựng sẵn một xung đột mức ĐỎ cho một bị đơn mang số căn cước 001099001234.
 *
 * @return array{0: Matter, 1: Matter}
 */
function matterWithRedConflictPair(User $actor): array
{
    $matter = Matter::factory()->create(['lead_lawyer_id' => $actor->id]);
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

    return [$matter, $otherMatter];
}

/**
 * Câu giải thích hiện NGAY DƯỚI một ô của form "thêm bên" đang mounted. `helperText()` của
 * Filament 5 không phải một thuộc tính đọc lại được: nó dựng một schema con `BELOW_CONTENT` chứa
 * một `Text` (xem `Forms\Components\Concerns\HasHelperText`), nên đọc đúng thứ người dùng thấy
 * có nghĩa là render schema con đó. `assertSee` không dùng được — thân modal của Filament 5 không
 * nằm trong HTML của lượt render này.
 */
function mountedPartyFieldHelperText(Testable $component, string $name): string
{
    /** @var PartiesRelationManager $instance */
    $instance = $component->instance();
    $schema = $instance->getSchema($instance->getMountedActionSchemaName());

    /** @var Field $field */
    $field = $schema->getFlatFields(withHidden: true)[$name];

    return (string) $field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function redConflictPartyData(array $overrides = []): array
{
    return [...[
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Bị đơn mới',
        'id_number' => '001099001234',
    ], ...$overrides];
}

/**
 * C-1 (CRITICAL, review vòng 3 → sửa vòng 4). Cùng khiếm khuyết đã sửa ở `CreateMatter`, còn
 * nguyên trên màn hình sinh đôi: `override_reason` ở tab "Các bên" hiện VÔ ĐIỀU KIỆN, nên một
 * manager điền lý do ngay LƯỢT GỬI ĐẦU đi thẳng vào nhánh ghi đè của `AddMatterParty` — bên được
 * lưu, `conflict_overridden: true` và lý do vào nhật ký vĩnh viễn, về một mức đỏ chưa ai từng thấy.
 *
 * Một lý do viết cho một xung đột chưa hiện ra không phải một quyết định.
 */
it('will not let a manager override a red conflict on the parties tab before it has been shown', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    [$matter] = matterWithRedConflictPair($manager);

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData([
        'override_reason' => 'Đã xác minh đây không phải cùng một người.',
    ]))->assertHasTableActionErrors(['override_reason']);

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();
});

/**
 * Nửa còn lại của C-1: hai ô quyết định chỉ TỒN TẠI sau khi một kết quả kiểm tra thật đã hiện ra.
 * Một trường `hidden` không được Filament dehydrate (`isDehydrated()` gọi
 * `isHiddenAndNotDehydratedWhenHidden()`, và `isDehydratedWhenHidden` mặc định `false`), nên đây
 * là cổng phía MÁY CHỦ chứ không phải trang trí. Với luật sư thì ô lý do hiện ra nhưng `disabled`
 * — `disabled()` gọi `saved(false)` nên nó cũng không dehydrate, đúng như ở `CreateMatter`.
 */
it('hides both decision fields on the parties tab until a conflict result exists, then disables the reason for a lawyer', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    [$matter] = matterWithRedConflictPair($lawyer);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->mountTableAction('create');

    $component->assertFormFieldHidden('override_reason')
        ->assertFormFieldHidden('acknowledge_conflict');

    $component->setTableActionData(redConflictPartyData())
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['override_reason']);

    $component->assertFormFieldVisible('override_reason')
        ->assertFormFieldDisabled('override_reason')
        ->assertFormFieldVisible('acknowledge_conflict');

    // Và câu giải thích đúng vai trò: đọc thẳng trên component thay vì `assertSee` trên HTML —
    // nội dung modal của Filament 5 không nằm trong phần thân đã render của lượt này.
    expect(mountedPartyFieldHelperText($component, 'override_reason'))
        ->toContain(__('matters.party_fields.override_reason_help_denied'));
});

/**
 * C-1, phần "màn hình phải nói thật". Sau khi một manager ghi đè ở lượt hai, thông báo phải: mang
 * màu `danger`, mang TIÊU ĐỀ RIÊNG của việc ghi đè (không phải "Cần xem xét trước khi lưu" — bên đã
 * lưu xong rồi, câu đó nói về một tương lai đã qua), liệt kê mã hồ sơ xung đột, và hiện lại nguyên
 * văn lý do vừa ghi vĩnh viễn vào nhật ký.
 */
it('tells the truth after a red conflict is overridden on the parties tab', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    [$matter, $otherMatter] = matterWithRedConflictPair($manager);
    $reason = 'Đã trao đổi với khách hàng, xác nhận đây không phải cùng một người.';

    $this->actingAs($manager, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['override_reason']);

    // Với manager thì ô lý do mở, và câu giải thích là câu của người ĐƯỢC ghi đè — nửa còn lại
    // của cặp helper text mà test luật sư ở trên ghim.
    $component->assertFormFieldEnabled('override_reason');
    expect(mountedPartyFieldHelperText($component, 'override_reason'))
        ->toContain(__('matters.party_fields.override_reason_help_allowed'));

    // Chỉ ô lý do, đúng như trong trình duyệt: mọi ô định danh khác đều `live()` và một thay đổi
    // thật ở đó sẽ (đúng thiết kế) làm quên kết quả kiểm tra đang hiện.
    $component->setTableActionData(['override_reason' => $reason])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeTrue();

    $notifications = partyNotifications();

    // ĐÚNG MỘT thông báo: thông báo "Đã tạo" mặc định của Filament bị tắt, nếu không nó chồng lên
    // đúng câu mà SPEC §6.10 bắt người dùng phải đọc (Minor 6, cùng bản sửa của `CreateMatter`).
    expect($notifications)->toHaveCount(1);

    $saved = $notifications->first();

    expect($saved)->not->toBeNull()
        ->and($saved->getTitle())->toBe(__('matters.parties.saved_overridden'))
        ->and($saved->getColor())->toBe('danger')
        ->and($saved->getBody())->toContain($otherMatter->code)
        ->and($saved->getBody())->toContain($reason)
        // Một dòng đã lưu xong không bao giờ được mô tả bằng câu "trước khi lưu".
        ->and($saved->getTitle())->not->toBe(__('matters.parties.conflict_check_title_attention'));
});

/**
 * Đường xác nhận (vàng): lưu được ở lượt hai, và thông báo sau khi lưu mang màu `warning` cùng một
 * tiêu đề nói rằng bên ĐÃ được thêm sau khi xem xét — không phải "cần xem xét trước khi lưu".
 */
it('says the party was added after review when a yellow conflict is acknowledged', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $otherMatter = Matter::factory()->create();
    $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Lê Thị Hoa',
    ]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: [
        'role' => PartyRole::Defendant->value,
        'is_our_client' => false,
        'name' => 'Lê   THỊ hoa',
    ])->assertHasTableActionErrors(['acknowledge_conflict']);

    $component->setTableActionData(['acknowledge_conflict' => true])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $saved = partyNotifications()->last();

    expect($saved->getTitle())->toBe(__('matters.parties.saved_after_review'))
        ->and($saved->getColor())->toBe('warning')
        ->and($saved->getBody())->toContain($otherMatter->code);
});

/**
 * Kết quả kiểm tra là một ẢNH CHỤP của dữ liệu lúc bấm lưu. Sửa một ô giữa hai lượt gửi thì nó
 * không còn mô tả đúng bên đang nhập nữa — và `$pendingConflictLevel` chỉ nhớ MỨC, nên một dấu
 * tích "đã xem xét" của lần kiểm tra CŨ sẽ được nhận cho một lần kiểm tra MỚI cùng mức. Cùng Minor
 * 3/4 đã sửa ở `CreateMatter`; ở đây phải cho ra cùng một kết quả, nếu không hai màn hình sinh đôi
 * lại lệch nhau lần thứ tư.
 */
it('forgets the stored conflict result when a party field is edited between two submits', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    [$matter] = matterWithRedConflictPair($lawyer);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['override_reason']);

    $component->assertFormFieldVisible('override_reason');

    // Sửa đúng ô sinh ra mức đỏ: kết quả đang giữ nói về một bên không còn tồn tại như thế nữa.
    $component->fillForm(
        redConflictPartyData(['id_number' => '000000000009']),
        $component->instance()->getMountedActionSchemaName(),
    );

    expect($component->instance()->conflictResult)->toBeNull();

    $component->assertFormFieldHidden('override_reason')
        ->assertFormFieldHidden('acknowledge_conflict');
});

/**
 * Modal bị bỏ dở rồi mở lại: lần mở mới phải bắt đầu từ con số không. Nếu không, hai ô quyết định
 * hiện sẵn ngay lượt gửi ĐẦU của một bên HOÀN TOÀN KHÁC, nói về kết quả kiểm tra của bên trước —
 * đúng lỗ hổng mà C-1 vừa đóng, chỉ đi vòng qua cửa sau.
 */
it('starts a freshly reopened add-party modal with no stored conflict result', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    [$matter] = matterWithRedConflictPair($manager);

    $this->actingAs($manager, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['override_reason']);

    expect($component->instance()->conflictResult)->not->toBeNull();

    $component->unmountTableAction()->mountTableAction('create');

    expect($component->instance()->conflictResult)->toBeNull();

    $component->assertFormFieldHidden('override_reason')
        ->assertFormFieldHidden('acknowledge_conflict');
});

/**
 * Bản sinh đôi của test cùng tên ở `CreateMatterTest` (Minor, fix round 4): một lý do ghi đè viết
 * cho kết quả kiểm tra NÀY không được sống sót sang kết quả kiểm tra KẾ TIẾP.
 */
it('clears a written override reason on the parties tab when the result it was written for is forgotten', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    [$matter] = matterWithRedConflictPair($manager);

    $this->actingAs($manager, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['override_reason']);

    $component->setTableActionData(['override_reason' => 'Lý do viết cho BẢNG ĐỎ THỨ NHẤT.']);

    $instance = $component->instance();
    // State path THẬT của form đang mở (`mountedActions.0.data`), không phải tên schema — đọc sai
    // chỗ thì `data_get` trả null và test xanh kể cả khi ô còn nguyên nội dung.
    $statePath = $instance->getSchema($instance->getMountedActionSchemaName())->getStatePath();

    expect(data_get($instance, "{$statePath}.override_reason"))->toBe('Lý do viết cho BẢNG ĐỎ THỨ NHẤT.');

    $instance->forgetConflictResult();

    expect(data_get($instance, "{$statePath}.override_reason"))->toBeNull();
});

/**
 * Nút "Tạo mới…" và tiêu đề modal của tab "Các bên" là hai chỗ đập vào mắt nhất của màn hình này.
 * Không đặt nhãn model thì Filament tự sinh từ tên lớp và cả hai đọc bằng tiếng Anh ("Tạo mới
 * matter party", "Tạo Matter Party") — đúng thứ CLAUDE.md cấm. Quan sát trực tiếp trên trình duyệt
 * trong lần kiểm tra tay của vòng này.
 */
it('names a party row in Vietnamese on the create button and the modal heading', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);

    $action = $component->instance()->getTable()->getAction('create');

    expect($action->getLabel())->toBe(__('matters.actions.add_party'))
        ->and($action->getModalHeading())->toBe(__('matters.actions.add_party_heading'))
        // Và nhãn model tiếng Việt vẫn phải có, cho những câu còn lại mà Filament tự dựng (trạng
        // thái bảng rỗng, thông báo…) — nếu không chúng quay về tên lớp.
        ->and(__('matters.party_label'))->not->toBe('matters.party_label');
});

/**
 * I-A (Important, review gộp nhánh M3). Cổng của `override_reason` hỏi "đã có kết quả kiểm tra
 * nào chưa?", không hỏi "đã có kết quả ĐỎ nào chưa?" — mà ô này `visible()` trên MỌI kết quả đã
 * lưu, kể cả VÀNG. Nên một manager viết lý do trong một vòng VÀNG, rồi mức leo lên ĐỎ trước lượt
 * gửi thứ hai (một lần thêm bên song song, hoặc một lần sửa hồ sơ `Client` kích hoạt
 * `SyncClientPartyIdentities` ghi lại `id_number_hash`): câu viết cho vòng vàng đó được mang
 * nguyên vào nhánh ghi đè của `AddMatterParty` — `isBlocking()` + manager + lý do khác rỗng ⟹ LƯU.
 * `conflict_overridden: true` và câu đó vào dòng nhật ký append-only vĩnh viễn, trong khi KHÔNG
 * một bảng ĐỎ nào từng được hiện ra.
 *
 * Kịch bản dựng thật, không khẳng định trên trạng thái nội bộ: khớp theo TÊN trần là vàng (§11),
 * rồi bên kia được bổ sung đúng số căn cước giữa hai lượt gửi nên lần kiểm tra kế tiếp trả về đỏ.
 */
it('will not honour an override reason written during a yellow round when the conflict escalates to red', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $manager->id]);
    $matter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Khách hàng hiện hữu',
    ]);

    // Vụ việc khác mang một bên LÀ khách hàng của văn phòng, TRÙNG TÊN với bên sắp thêm nhưng
    // CHƯA có số căn cước — khớp tầng tên nên trần của lượt một là VÀNG.
    $otherMatter = Matter::factory()->create();
    $twin = $otherMatter->parties()->create([
        'role' => PartyRole::Plaintiff,
        'is_our_client' => true,
        'name' => 'Bị đơn mới',
    ]);

    $this->actingAs($manager, 'web');

    $component = $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['acknowledge_conflict']);

    expect($component->instance()->conflictResult['level'])->toBe('yellow');

    // Giữa hai lượt gửi: hồ sơ bên kia được bổ sung đúng số căn cước của bên đang nhập, nên lần
    // kiểm tra KẾ TIẾP không còn là vàng nữa.
    $twin->identify('001099001234', null)->save();

    $component->setTableActionData(['override_reason' => 'Lý do viết cho một vòng VÀNG.'])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['override_reason']);

    expect($matter->parties()->where('name', 'Bị đơn mới')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'matter_party_added')->exists())->toBeFalse();
});

/**
 * I-B (Important, review gộp nhánh M3). Thân thông báo của tab "Các bên" liệt kê mã hồ sơ, loại vụ
 * việc, vai, tầng khớp và mức — nhưng KHÔNG tên của bên trùng, dù docblock của chính hàm đó tự
 * nhận là có. SPEC §6.10 "Đính chính 2026-09-16" nói thẳng vì sao cột đó không bỏ được: "không có
 * nó thì người dùng không có cách nào kiểm chứng hay phản bác kết quả." Đây là màn hình nơi một
 * manager ký một lý do ghi đè vĩnh viễn, nên họ phải thấy được mình đang ghi đè lên AI.
 */
it('names the matched party in the parties-tab conflict notification', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    [$matter, $otherMatter] = matterWithRedConflictPair($manager);

    $this->actingAs($manager, 'web');

    $this->livewire(PartiesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ])->callTableAction('create', data: redConflictPartyData())
        ->assertHasTableActionErrors(['override_reason']);

    $blocked = sentNotification(__('matters.parties.conflict_blocked_title'));

    expect($blocked)->not->toBeNull()
        ->and($blocked->getBody())->toContain($otherMatter->code)
        // Tên của bên trùng ở hồ sơ kia — thứ duy nhất cho người đọc biết họ đang bị chặn vì AI.
        ->and($blocked->getBody())->toContain('Người trùng căn cước');
});
