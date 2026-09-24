<?php

use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Resources\Matters\Pages\CreateMatter;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\ClientRequestsRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Models\Client;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Exceptions\MethodNotFoundException;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Đội ngũ" (SPEC §4.7, §5, §7.2; M6.5 Task 3, R6) — trước task này không có màn hình nào
 * ghi vào `matter_user` ngoài `Matter::created()` (chỉ thêm lead), nên trợ lý và luật sư cộng sự
 * không bao giờ thấy được một vụ mở qua giao diện (finding `intake-01`/`roles-03`/`spec-gap-01`/
 * `e2e-F4`, critical). Mọi test ở đây đi qua Livewire (`callTableAction`, `mountTableAction`),
 * KHÔNG gọi thẳng `addTeamMember()`/`AddTeamMember` — brief Task 3 nói rõ 49 lời gọi
 * `addTeamMember()` trong test cũ đã che một luồng không tồn tại ngoài đời.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lead->id,
        'is_published_to_portal' => true,
    ]);
});

function teamTab(Matter $matter)
{
    return test()->livewire(TeamRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/**
 * Fix round 1, "also fix": `memberOptions()` không còn `public` (một phương thức public trên một
 * component Livewire là một điểm cuối GỌI ĐƯỢC TỪ XA — bất kỳ ai mở được tab này đều gọi thẳng nó
 * qua giao thức Livewire, bỏ qua hẳn ô `Select`, và đọc được id lẫn tên của MỌI nhân sự đủ điều
 * kiện). Test vẫn cần đo được danh sách thật (cùng lý lẽ ở docblock hàm) nên gọi qua
 * `ReflectionMethod`, đúng tiền lệ `ClientUserResourceTest.php` ("the create-page mutate hook
 * itself rejects...") đã dùng cho một hook `protected` khác.
 *
 * @return array<int, string>
 */
function memberOptionsFor($tab, ?string $role): array
{
    $method = new ReflectionMethod($tab->instance(), 'memberOptions');
    $method->setAccessible(true);

    return $method->invoke($tab->instance(), $role);
}

/**
 * Tiêu đề VÀ THÂN của mọi thông báo đã gửi, đọc đúng MỘT lần — cùng thành ngữ
 * `DocumentsRelationManagerTest::sentNotifications()` (tên hàm đổi để không trùng tên toàn cục
 * giữa hai tệp test).
 *
 * @return array<int, array{title: string|null, body: string}>
 */
function teamNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => (string) $notification->getBody(),
        ])
        ->all();
}

/**
 * Cổng của cả TAB: `MatterPolicy::view` trên vụ việc chủ — cùng thành ngữ
 * `DeadlinesRelationManager`/`ClientRequestsRelationManager`. Kế toán có `matter.viewAny` nhưng
 * không có `matter.view` (SPEC §5), nên tab không tồn tại cho họ dù họ thấy vụ việc trong danh
 * sách.
 */
it('exists only for someone who can open the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');
    expect(TeamRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();

    $this->actingAs($this->lead, 'web');
    expect(TeamRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();
});

/**
 * Bài học chính của brief Task 3: thêm một trợ lý qua ĐÚNG màn hình này phải mở ra được toàn bộ
 * những gì SPEC §1/§5 hứa cho vai trợ lý — không chỉ một dòng trong `matter_user`. Bốn khẳng
 * định: (1) thấy trong danh sách `ListMatters`, (2) mở được URL trực tiếp, (3) có mặt ở ô chọn
 * người phụ trách mốc hạn, (4) có mặt ở ô chọn người xử lý yêu cầu khách — hai ô đó đọc `team()`
 * trực tiếp (xem docblock `DeadlinesRelationManager::responsibleOptions()`), nên trước Task 3
 * không đường nào đưa được một trợ lý vào đó qua giao diện.
 */
it('adds an assistant through the tab, and from then on the assistant sees, opens, and can be picked for the matter', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);

    $this->actingAs($this->lead, 'web');

    teamTab($this->matter)->callTableAction('addMember', data: [
        'role_in_matter' => MatterRole::Assistant->value,
        'user_id' => $assistant->id,
    ])->assertHasNoTableActionErrors();

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue();

    $this->actingAs($assistant, 'web');

    $this->livewire(ListMatters::class)->assertCanSeeTableRecords([$this->matter]);

    $this->get(MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin'))->assertOk();

    $deadlinesOptions = test()->livewire(DeadlinesRelationManager::class, [
        'ownerRecord' => $this->matter,
        'pageClass' => ViewMatter::class,
    ])->instance()->responsibleOptions();

    $requestOptions = test()->livewire(ClientRequestsRelationManager::class, [
        'ownerRecord' => $this->matter,
        'pageClass' => ViewMatter::class,
    ])->instance()->assignableUsers();

    expect($deadlinesOptions)->toHaveKey($assistant->id)
        ->and($requestOptions)->toHaveKey($assistant->id);
});

it('lists members with their role and join date', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($this->lead, 'web');

    teamTab($this->matter)
        ->assertSee('Luật sư Vũ Khang')
        ->assertSee(MatterRole::Lead->label())
        ->assertSee('Trợ lý Mai')
        ->assertSee(MatterRole::Assistant->label());
});

/**
 * `memberOptions()` là chỗ DUY NHẤT đo được danh sách thật của ô chọn người (một `Select`
 * `native(false)` không in options vào HTML ban đầu) — cùng thành ngữ
 * `DeadlinesRelationManager::responsibleOptions()`. Đo cả ba lớp lọc trong một test: vai sai
 * (luật sư cho `assistant`), tài khoản đã nghỉ việc, và người ĐÃ trong đội ngũ.
 */
it('filters the member picker to active, eligible, not-yet-member staff for the chosen role', function () {
    $eligibleAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý hợp lệ']);
    $wrongRoleLawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư sai vai']);
    $retiredAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý đã nghỉ', 'is_active' => false]);
    $alreadyMember = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý đã ở trong đội']);
    $this->matter->addTeamMember($alreadyMember, MatterRole::Assistant);

    $this->actingAs($this->lead, 'web');

    $options = memberOptionsFor(teamTab($this->matter), MatterRole::Assistant->value);

    expect($options)->toHaveKey($eligibleAssistant->id)
        ->and($options)->not->toHaveKey($wrongRoleLawyer->id)
        ->and($options)->not->toHaveKey($retiredAssistant->id)
        ->and($options)->not->toHaveKey($alreadyMember->id);
});

/**
 * Fix round 1, "also fix" — `memberOptions()` không còn `public`. Một phương thức `public` trên
 * một component Livewire là một điểm cuối GỌI ĐƯỢC TỪ XA (`Livewire\Mechanisms\HandleComponents\
 * HandleComponents::callMethod()` chỉ nhận các tên nằm trong
 * `Utils::getPublicMethodsDefinedBySubClass()`), độc lập HOÀN TOÀN với việc ô `Select` có hiện
 * nó ra hay không — bất kỳ ai mở được tab này đều gửi thẳng một request Livewire gọi
 * `memberOptions('assistant')` và đọc về id lẫn tên của MỌI nhân sự đủ điều kiện, bỏ qua hẳn
 * giao diện. `->call()` của Livewire test helper đi qua ĐÚNG cơ chế đó (khác `->instance()->
 * memberOptions()` gọi thẳng PHP, bỏ qua toàn bộ tầng Livewire) — test này mô phỏng ĐÚNG cuộc
 * tấn công finding mô tả, không phải suy luận từ visibility của mã nguồn.
 */
it('cannot be called remotely as a public Livewire method', function () {
    $this->actingAs($this->lead, 'web');

    expect(fn () => teamTab($this->matter)->call('memberOptions', MatterRole::Assistant->value))
        ->toThrow(MethodNotFoundException::class);
});

/**
 * `role_in_matter` chưa chọn (ô Vai `live()` chưa có giá trị lúc render lần đầu) gửi `null` xuống
 * đây — `MatterRole::tryFrom(null ?? '')` trả `null`, và phải trả về mảng rỗng thay vì để
 * `AddTeamMember::eligibleForRole()` nhận một `MatterRole|null` khi nó chỉ nhận `MatterRole`
 * (TypeError, tức trang 500 ngay khi mở modal "Thêm thành viên"). `MatterRole::Lead` (giá trị
 * không có trong `options()` của ô Vai — xem `addMemberAction()`) cũng phải rỗng: không mời một
 * lựa chọn `AddTeamMember` sẽ luôn từ chối.
 */
it('returns no member options for an unchosen or a lead role', function () {
    // Phải có ÍT NHẤT một nhân sự hợp lệ, chưa trong đội ngũ trong CSDL — nếu không,
    // `User::query()->...->get()` trả về một collection rỗng và `->filter()` không bao giờ gọi
    // `eligibleForRole($user, $matterRole)`, nên xoá điều kiện null/Lead ở dưới sẽ KHÔNG làm test
    // này đỏ (đã đo: probe đầu tiên của điều kiện này cho kết quả dương giả cho tới khi dòng này
    // được thêm vào).
    User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($this->lead, 'web');

    $tab = teamTab($this->matter);

    expect(memberOptionsFor($tab, null))->toBe([])
        ->and(memberOptionsFor($tab, ''))->toBe([])
        ->and(memberOptionsFor($tab, MatterRole::Lead->value))->toBe([]);
});

/**
 * R6: gỡ một thành viên còn đứng tên mốc hạn chưa xong bị từ chối, và họ vẫn còn trong đội.
 *
 * Fix round 1, "also fix": lời từ chối phải LIỆT KÊ việc còn dở dang ("kèm danh sách") — không
 * chỉ dừng lại ở "không cho gỡ". `TeamMemberHasOpenWork::make()` đã ghép sẵn danh sách vào thân
 * thông báo (`OpenWorkResult::describe()`); test này đọc thân thông báo THẬT đã gửi, không chỉ
 * suy luận từ việc dữ liệu không đổi.
 */
it('refuses to remove an assistant still holding an unfinished deadline, and keeps them on the team', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Nộp đơn kháng cáo',
        'responsible_user_id' => $assistant->id,
        'is_completed' => false,
    ]);

    $this->actingAs($this->lead, 'web');

    teamTab($this->matter)->callTableAction('removeMember', $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeTrue()
        ->and($deadline->fresh()->responsible_user_id)->toBe($assistant->id);

    $notifications = teamNotifications();

    expect(array_column($notifications, 'title'))->toContain(__('actions.failed_title'))
        ->and(implode("\n", array_column($notifications, 'body')))->toContain('Nộp đơn kháng cáo');
});

it('removes an assistant with no open work and records it on the audit trail', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($this->lead, 'web');

    teamTab($this->matter)->callTableAction('removeMember', $assistant);

    expect($this->matter->team()->whereKey($assistant->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'team_member_removed')->count())->toBe(1);
});

/**
 * Vai `lead` chỉ đổi qua bàn giao vụ việc (M7, R6) — nút "Gỡ" không hiện trên chính dòng đó.
 */
it('never shows the remove button on the lead row', function () {
    $this->actingAs($this->lead, 'web');

    teamTab($this->matter)->assertTableActionHidden('removeMember', $this->lead);
});

/**
 * Fix round 1, finding S3: dòng lead bị ẩn cho MỌI actor có `manageTeam` — không chỉ với chính
 * lead. Dùng admin (chắc chắn qua được `manageTeam`) để tách RIÊNG điều kiện `->visible()` (ẩn
 * theo VAI của dòng) khỏi điều kiện `->authorize()` (đã có test riêng ở trên cho actor không đủ
 * quyền).
 */
it('blocks a forced removeMember call on the lead row even for an actor who can manage the team', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin, 'web');

    $tab = teamTab($this->matter);
    $tab->assertTableActionHidden('removeMember', $this->lead);

    expect(fn () => $tab->callTableAction('removeMember', $this->lead))
        ->toThrow(ExpectationFailedException::class);

    expect($this->matter->team()->whereKey($this->lead->id)->exists())->toBeTrue();
});

/**
 * Fix round 1, finding S2 (R5): "quản lý đội ngũ đòi `matter.update` VÀ không phải trợ lý."
 * `EditUser` → `assignRoleFromPosition()` là đường THẬT đổi vai — `matters.lead_lawyer_id`
 * không tự đổi theo, nên người này vẫn "là lead" trên hồ sơ cũ nhưng không còn được quản lý đội
 * ngũ của nó nữa.
 */
it('hides the add-member button and blocks the call once the lead has been demoted to assistant', function () {
    $this->actingAs($this->lead, 'web');
    teamTab($this->matter)->assertTableActionVisible('addMember');

    $this->lead->update(['position' => UserPosition::Assistant]);
    $this->lead->assignRoleFromPosition();

    $this->actingAs($this->lead->fresh(), 'web');

    $tab = teamTab($this->matter);
    $tab->assertTableActionHidden('addMember');

    $newHire = User::factory()->withRole(Role::Assistant)->create();

    expect(fn () => $tab->callTableAction('addMember', data: [
        'role_in_matter' => MatterRole::Assistant->value,
        'user_id' => $newHire->id,
    ]))->toThrow(ExpectationFailedException::class);

    expect($this->matter->team()->whereKey($newHire->id)->exists())->toBeFalse();
});

/** Cùng gotcha của nút "Thêm thành viên" (xem test kế), áp cho nút "Gỡ" trên một dòng không phải lead. */
it('hides the remove-member button from an assistant and blocks a forced call', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $other = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($other, MatterRole::Assistant);

    $this->actingAs($assistant, 'web');

    $tab = teamTab($this->matter);
    $tab->assertTableActionHidden('removeMember', $other);

    expect(fn () => $tab->callTableAction('removeMember', $other))
        ->toThrow(ExpectationFailedException::class);

    expect($this->matter->team()->whereKey($other->id)->exists())->toBeTrue();
});

/**
 * Filament gotcha (CLAUDE.md): trang/nút tự viết phải vừa ẨN vừa TỪ CHỐI cho người không có
 * quyền. `callTableAction()` của Filament tự hỏi `isVisible()` TRƯỚC khi gọi
 * (`Filament\Actions\Testing\TestsActions::callAction()`), nên ép gọi một action đã ẩn ném ra
 * đúng lỗi assertion đó — bằng chứng nút vừa ẨN vừa BỊ CHẶN thật sự khi bị gọi thẳng, không chỉ
 * vẽ khác trên màn hình.
 */
it('hides the add-member button from an assistant and blocks a forced call', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $newHire = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($assistant, 'web');

    $tab = teamTab($this->matter);
    $tab->assertTableActionHidden('addMember');

    expect(fn () => $tab->callTableAction('addMember', data: [
        'role_in_matter' => MatterRole::Assistant->value,
        'user_id' => $newHire->id,
    ]))->toThrow(ExpectationFailedException::class);

    expect($this->matter->team()->count())->toBe(2); // lead + trợ lý ban đầu, không thêm ai
});

/**
 * Fix round 1, finding S1 — bản trước gọi thẳng `app(OpenMatter::class)->handle(...)`, dù tên và
 * docblock nói "qua màn hình `CreateMatter` thật". Giờ đi ĐÚNG con đường một luật sư thật đi:
 * `Livewire::test(CreateMatter::class)->fillForm(...)->call('create')`.
 *
 * R6: luật sư A mở vụ việc rồi giao cho luật sư B phụ trách vẫn phải mở được vụ SAU KHI LƯU.
 */
it('lets the opener still open the matter after handing it to another lead through the real create screen', function () {
    $opener = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();

    // Khách hàng phải NẰM TRONG tầm nhìn của $opener trước — `VisibleClientOptions` chỉ liệt kê
    // khách của những vụ luật sư đã liệt kê được (cùng lý lẽ `clientVisibleTo()` của
    // `CreateMatterTest.php`, viết lại tại chỗ vì tên hàm toàn cục không dùng lại được giữa hai
    // tệp Pest — hai tệp khai báo cùng tên hàm sẽ lỗi nạp tệp).
    $client = Client::factory()->create();
    Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $opener->id]);

    $matterType = MatterType::factory()->withStages()->create();

    $this->actingAs($opener, 'web');

    $this->livewire(CreateMatter::class)
        ->fillForm([
            'client_id' => $client->id,
            'client_role' => PartyRole::Plaintiff->value,
            'matter_type_id' => $matterType->id,
            'title' => 'Tranh chấp hợp đồng thuê nhà — bàn giao ngay khi mở',
            'lead_lawyer_id' => $newLead->id,
            'summary_for_client' => 'Tóm tắt gửi khách hàng.',
            'other_parties' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->where('title', 'Tranh chấp hợp đồng thuê nhà — bàn giao ngay khi mở')->first();

    expect($matter)->not->toBeNull()
        ->and($matter->lead_lawyer_id)->toBe($newLead->id);

    $this->livewire(ListMatters::class)->assertCanSeeTableRecords([$matter]);

    $this->get(MatterResource::getUrl('view', ['record' => $matter], panel: 'admin'))->assertOk();
});

// =========================================================================================
// VỤ RESTRICTED — Review Focus 1, và fix round 1 finding I1
// =========================================================================================

/**
 * Fix round 1, finding I1 — vụ `restricted` chỉ lead và admin XEM được (`Matter::isListableBy()`,
 * không đọc `team()` chút nào cho nhánh này). Một trợ lý ĐỦ ĐIỀU KIỆN theo vai (assistant→
 * `assistant`) vẫn bị `AddTeamMember` từ chối vì họ sẽ KHÔNG BAO GIỜ thấy được vụ việc sau khi
 * thêm — trước bản sửa này, họ được thêm thành công rồi ăn 404 ngay lần mở tiếp theo. Chỉ một
 * ADMIN KHÁC (vai `observer`, vai duy nhất `eligibleForRole()` cho phép admin giữ) mới thêm được.
 */
it('lets the lead add another admin to a restricted matter, but refuses an assistant who would not see it', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $anotherAdmin = User::factory()->withRole(Role::Admin)->create();
    $newHire = User::factory()->withRole(Role::Assistant)->create();

    $this->actingAs($manager, 'web');

    teamTab($restricted)->assertTableActionHidden('addMember');

    $this->actingAs($this->lead, 'web');

    teamTab($restricted)->callTableAction('addMember', data: [
        'role_in_matter' => MatterRole::Observer->value,
        'user_id' => $anotherAdmin->id,
    ])->assertHasNoTableActionErrors();

    expect($restricted->team()->whereKey($anotherAdmin->id)->exists())->toBeTrue();

    teamTab($restricted)->callTableAction('addMember', data: [
        'role_in_matter' => MatterRole::Assistant->value,
        'user_id' => $newHire->id,
    ])->assertHasTableActionErrors(['user_id']);

    expect($restricted->team()->whereKey($newHire->id)->exists())->toBeFalse();
});

/**
 * Fix round 1, finding I1 — "The member picker must leave such people out." Vai `assistant` trên
 * một vụ `restricted` không có AI đủ điều kiện (một `Assistant` không phải admin, nên không bao
 * giờ qua được `Gate::allows('view', $matter)` của vụ hạn chế) — ô chọn phải trống, không mời một
 * lựa chọn `AddTeamMember` sẽ luôn từ chối.
 */
it('offers only staff who would see a restricted matter, for that matter', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $anotherAdmin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị viên khác']);
    $ineligibleAssistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý không thấy được vụ']);

    $this->actingAs($this->lead, 'web');

    $tab = teamTab($restricted);

    $observerOptions = memberOptionsFor($tab, MatterRole::Observer->value);

    expect($observerOptions)->toHaveKey($anotherAdmin->id)
        ->and($observerOptions)->not->toHaveKey($ineligibleAssistant->id)
        ->and(memberOptionsFor($tab, MatterRole::Assistant->value))->toBe([]);
});

/**
 * Review Focus 1: hiện trạng hiển thị theo `scopeListableBy()` KHÔNG đổi trong task này — một
 * manager không phải admin/lead vẫn không liệt kê được vụ `restricted`, y hệt trước khi
 * `manageTeam` tồn tại. `manageTeam` chỉ THÊM một cổng ghi mới, không nới ranh giới đọc cũ.
 */
it('leaves matter visibility on a restricted matter exactly as scopeListableBy defined it before this task', function () {
    $restricted = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->lead->id]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(Matter::query()->listableBy($manager)->whereKey($restricted->id)->exists())->toBeFalse()
        ->and(Matter::query()->listableBy($this->lead)->whereKey($restricted->id)->exists())->toBeTrue()
        ->and(Matter::query()->listableBy($admin)->whereKey($restricted->id)->exists())->toBeTrue();
});
