<?php

use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Mốc thời hạn" (SPEC §7.2) — **màn hình duy nhất trong hệ thống tạo ra một hàng
 * `deadlines`**, và vì thế là thứ quyết định `CheckDeadlines` của Task 6 có dữ liệu để chạy hay
 * chỉ xanh trên một cái bảng rỗng.
 *
 * Tệp này đo ba thứ: **ai nhìn thấy gì** (tab, và bốn nút chỉ hiện cho người ghi được vào vụ
 * việc), **màn hình gọi Action chứ không tự ghi** (mọi khẳng định về dữ liệu đi kèm một dòng
 * nhật ký mà chỉ Action sinh ra), và **một dòng quá hạn TRÔNG khác một dòng còn một tuần** —
 * bằng kiểu dáng viết thẳng, vì dự án không có bước dựng CSS.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Vũ Khang']);
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'is_published_to_portal' => true,
    ]);
});

function deadlinesTab(Matter $matter)
{
    return test()->livewire(DeadlinesRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

function makeDeadline(Matter $matter, array $attributes = []): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $matter->lead_lawyer_id,
        ...$attributes,
    ]);
}

// =========================================================================================
// DANH SÁCH — SPEC §7.2 "danh sách, thêm nhanh"
// =========================================================================================

it('lists the deadlines of the matter with the severity and who is responsible', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    makeDeadline($this->matter, [
        'name' => 'Nộp đơn kháng cáo',
        'severity' => DeadlineSeverity::Critical,
        'responsible_user_id' => $assistant->id,
    ]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->assertSee('Nộp đơn kháng cáo')
        ->assertSee(DeadlineSeverity::Critical->label())
        ->assertSee('Trợ lý Mai');
});

/**
 * Sắp theo ngày đến hạn, gần nhất trước — đó là toàn bộ việc của bảng này: thứ sắp hết giờ phải
 * nằm trên cùng.
 */
it('orders the list by due date, soonest first', function () {
    makeDeadline($this->matter, ['name' => 'Hạn xa', 'due_date' => today()->addDays(30)]);
    makeDeadline($this->matter, ['name' => 'Hạn gần', 'due_date' => today()->addDays(2)]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->assertCanSeeTableRecords(
        Deadline::query()->orderBy('due_date')->get(),
        inOrder: true,
    );
});

/**
 * **Test này đo QUAN HỆ, không đo `ScopesToVisibleMatters` — nói ra vì bản đầu của nó tưởng là
 * đang đo cái trait.** Truy vấn nền của một relation manager là `$matter->deadlines()`, nên một
 * mốc của hồ sơ khác không lọt vào đây kể cả khi gỡ sạch `modifyQueryUsing` — một mutation probe
 * xoá lời gọi trait đã để test này XANH. Cái trait thật sự làm gì thì test ngay dưới đo.
 *
 * Nó vẫn ở lại vì nó ghim đúng một thứ có thể vỡ: `protected static $relationship`. Vế dương
 * trong cùng test — mốc của chính vụ việc PHẢI hiện — vì một bảng rỗng cũng "không chứa" mọi thứ.
 */
it('never shows a deadline that belongs to another matter', function () {
    makeDeadline($this->matter, ['name' => 'Mốc của hồ sơ này']);
    $foreign = Matter::factory()->create();
    makeDeadline($foreign, ['name' => 'Mốc của hồ sơ khác']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->assertSee('Mốc của hồ sơ này')
        ->assertDontSee('Mốc của hồ sơ khác');
});

/**
 * Đây mới là chỗ `ScopesToVisibleMatters` có việc thật: nó hỏi vụ việc CHỦ có nằm trong tầm nhìn
 * của người đang xem không (`Matter::scopeListableBy`, SPEC §5).
 *
 * **Người đóng vai là TRƯỞNG PHÒNG, và đó là cả điểm của test.** Trưởng phòng có `matter.viewAny`
 * (`Role::permissions()`), nên `scopeListableBy` không ràng buộc gì với họ trên một vụ việc
 * thường — vế dương bên dưới. Một vụ việc `restricted` (SPEC §4.6) thì khép lại với họ, và đó là
 * ca duy nhất trên màn hình này mà lớp lọc hàng đổi được câu trả lời cho MỘT người: cùng một
 * người, cùng một hồ sơ, hai câu trả lời khác nhau vì đúng một cột.
 *
 * Lớp này KHÔNG phải cổng của tab (cổng là `canViewForRecord()`, test riêng ở trên); nó là lớp
 * thứ hai, và nó tồn tại vì một cổng duy nhất nằm ở một tầng khác là đúng hình dạng mà vòng rà
 * soát M4 đã lên án.
 */
it('empties the table for a manager once the owner matter turns restricted', function () {
    makeDeadline($this->matter, ['name' => 'Mốc trong hồ sơ hạn chế']);

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');
    deadlinesTab($this->matter)->assertSee('Mốc trong hồ sơ hạn chế');

    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);
    deadlinesTab($this->matter)->assertDontSee('Mốc trong hồ sơ hạn chế');
});

/**
 * Cổng của cả TAB: `MatterPolicy::view` trên vụ việc chủ. Kế toán có `matter.viewAny` nhưng
 * không có `matter.view` (SPEC §5), nên tab không tồn tại cho họ.
 */
it('exists only for someone who can open the matter', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    $this->actingAs($accountant, 'web');
    expect(DeadlinesRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeFalse();

    $this->actingAs($this->lawyer, 'web');
    expect(DeadlinesRelationManager::canViewForRecord($this->matter, ViewMatter::class))->toBeTrue();
});

// =========================================================================================
// THÊM NHANH — hai ô bắt buộc, ba mặc định
// =========================================================================================

it('adds a deadline from two fields, defaulting the responsible person, the severity and the publication switch', function () {
    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('create', data: [
        'name' => 'Phiên hoà giải lần 2',
        'due_date' => today()->addDays(6)->toDateString(),
    ])->assertHasNoTableActionErrors();

    $deadline = Deadline::query()->firstWhere('name', 'Phiên hoà giải lần 2');

    expect($deadline)->not->toBeNull()
        ->and($deadline->responsible_user_id)->toBe($this->lawyer->id)
        ->and($deadline->severity)->toBe(DeadlineSeverity::Normal)
        ->and($deadline->is_published)->toBeFalse()
        ->and($deadline->created_by)->toBe($this->lawyer->id);

    // Chỉ Action ghi dòng này: một `$record->update()` trong màn hình sẽ để khẳng định dưới đây đỏ.
    expect(Activity::query()->where('event', 'deadline_added')->count())->toBe(1);
});

it('lets the lawyer set the severity and the responsible person while adding', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('create', data: [
        'name' => 'Hạn kháng nghị',
        'due_date' => today()->addDays(4)->toDateString(),
        'severity' => DeadlineSeverity::Critical->value,
        'responsible_user_id' => $assistant->id,
    ])->assertHasNoTableActionErrors();

    $deadline = Deadline::query()->firstWhere('name', 'Hạn kháng nghị');

    expect($deadline->severity)->toBe(DeadlineSeverity::Critical)
        ->and($deadline->responsible_user_id)->toBe($assistant->id);
});

it('publishes a new deadline to the client when the switch is on', function () {
    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('create', data: [
        'name' => 'Phiên toà sơ thẩm',
        'due_date' => today()->addDays(20)->toDateString(),
        'is_published' => true,
    ])->assertHasNoTableActionErrors();

    expect(Deadline::query()->firstWhere('name', 'Phiên toà sơ thẩm')->is_published)->toBeTrue();
});

/**
 * Công tắc công bố bị KHOÁ khi vụ việc chưa lên cổng — cùng thành ngữ
 * `BuildsStageUpdateSchema::publishToggleField()` dùng cho dòng tiến độ, và cùng lý do: một công
 * tắc bật được rồi mới nhận về một lời từ chối là một lời hứa sai. Một trường `disabled()` KHÔNG
 * được Filament dehydrate, nên đây là cổng phía MÁY CHỦ chứ không phải trang trí — test gửi
 * thẳng `is_published = true` lên và mốc vẫn được tạo, chưa công bố.
 */
it('cannot publish a new deadline while the matter itself is not on the portal', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('create', data: [
        'name' => 'Mốc nội bộ',
        'due_date' => today()->addDays(9)->toDateString(),
        'is_published' => true,
    ])->assertHasNoTableActionErrors();

    $deadline = Deadline::query()->firstWhere('name', 'Mốc nội bộ');

    // Vế dương: mốc VẪN được lưu. Khoá công tắc không được biến một lần thêm nhanh hợp lệ thành
    // một lần từ chối — nó chỉ bỏ đúng cái cờ mà vụ việc này chưa mang nổi.
    expect($deadline)->not->toBeNull()
        ->and($deadline->is_published)->toBeFalse();
});

it('refuses an empty name with an error on the field, and saves nothing', function () {
    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('create', data: [
        'name' => '',
        'due_date' => today()->addDays(3)->toDateString(),
    ])->assertHasTableActionErrors(['name']);

    expect(Deadline::query()->count())->toBe(0);
});

/**
 * Ô chọn người phụ trách là một tiện ích, không phải cổng — nhưng nó không được mời người ta
 * chọn một cái bẫy. Options của một `Select` `native(false)` không đi vào HTML, nên đây là chỗ
 * duy nhất đo được danh sách thật (cùng lý do `ClientRequestsRelationManager::assignableUsers()`
 * công khai).
 */
it('offers only the active members of the matter team as the responsible person', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $retired = User::factory()->withRole(Role::Assistant)->create(['name' => 'Người đã nghỉ', 'is_active' => false]);
    $this->matter->addTeamMember($retired, MatterRole::Assistant);

    $outsider = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Người ngoài đội']);

    $this->actingAs($this->lawyer, 'web');

    $options = deadlinesTab($this->matter)->instance()->responsibleOptions();

    expect($options)->toHaveKey($this->lawyer->id)
        ->and($options)->toHaveKey($assistant->id)
        ->and($options)->not->toHaveKey($retired->id)
        ->and($options)->not->toHaveKey($outsider->id);
});

it('hides the add button from someone who cannot write to the matter', function () {
    $this->actingAs($this->lawyer, 'web');
    deadlinesTab($this->matter)->assertTableActionVisible('create');

    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');
    deadlinesTab($this->matter)->assertTableActionHidden('create');
});

// =========================================================================================
// ĐỔI NGƯỜI PHỤ TRÁCH (fix round 1, CRITICAL) — trước bản sửa này không màn hình nào đổi được
// `responsible_user_id` sau khi tạo, nên một người KHÔNG phải lead còn đứng tên một mốc chưa
// xong không bao giờ nghỉ việc được: `ReassignMatter` chỉ chuyển việc của LEAD, và cột này không
// có đường ghi nào khác ngoài lúc tạo mốc. `App\Actions\Deadline\ChangeDeadlineResponsible` là
// đường ghi thứ hai; test Action-tier riêng (`tests/Feature/Actions/Deadline/
// ChangeDeadlineResponsibleTest.php`) đo luật của chính Action.
// =========================================================================================

it('changes the responsible person through the changeResponsible action, and writes an audit entry', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Mai']);
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeDeadline($this->matter, ['name' => 'Nộp đơn kháng cáo', 'responsible_user_id' => $assistant->id]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('changeResponsible', $deadline, data: [
        'responsible_user_id' => $this->lawyer->id,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id)
        ->and(Activity::query()->where('event', 'deadline_responsible_changed')
            ->where('properties->from', $assistant->id)
            ->where('properties->to', $this->lawyer->id)
            ->exists())->toBeTrue();
});

it('hides the change-responsible button from someone who cannot write to the matter', function () {
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');
    deadlinesTab($this->matter)->assertTableActionVisible('changeResponsible', $deadline);

    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');
    deadlinesTab($this->matter)->assertTableActionHidden('changeResponsible', $deadline);
});

/**
 * Minor (fix round 2): một mốc ĐÃ HOÀN THÀNH không còn "việc" nào để đổi người phụ trách nữa —
 * ẩn hẳn nút, cùng chỗ `ChangeDeadlineResponsible::handle()` tự chặn ở tầng Action (test Action-tier
 * riêng đo lớp bên dưới này, cùng thành ngữ mọi cặp UI-ẩn/Action-tự-chặn khác trong dự án).
 */
it('hides the change-responsible button on a completed deadline', function () {
    $deadline = makeDeadline($this->matter, ['is_completed' => true, 'completed_at' => now()]);

    $this->actingAs($this->lawyer, 'web');
    deadlinesTab($this->matter)->assertTableActionHidden('changeResponsible', $deadline);
});

// =========================================================================================
// ĐÁNH DẤU HOÀN THÀNH, VÀ ĐƯỜNG LÙI
// =========================================================================================

it('marks a deadline complete through the action', function () {
    $deadline = makeDeadline($this->matter, ['name' => 'Nộp đơn kháng cáo']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('complete', $deadline);

    expect($deadline->fresh()->is_completed)->toBeTrue()
        ->and($deadline->fresh()->completed_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'deadline_completion_set')->count())->toBe(1);
});

it('swaps the complete button for the reopen button once a deadline is done', function () {
    $open = makeDeadline($this->matter, ['name' => 'Còn mở']);
    $done = makeDeadline($this->matter, ['name' => 'Đã xong', 'is_completed' => true, 'completed_at' => now()]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->assertTableActionVisible('complete', $open)
        ->assertTableActionHidden('reopen', $open)
        ->assertTableActionHidden('complete', $done)
        ->assertTableActionVisible('reopen', $done);
});

it('reopens a deadline through the action', function () {
    $deadline = makeDeadline($this->matter, ['is_completed' => true, 'completed_at' => now()]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline);

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->completed_at)->toBeNull();
});

// =========================================================================================
// CÔNG TẮC CÔNG BỐ CHO KHÁCH
// =========================================================================================

it('publishes and unpublishes a deadline through the actions', function () {
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('publish', $deadline);
    expect($deadline->fresh()->is_published)->toBeTrue();

    deadlinesTab($this->matter)->callTableAction('unpublish', $deadline);
    expect($deadline->fresh()->is_published)->toBeFalse();
});

it('swaps the publish button for the unpublish button once a deadline is on the portal', function () {
    $private = makeDeadline($this->matter, ['name' => 'Chỉ nội bộ']);
    $shared = makeDeadline($this->matter, ['name' => 'Đã gửi khách', 'is_published' => true]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->assertTableActionVisible('publish', $private)
        ->assertTableActionHidden('unpublish', $private)
        ->assertTableActionHidden('publish', $shared)
        ->assertTableActionVisible('unpublish', $shared);
});

it('hides every write button from someone who cannot write to the matter', function () {
    $deadline = makeDeadline($this->matter, ['is_published' => true]);

    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');

    deadlinesTab($this->matter)
        ->assertTableActionHidden('complete', $deadline)
        ->assertTableActionHidden('reopen', $deadline)
        ->assertTableActionHidden('publish', $deadline)
        ->assertTableActionHidden('unpublish', $deadline);
});

/**
 * R5 (roles-05, M6.5 Task 10): trợ lý có `matter.update` (đổi tên, ngày, người phụ trách…) nhưng
 * không có `stageLog.publish` — công bố/gỡ một mốc hạn cho khách là "quyết định đưa gì ra cho
 * khách", cùng loại quyết định với SetMatterPortalPublication (xem DeadlinePolicy::publish()).
 * Nút "changeResponsible"/"complete" vẫn hiện (không đổi bởi task này) — chỉ hai nút công bố ẩn.
 */
it('hides only the publish/unpublish buttons from an assistant, keeping the other write buttons', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $private = makeDeadline($this->matter, ['name' => 'Chỉ nội bộ']);
    $shared = makeDeadline($this->matter, ['name' => 'Đã gửi khách', 'is_published' => true]);

    $this->actingAs($assistant, 'web');

    deadlinesTab($this->matter)
        ->assertTableActionHidden('publish', $private)
        ->assertTableActionHidden('unpublish', $shared)
        ->assertTableActionVisible('complete', $private)
        ->assertTableActionVisible('changeResponsible', $private);
});

/**
 * **Cuộc đua có thật, không phải một ca dựng.** Hai tab đang mở: một người rút hồ sơ khỏi cổng
 * khách trong lúc người kia đang nhìn cái nút "Gửi cho khách" đã vẽ ra từ trước. Lời từ chối của
 * Action (`MatterNotPublishedToPortal`, một `DomainException`) phải tới mắt người dùng bằng tiếng
 * Việt qua `ReportsActionFailures` — không thành một trang 500, và tuyệt đối không thành một mã
 * HTTP riêng (SPEC §10.10).
 */
it('shows an Action refusal as a Vietnamese notification instead of a 500', function () {
    $deadline = makeDeadline($this->matter, ['name' => 'Phiên hoà giải']);

    $this->actingAs($this->lawyer, 'web');

    $tab = deadlinesTab($this->matter);

    $this->matter->update(['is_published_to_portal' => false]);

    $tab->callTableAction('publish', $deadline);

    Notification::assertNotified(__('actions.failed_title'));

    expect($deadline->fresh()->is_published)->toBeFalse();
});

// =========================================================================================
// QUÁ HẠN TÔ ĐỎ, SẮP ĐẾN HẠN TÔ VÀNG — và cả hai phải thật sự tô
// =========================================================================================

/**
 * Cùng phép đo `StageLogPaintingTest` dựng: dự án không có bước dựng CSS (CLAUDE.md), nên một lớp
 * tiện ích Tailwind viết tay tô ra đúng số không, và một biến màu gõ nhầm (`--grey-600`,
 * `--danger-650`) cho `var()` rỗng — cũng đúng số không. Hai lỗi ấy đã sống hai milestone ở M3
 * mà không ai nhìn thấy.
 */
it('paints the due date with registered colour variables only, and emits no CSS class at all', function () {
    $markup = collect([
        makeDeadline($this->matter, ['due_date' => today()->subDays(3)]),
        makeDeadline($this->matter, ['due_date' => today()->addDays(2)]),
        makeDeadline($this->matter, ['due_date' => today()->addDays(40)]),
        makeDeadline($this->matter, ['is_completed' => true, 'completed_at' => now()]),
    ])->map(fn (Deadline $deadline): string => (string) DeadlinesRelationManager::renderDueDate($deadline))
        ->implode('');

    // Không một thuộc tính `class` nào: đó là quyết định của một dự án không có bước dựng CSS,
    // phát biểu thành một khẳng định thay vì thành một bộ lọc rỗng — cùng bài học
    // `StageLogPaintingTest` rút ra khi bản trước của nó lọc một danh sách rỗng và xanh mà không
    // đọc tới một byte markup nào. (`classTokens()` của tệp đó là hàm cục bộ của tệp đó; ở đây
    // khẳng định được viết thẳng thay vì dựng một hàm trùng tên, thứ sẽ làm PHP chết khi cả hai
    // tệp cùng được nạp.)
    expect($markup)->not->toContain('class=')
        ->and(colourVariablesIn($markup))->not->toBeEmpty()
        ->and(unregisteredColourVariables($markup))->toBe([]);
});

it('paints an overdue deadline red and a deadline due this week amber', function () {
    $overdue = (string) DeadlinesRelationManager::renderDueDate(
        makeDeadline($this->matter, ['due_date' => today()->subDays(3)])
    );
    $dueSoon = (string) DeadlinesRelationManager::renderDueDate(
        makeDeadline($this->matter, ['due_date' => today()->addDays(2)])
    );
    $far = (string) DeadlinesRelationManager::renderDueDate(
        makeDeadline($this->matter, ['due_date' => today()->addDays(40)])
    );

    expect($overdue)->toContain('var(--danger-600)')
        ->and($overdue)->toContain(__('deadlines.tab.timing.overdue', ['count' => 3]))
        ->and($dueSoon)->toContain('var(--warning-600)')
        ->and($dueSoon)->toContain(__('deadlines.tab.timing.due_in_days', ['count' => 2]))
        ->and($far)->not->toContain('var(--danger-600)')
        ->and($far)->not->toContain('var(--warning-600)');
});

/**
 * Một mốc ĐÃ XONG thì thôi kêu, kể cả khi ngày của nó đã trôi qua: màu đỏ trên màn hình này là
 * một lời gọi hành động, và một dòng đỏ không còn việc gì để làm dạy người ta bỏ qua màu đỏ.
 */
it('stops painting a completed deadline red even when its date has passed', function () {
    $html = (string) DeadlinesRelationManager::renderDueDate(makeDeadline($this->matter, [
        'due_date' => today()->subDays(10),
        'is_completed' => true,
        'completed_at' => now(),
    ]));

    expect($html)->not->toContain('var(--danger-600)')
        ->and($html)->toContain(__('deadlines.tab.timing.done'));
});

it('says the date is today or tomorrow in words rather than in a day count', function () {
    $today = (string) DeadlinesRelationManager::renderDueDate(makeDeadline($this->matter, ['due_date' => today()]));
    $tomorrow = (string) DeadlinesRelationManager::renderDueDate(makeDeadline($this->matter, ['due_date' => today()->addDay()]));

    expect($today)->toContain(__('deadlines.tab.timing.due_today'))
        ->and($today)->toContain('var(--danger-600)')
        ->and($tomorrow)->toContain(__('deadlines.tab.timing.due_tomorrow'))
        ->and($tomorrow)->toContain('var(--warning-600)');
});
