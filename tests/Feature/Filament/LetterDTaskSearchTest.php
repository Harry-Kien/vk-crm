<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Matter;
use App\Models\Task;
use App\Models\User;
use App\Support\Search\LetterD;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;

/*
 * Gộp integ-fa (làn fa3 × làn fa1/fa2): chữ "đ" như "d" ở các ô tìm làn fa3 để lại cho làn khác — trang "Công việc"
 * (`TaskColumns`: tiêu đề, mã hồ sơ — cả tab "Công việc" của vụ dùng chung), bộ lọc vụ / người được giao / người giao
 * của `ListTasks`, ô "Vụ việc" và "Giao cho" của "Giao việc" (trang Công việc và tab của vụ), ô người nhận của "Giao
 * lại", bốn ô chọn của "Bàn giao hàng loạt" và ô người của "Thêm thành viên". Cột: `LetterD::searchable()` (chỉ thu
 * hẹp truy vấn của bảng — `visibleTo()` không đổi). Ô chọn: `LetterD::searchOptions()` / `filterOptions()` trên ĐÚNG
 * danh sách tuỳ chọn cũ — tầm nhìn không đổi, vụ hạn chế không lộ. Mọi hành vi qua màn hình (Livewire). Hàm mang
 * tiền tố `ltd`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đoàn Fabaltd Lead']);
    $this->helper = User::factory()->withRole(Role::Assistant)->create(['name' => 'Đinh Fabaltd Trợ']);
    $this->secretLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đỗ Fabaltd Kín']);

    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lead->id, 'code' => 'QĐ-FABALTD-01', 'title' => 'Tranh chấp đất đai Fabaltd',
    ]);
    $this->matter->addTeamMember($this->helper, MatterRole::Assistant);

    $this->secret = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->secretLead->id, 'code' => 'QĐ-FABALTD-09', 'title' => 'Tranh chấp đất đai Fabaltd kín',
    ]);

    $this->task = Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create([
        'title' => 'Gọi ông Đinh Fabaltdtask', 'assignee_id' => $this->helper->id, 'assigned_by' => $this->lead->id,
    ]);
    $this->secretTask = Task::factory()->forMatter($this->secret)->status(TaskStatus::Accepted)->create([
        'title' => 'Gọi ông Đinh Fabaltdtask kín', 'assignee_id' => $this->secretLead->id, 'assigned_by' => $this->secretLead->id,
    ]);
});

function ltdMariadb(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

function ltdSelect(Testable $page, string $schema, string $name): Select
{
    $select = collect($page->instance()->getSchema($schema)->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with((string) $component->getKey(), '.'.$name));

    expect($select)->not->toBeNull("{$schema} {$name}")
        ->and($select->hasDynamicSearchResults())->toBeTrue("{$schema} {$name} tìm trên máy chủ")
        ->and($select->getSearchDebounce())->toBe(LetterD::SELECT_SEARCH_DEBOUNCE_MS, "{$schema} {$name} độ trễ");

    return $select;
}

/**
 * Kết quả ô chọn `$name` của schema `$schema` (null = schema của hộp thoại đang mở) cho chuỗi `$term` — đúng lời gọi ô
 * chọn gửi khi người dùng gõ (`callSchemaComponentMethod` → `getSearchResultsForJs`).
 *
 * @return array<int, string> id => nhãn
 */
function ltdSearch(Testable $page, ?string $schema, string $name, string $term): array
{
    $schema ??= $page->instance()->getMountedActionSchemaName();
    $select = ltdSelect($page, $schema, $name);
    $results = null;

    $page->call('callSchemaComponentMethod', $select->getKey(), 'getSearchResultsForJs', ['search' => $term])
        ->assertReturned(function (mixed $returned) use (&$results): bool {
            $results = $returned;

            return is_array($returned);
        });

    return collect($results)->mapWithKeys(fn (array $option): array => [(int) $option['value'] => $option['label']])->all();
}

// =================================================================================================
// Cột của bảng
// =================================================================================================

it('Tasks list: the title and matter code treat "đ" as "d", and a task on a restricted matter stays hidden', function () {
    $this->actingAs($this->manager, 'web');

    $list = fn (string $term) => $this->livewire(ListTasks::class, ['activeTab' => 'all'])->searchTable($term);

    $list('dinh fabaltdtask')->assertCanSeeTableRecords([$this->task])->assertCanNotSeeTableRecords([$this->secretTask]);
    $list('ĐINH FABALTDTASK')->assertCanSeeTableRecords([$this->task]);
    $list('qd-fabaltd')->assertCanSeeTableRecords([$this->task])->assertCanNotSeeTableRecords([$this->secretTask]);
    $list('QĐ-FABALTD-09')->assertCanNotSeeTableRecords([$this->secretTask]);

    // Người dẫn vụ hạn chế vẫn tìm được việc của mình — phạm vi cũ, không hẹp hơn.
    $this->actingAs($this->secretLead, 'web');
    $list('dinh fabaltdtask kin')->assertCanNotSeeTableRecords([$this->task]);
    $list('qd-fabaltd-09')->assertCanSeeTableRecords([$this->secretTask]);
});

it('Tasks list: on MariaDB "Dang" finds "Đặng" in a task title', function () {
    $dang = Task::factory()->forMatter($this->matter)->create([
        'title' => 'Hẹn ông Đặng Fabaltdmaria', 'assignee_id' => $this->helper->id, 'assigned_by' => $this->lead->id,
    ]);

    $this->actingAs($this->manager, 'web');

    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all'])->searchTable('Dang Fabaltdmaria');

    ltdMariadb()
        ? $page->assertCanSeeTableRecords([$dang])
        // SQLite so `LIKE` theo byte: "ặ" chỉ khớp khi gõ đúng dấu (LetterD docblock).
        : $page->assertCanNotSeeTableRecords([$dang]);

    $this->livewire(ListTasks::class, ['activeTab' => 'all'])->searchTable('Dặng Fabaltdmaria')->assertCanSeeTableRecords([$dang]);
});

it('Tasks tab of a matter: the title treats "đ" as "d"', function () {
    $this->actingAs($this->lead, 'web');

    $this->livewire(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->searchTable('dinh fabaltdtask')
        ->assertCanSeeTableRecords([$this->task]);
});

// =================================================================================================
// Ô chọn
// =================================================================================================

it('Tasks list filters: matter, assignee and assigner find "Đ…" by "d…", never offering the restricted matter', function () {
    $this->actingAs($this->manager, 'web');

    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all']);

    expect(ltdSearch($page, 'tableFiltersForm', 'matter_id.value', 'qd fabaltd'))->toBe([$this->matter->id => 'QĐ-FABALTD-01'])
        ->and(ltdSearch($page, 'tableFiltersForm', 'assignee_id.value', 'dinh fabaltd'))->toBe([$this->helper->id => 'Đinh Fabaltd Trợ'])
        ->and(ltdSearch($page, 'tableFiltersForm', 'assigned_by.value', 'doan fabaltd'))->toBe([$this->lead->id => 'Đoàn Fabaltd Lead']);
});

it('"Giao việc" on the Tasks page: the matter select finds "đất đai" by "dat dai" only among matters the viewer may assign on, the assignee select follows the matter', function () {
    $this->actingAs($this->manager, 'web');

    $page = $this->livewire(ListTasks::class)->mountAction('create');

    expect(ltdSearch($page, null, 'matter_id', 'dat dai fabaltd'))->toHaveKey($this->matter->id)
        ->not->toHaveKey($this->secret->id);

    $page->fillForm(['matter_id' => $this->matter->id]);

    expect(array_keys(ltdSearch($page, null, 'assignee_id', 'dinh fabaltd')))->toBe([$this->helper->id])
        ->and(ltdSearch($page, null, 'assignee_id', 'do fabaltd kin'))->toBe([]);
});

it('"Giao lại": the new-holder select finds "Đoàn" by "doan", among the same people as before', function () {
    $this->actingAs($this->manager, 'web');

    $page = $this->livewire(ListTasks::class, ['activeTab' => 'all'])->mountTableAction('reassign', $this->task);

    // Người đang giữ việc (chưa trả lại) không có trong danh sách — như trước.
    expect(array_keys(ltdSearch($page, null, 'assignee_id', 'doan fabaltd')))->toBe([$this->lead->id])
        ->and(ltdSearch($page, null, 'assignee_id', 'dinh fabaltd'))->toBe([]);
});

it('"Giao việc" on the Tasks tab of a matter: the assignee select finds "Đinh" by "dinh"', function () {
    $this->actingAs($this->lead, 'web');

    $page = $this->livewire(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->mountTableAction('assign');

    expect(array_keys(ltdSearch($page, null, 'assignee_id', 'dinh fabaltd')))->toBe([$this->helper->id])
        ->and(ltdSearch($page, null, 'assignee_id', 'do fabaltd kin'))->toBe([]);
});

it('"Thêm thành viên": the person select finds "Đ…" by "d…" for the chosen role', function () {
    $newcomer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Đặng Fabaltd Mới']);

    $this->actingAs($this->lead, 'web');

    $page = $this->livewire(TeamRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->mountTableAction('addMember')
        ->fillForm(['role_in_matter' => MatterRole::Associate->value]);

    expect(ltdSearch($page, null, 'user_id', 'dang fabaltd'))->toBe([$newcomer->id => 'Đặng Fabaltd Mới'])
        // Người đã ngồi đội ngũ không có lại — như trước.
        ->and(ltdSearch($page, null, 'user_id', 'dinh fabaltd'))->toBe([]);
});

it('"Bàn giao hàng loạt": the four person selects find "Đ…" by "d…", and a lead whose only open matter is restricted is never offered to a manager', function () {
    $this->actingAs($this->manager, 'web');

    $page = $this->livewire(BulkReassign::class);

    expect(ltdSearch($page, 'form', 'lead_lawyer_id', 'doan fabaltd'))->toBe([$this->lead->id => 'Đoàn Fabaltd Lead'])
        ->and(ltdSearch($page, 'form', 'lead_lawyer_id', 'do fabaltd kin'))->toBe([])
        ->and(ltdSearch($page, 'staffWorkForm', 'user_id', 'dinh fabaltd'))->toBe([$this->helper->id => 'Đinh Fabaltd Trợ'])
        ->and(ltdSearch($page, 'staffWorkForm', 'user_id', 'do fabaltd kin'))->toBe([]);

    $page->set('data.lead_lawyer_id', $this->lead->id)->set('staff.user_id', $this->helper->id);

    // Người nhận: không lại chính người đang chọn ở đầu (như trước); tên người khác tìm được không dấu.
    expect(ltdSearch($page, 'form', 'new_lead_id', 'doan fabaltd'))->toBe([])
        ->and(ltdSearch($page, 'form', 'new_lead_id', 'do fabaltd kin'))->toBe([$this->secretLead->id => 'Đỗ Fabaltd Kín'])
        ->and(ltdSearch($page, 'staffWorkForm', 'recipient_id', 'dinh fabaltd'))->toBe([])
        ->and(ltdSearch($page, 'staffWorkForm', 'recipient_id', 'doan fabaltd'))->toBe([$this->lead->id => 'Đoàn Fabaltd Lead']);
});

it('LetterD::filterOptions is the same match as LetterD::searchOptions', function () {
    $options = [1 => 'Nguyễn Văn Đức', 2 => 'Trần Thị Đào', 3 => 'Lê Dũng'];

    foreach (['duc nguyen', 'DAO', 'd', 'khong co', 'duc tran', '', null] as $term) {
        expect(LetterD::filterOptions($options, $term))->toBe(LetterD::searchOptions(fn (): array => $options)($term), (string) $term);
    }
});
