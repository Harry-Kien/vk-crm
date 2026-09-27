<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Widgets\UpcomingDeadlinesWidget;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
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
// SỬA (M6.5 Task 14, `deadlines/F7`) — trước bản sửa này không màn hình nào sửa được tên, ngày
// hay mức độ của một mốc đã tạo; cách lách duy nhất là đánh dấu "hoàn thành" sai sự thật rồi thêm
// mốc mới.
// =========================================================================================

it('edits the name, due date and severity of a deadline, and writes an audit entry', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Phiên toà sơ thẩm',
        'due_date' => today()->addDays(2),
        'severity' => DeadlineSeverity::Normal,
    ]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => 'Phiên toà sơ thẩm (hoãn)',
        'due_date' => today()->addDays(20)->toDateString(),
        'severity' => DeadlineSeverity::Critical->value,
    ])->assertHasNoTableActionErrors();

    $fresh = $deadline->fresh();

    expect($fresh->name)->toBe('Phiên toà sơ thẩm (hoãn)')
        ->and($fresh->due_date->toDateString())->toBe(today()->addDays(20)->toDateString())
        ->and($fresh->severity)->toBe(DeadlineSeverity::Critical)
        ->and(Activity::query()->where('event', 'deadline_updated')->count())->toBe(1);
});

/**
 * Hoãn phiên toà: sửa ngày từ còn 2 ngày (đã gửi bậc d7 và d3 — xem `CheckDeadlines::passedTiers()`)
 * sang còn 20 ngày. Ngày mới CHƯA TỚI cả ba bậc cũ (`d3`, `d7`, `d14` — mốc `critical`), nên cả ba
 * đều bị dọn; `CheckDeadlines` chạy lại đúng lúc còn 14 ngày sẽ nhắc bậc đó như một mốc mới, không
 * bị khoá chống trùng của ngày CŨ chặn lại.
 */
it('clears every reminder tier the new due date has not reached yet, so CheckDeadlines reminds again when it comes due', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Phiên toà sơ thẩm',
        'due_date' => today()->addDays(2),
        'severity' => DeadlineSeverity::Critical,
        'reminders_sent' => [],
    ]);

    Mail::fake();
    (new CheckDeadlines)->handle();
    // Mốc critical: bậc hiện tại là d3 (2 ≤ 3), và các bậc XA HƠN (d7, d14) đã trôi qua chưa gửi
    // nên bị đánh dấu "đã gửi" cùng lượt — xem `CheckDeadlines::passedTiers()`.
    expect($deadline->fresh()->reminders_sent)->toEqualCanonicalizing(['d7', 'd3', 'd14']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => $deadline->name,
        'due_date' => today()->addDays(20)->toDateString(),
        'severity' => DeadlineSeverity::Critical->value,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->reminders_sent)->toBe([]);

    // Mail::fake() lại: đếm lại từ 0, để lần gửi TRƯỚC lần sửa (bậc d3) không lẫn vào phép đếm
    // của lần gửi SAU đây.
    Mail::fake();
    $this->travelTo(today()->addDays(6));
    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
    expect($deadline->fresh()->reminders_sent)->toContain('d14');
});

/**
 * Cặp dương/âm dựng trên MỘT lượt sửa: dời hạn từ còn 2 ngày (đã gửi d7+d3) sang còn 5 ngày.
 * `d7` VẪN "đã tới" (5 ≤ 7) nên GIỮ NGUYÊN; `d3` "chưa tới" (5 > 3) nên bị XOÁ. Một điều kiện
 * "xoá tất cả không phân biệt" sẽ làm vế GIỮ đỏ; một điều kiện "không xoá gì cả" sẽ làm vế XOÁ đỏ
 * — cùng test bắt được cả hai hướng hỏng.
 *
 * Mutation probe (đã chạy tay, khôi phục sau khi dán bằng chứng vào báo cáo):
 *  - Xoá tất cả không điều kiện (`clearedReminders()` luôn trả `[]`): vế "GIỮ d7" đỏ.
 *  - Không xoá gì (`clearedReminders()` luôn trả nguyên `$sent`): vế "XOÁ d3" đỏ.
 */
it('keeps a tier the new due date has already reached, while clearing one it has not', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Phiên hoà giải',
        'due_date' => today()->addDays(2),
        'severity' => DeadlineSeverity::Normal,
        'reminders_sent' => [],
    ]);

    Mail::fake();
    (new CheckDeadlines)->handle();
    expect($deadline->fresh()->reminders_sent)->toEqualCanonicalizing(['d7', 'd3']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => $deadline->name,
        'due_date' => today()->addDays(5)->toDateString(),
        'severity' => DeadlineSeverity::Normal->value,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->reminders_sent)->toBe(['d7'])
        ->and($deadline->fresh()->reminders_sent)->not->toContain('d3');
});

/**
 * Khoá `overdue` có luật riêng: giữ khi ngày mới VẪN đã qua, xoá khi ngày mới về lại tương lai —
 * nếu không, một mốc quá hạn được dời ra sau sẽ không bao giờ cảnh báo quá hạn lần nữa khi nó trễ
 * lần hai. Hai vế trên cùng một mốc, hai lần sửa.
 */
it('keeps the overdue mark while the new date is still past, and clears it once the date moves to the future', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Nộp tạm ứng án phí',
        'due_date' => today()->subDays(3),
        'reminders_sent' => [],
    ]);

    Mail::fake();
    (new CheckDeadlines)->handle();
    expect($deadline->fresh()->reminders_sent)->toContain('overdue');

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'due_date' => today()->subDay()->toDateString(),
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->reminders_sent)->toContain('overdue');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'due_date' => today()->addDays(10)->toDateString(),
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->reminders_sent)->toBe([]);
});

/**
 * Sửa chỉ tên (ngày gửi lại y hệt cũ): `reminders_sent` không bị đụng tới.
 *
 * `reminders_sent` mang `d1` dù ngày còn 10 — một trạng thái không tự nhiên phát sinh qua luồng
 * thật, dựng riêng để lộ đúng điều kiện đang test: nếu Action tính lại `clearedReminders()` bất kể
 * ngày có đổi hay không, `d1` sẽ bị dọn (10 > 1, "chưa tới") NGAY CẢ KHI `due_date` gửi lên y hệt
 * cũ. Một mutation probe xoá điều kiện `$dueDateChanged` (luôn tính lại) làm đúng khẳng định dưới
 * đây đỏ — xem báo cáo.
 */
it('leaves reminders_sent alone when the due date is resubmitted unchanged', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Phiên hoà giải',
        'due_date' => today()->addDays(10),
        'reminders_sent' => ['d1'],
    ]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => 'Phiên hoà giải (đổi tên)',
        'due_date' => today()->addDays(10)->toDateString(),
        'severity' => DeadlineSeverity::Normal->value,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->reminders_sent)->toBe(['d1'])
        ->and($deadline->fresh()->name)->toBe('Phiên hoà giải (đổi tên)');
});

it('refuses an empty name on edit with an error on the field', function () {
    $deadline = makeDeadline($this->matter, ['name' => 'Tên gốc']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'name' => '',
        'due_date' => today()->addDays(3)->toDateString(),
        'severity' => DeadlineSeverity::Normal->value,
    ])->assertHasTableActionErrors(['name']);

    expect($deadline->fresh()->name)->toBe('Tên gốc');
});

/**
 * `deadlines/F7` nêu cả "người phụ trách" trong những thứ không sửa được. Ô chọn trong form "Sửa"
 * bày ra đúng `responsibleOptions()` (cùng danh sách form "thêm nhanh" và nút "Đổi người phụ
 * trách"); cổng thật là `ChecksDeadlineHolder::canHoldDeadline()` trong `UpdateDeadline` — xem
 * `UpdateDeadlineTest`.
 */
it('hands a deadline to another team member through the edit form', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = makeDeadline($this->matter, ['name' => 'Nộp bản tự khai']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'responsible_user_id' => $assistant->id,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($assistant->id)
        ->and($deadline->fresh()->name)->toBe('Nộp bản tự khai');
});

/** Trưởng phòng xem được vụ nhưng không ở trong đội ngũ, nên không có trong danh sách — form không nhận id đó. */
it('refuses, on the edit form, a responsible person the form does not offer', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'responsible_user_id' => $manager->id,
    ])->assertHasTableActionErrors(['responsible_user_id']);

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/**
 * Người giữ mốc đã bị vô hiệu hoá: ô chọn KHÔNG điền sẵn tên họ (một giá trị ngoài danh sách sẽ
 * bị luật `in:` chặn với một câu lỗi về thứ người dùng chưa từng chọn — cùng cái bẫy mà ô mặc định
 * của form "thêm nhanh" đã ghi lại), và bắt người sửa chọn một người còn giữ được mốc.
 */
it('asks for a new holder when editing a deadline whose holder no longer qualifies', function () {
    $former = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($former, MatterRole::Associate);
    $deadline = makeDeadline($this->matter, ['responsible_user_id' => $former->id]);
    $former->update(['is_active' => false]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->mountTableAction('edit', $deadline)
        ->assertTableActionDataSet(['responsible_user_id' => null]);

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'due_date' => today()->addDays(30)->toDateString(),
    ])->assertHasTableActionErrors(['responsible_user_id']);

    deadlinesTab($this->matter)->callTableAction('edit', $deadline, data: [
        'due_date' => today()->addDays(30)->toDateString(),
        'responsible_user_id' => $this->lawyer->id,
    ])->assertHasNoTableActionErrors();

    expect($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id)
        ->and($deadline->fresh()->due_date->toDateString())->toBe(today()->addDays(30)->toDateString());
});

/** Mốc đã xong không còn việc gì để giao (cùng luật nút "Đổi người phụ trách"): ô người phụ trách không có mặt, các ô khác vẫn sửa được. */
it('edits a completed deadline without touching who holds it', function () {
    $deadline = makeDeadline($this->matter, [
        'name' => 'Tên gõ nhầm',
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)
        ->mountTableAction('edit', $deadline)
        ->assertTableActionDataSet(['name' => 'Tên gõ nhầm'])
        ->assertFormFieldHidden('responsible_user_id')
        ->setTableActionData(['name' => 'Tên đã sửa'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($deadline->fresh()->name)->toBe('Tên đã sửa')
        ->and($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

it('hides the edit button from someone who cannot write to the matter', function () {
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');
    deadlinesTab($this->matter)->assertTableActionVisible('edit', $deadline);

    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');
    deadlinesTab($this->matter)->assertTableActionHidden('edit', $deadline);
});

// =========================================================================================
// XOÁ (M6.5 Task 14, `deadlines/F7`; R14) — xoá mềm kèm lý do bắt buộc.
// =========================================================================================

it('deletes a deadline with a reason, and writes an audit entry', function () {
    $deadline = makeDeadline($this->matter, ['name' => 'Mốc gõ nhầm']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('delete', $deadline, data: [
        'reason' => 'Ghi nhầm sang vụ khác, chưa từng là mốc của hồ sơ này.',
    ])->assertHasNoTableActionErrors();

    expect(Deadline::query()->whereKey($deadline->id)->exists())->toBeFalse()
        ->and(Deadline::withTrashed()->whereKey($deadline->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'deadline_deleted')
            ->where('properties->reason', 'Ghi nhầm sang vụ khác, chưa từng là mốc của hồ sơ này.')
            ->exists())->toBeTrue();
});

it('requires a reason to delete a deadline, and deletes nothing without one', function () {
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('delete', $deadline, data: [
        'reason' => '',
    ])->assertHasTableActionErrors(['reason']);

    expect(Deadline::query()->whereKey($deadline->id)->exists())->toBeTrue();
});

/** Một mốc đã gỡ không còn hiện ở bảng của chính vụ việc — cùng `SoftDeletingScope` mọi nơi khác dùng. */
it('removes a deleted deadline from the table', function () {
    $deadline = makeDeadline($this->matter, ['name' => 'Mốc sẽ bị gỡ']);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('delete', $deadline, data: ['reason' => 'Nhập trùng.']);

    deadlinesTab($this->matter)->assertDontSee('Mốc sẽ bị gỡ');
});

/**
 * Brief Task 14: "mốc đã xoá không hiện ở widget và không được nhắc" — cả chuỗi, bắt đầu từ nút
 * "Xoá" thật. Mốc thứ hai của cùng vụ, cùng bậc nhắc, không bị gỡ: vế dương trong cùng lượt, để
 * "không thư nào" không thể đến từ một lý do khác (vụ việc, người nhận).
 */
it('keeps a deadline deleted through the tab out of the dashboard widget and out of the reminders', function () {
    $deleted = makeDeadline($this->matter, ['name' => 'Mốc gõ nhầm', 'due_date' => today()->addDay()]);
    $kept = makeDeadline($this->matter, ['name' => 'Mốc thật', 'due_date' => today()->addDay()]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('delete', $deleted, data: [
        'reason' => 'Nhập trùng với mốc thật.',
    ])->assertHasNoTableActionErrors();

    test()->livewire(UpcomingDeadlinesWidget::class)
        ->assertCanSeeTableRecords([$kept])
        ->assertCanNotSeeTableRecords([$deleted]);

    Mail::fake();
    (new CheckDeadlines)->handle();

    expect($kept->fresh()->reminders_sent)->toContain('d1')
        ->and($deleted->fresh()->reminders_sent)->toBe([]);
});

it('hides the delete button from someone who cannot write to the matter', function () {
    $deadline = makeDeadline($this->matter);

    $this->actingAs($this->lawyer, 'web');
    deadlinesTab($this->matter)->assertTableActionVisible('delete', $deadline);

    $this->actingAs(User::factory()->withRole(Role::Accountant)->create(), 'web');
    deadlinesTab($this->matter)->assertTableActionHidden('delete', $deadline);
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

/**
 * Carried từ rà soát Task 3 (quyết định của controller, M6.5 Task 14): `SetDeadlineCompletion` mở
 * lại một mốc mà KHÔNG hỏi lại người đứng tên còn giữ được nó không — người đó có thể đã bị vô hiệu
 * hoá, xoá, gỡ khỏi đội ngũ, hoặc vụ việc đã bị siết thành `restricted` từ sau khi mốc được đánh
 * dấu xong. Quyết định: mở lại VẪN chạy, người phụ trách chuyển sang luật sư phụ trách hồ sơ, có
 * ghi nhật ký, và màn hình nói ra điều đó bằng tiếng Việt.
 */
function reopenedHandOverTitle(User $lead): string
{
    return __('deadlines.tab.actions.reopen_reassigned_title', ['name' => $lead->name]);
}

function completedDeadlineHeldBy(Matter $matter, User $holder): Deadline
{
    return makeDeadline($matter, [
        'responsible_user_id' => $holder->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);
}

it('hands a reopened deadline to the lead lawyer when its holder has since been deactivated, and says so', function () {
    $former = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($former, MatterRole::Associate);
    $deadline = completedDeadlineHeldBy($this->matter, $former);
    $former->update(['is_active' => false]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline)->assertHasNoTableActionErrors();

    Notification::assertNotified(reopenedHandOverTitle($this->lawyer));

    $activity = Activity::query()->where('event', 'deadline_responsible_changed')->sole();

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id)
        ->and($activity->causer?->is($this->lawyer))->toBeTrue()
        ->and($activity->properties->get('from'))->toBe($former->id)
        ->and($activity->properties->get('to'))->toBe($this->lawyer->id)
        ->and($activity->properties->get('reason'))->toBe('reopened_holder_no_longer_qualifies');
});

it('hands a reopened deadline to the lead lawyer when its holder has since been deleted', function () {
    $former = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($former, MatterRole::Associate);
    $deadline = completedDeadlineHeldBy($this->matter, $former);
    $former->delete();

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline)->assertHasNoTableActionErrors();

    Notification::assertNotified(reopenedHandOverTitle($this->lawyer));

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/**
 * Cùng luật, đường vào khác: người phụ trách còn `is_active` nhưng vụ việc bị siết thành
 * `restricted` sau khi mốc hoàn thành, và người đó không phải lead/admin — không còn
 * `Gate::view()` được nữa.
 */
it('hands a reopened deadline to the lead lawyer when its holder can no longer view a matter that turned restricted', function () {
    $formerlyOk = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter->addTeamMember($formerlyOk, MatterRole::Associate);
    $deadline = completedDeadlineHeldBy($this->matter, $formerlyOk);

    $this->matter->update(['confidentiality' => Confidentiality::Restricted]);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline)->assertHasNoTableActionErrors();

    Notification::assertNotified(reopenedHandOverTitle($this->lawyer));

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/**
 * "Còn trong đội ngũ" là một điều kiện RIÊNG, không suy ra được từ `Gate::view()`: một trưởng
 * phòng có `matter.viewAny` nên vẫn XEM được vụ thường sau khi bị gỡ khỏi đội ngũ. R6 chỉ cho gỡ
 * người đó vì mốc của họ đã xong lúc ấy — mở lại mốc thì mốc phải về tay một người còn trong đội
 * ngũ, đúng bất biến "mốc chưa xong nằm trong tay đội ngũ" mà R6 giữ.
 */
it('hands a reopened deadline to the lead lawyer when its holder has since left the team, even though they can still view the matter', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->matter->addTeamMember($manager, MatterRole::Associate);
    $deadline = completedDeadlineHeldBy($this->matter, $manager);

    $this->matter->team()->detach($manager->id);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline)->assertHasNoTableActionErrors();

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->responsible_user_id)->toBe($this->lawyer->id);
});

/** Cặp dương: người giữ mốc vẫn còn hợp lệ thì mở lại mà KHÔNG đổi người, không ghi nhật ký đổi người, không có câu nào về việc giao lại. */
it('reopens a deadline for a holder who still qualifies without handing it over', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    $deadline = completedDeadlineHeldBy($this->matter, $assistant);

    $this->actingAs($this->lawyer, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline)->assertHasNoTableActionErrors();

    Notification::assertNotNotified(reopenedHandOverTitle($this->lawyer));

    expect($deadline->fresh()->is_completed)->toBeFalse()
        ->and($deadline->fresh()->responsible_user_id)->toBe($assistant->id)
        ->and(Activity::query()->where('event', 'deadline_responsible_changed')->count())->toBe(0);
});

/**
 * Luật sư phụ trách hồ sơ cũng không còn giữ được mốc (R7 chặn vô hiệu hoá họ khi còn dẫn vụ
 * đang mở, nhưng dữ liệu cũ vẫn có thể mang trạng thái đó): không có ai để giao — mốc Ở LẠI
 * "đã xong" và màn hình nói việc cần làm là bàn giao hồ sơ trước. Không bao giờ mở lại một mốc
 * rồi để nó trong tay một người không ai còn nhắc tới.
 */
it('refuses to reopen when neither the holder nor the lead lawyer still qualifies', function () {
    $admin = User::factory()->admin()->create();
    $former = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $deadline = completedDeadlineHeldBy($this->matter, $former);
    $this->lawyer->update(['is_active' => false]);

    $this->actingAs($admin, 'web');

    deadlinesTab($this->matter)->callTableAction('reopen', $deadline);

    Notification::assertNotified(__('actions.failed_title'));

    expect($deadline->fresh()->is_completed)->toBeTrue()
        ->and($deadline->fresh()->responsible_user_id)->toBe($former->id);
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
 * không có `stageLog.publish` — công bố (BẬT) một mốc hạn cho khách là "quyết định đưa gì ra cho
 * khách", cùng loại quyết định với SetMatterPortalPublication (xem DeadlinePolicy::publish()).
 * Nút "changeResponsible"/"complete" vẫn hiện (không đổi bởi task này) — chỉ nút "publish" ẩn.
 *
 * Fix round 1 (ruling task-10-fix1-findings.md): nút "unpublish" (GỠ, chỉ thu hẹp những gì khách
 * thấy) chỉ cần `matter.update` — trợ lý VẪN thấy và bấm được, khác bản trước ẩn cả hai nút.
 */
it('hides only the publish button from an assistant, keeping unpublish and the other write buttons', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $private = makeDeadline($this->matter, ['name' => 'Chỉ nội bộ']);
    $shared = makeDeadline($this->matter, ['name' => 'Đã gửi khách', 'is_published' => true]);

    $this->actingAs($assistant, 'web');

    deadlinesTab($this->matter)
        ->assertTableActionHidden('publish', $private)
        ->assertTableActionVisible('complete', $private)
        ->assertTableActionVisible('changeResponsible', $private)
        ->assertTableActionVisible('unpublish', $shared)
        ->callTableAction('unpublish', $shared);

    expect($shared->fresh()->is_published)->toBeFalse();
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
