<?php

use App\Actions\Document\ReviewChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamMember;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\Payment;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * M13 Task 6 — trang "Hiệu suất theo kỳ" qua Livewire: tập người theo kỳ (R3, `subjectsForPeriod()`),
 * "Không áp dụng" ở đúng ba cột P4, P5, P7 (R6), không cột số nào sắp xếp được (R8), nhãn kỳ đang chạy
 * (R7), khối "Cách tính các con số" và "Vì sao không có bảng xếp hạng", câu R4, `performance_viewed`
 * khi mount và khi đổi kỳ (R14), kỳ tuỳ chọn bị từ chối, kỳ đang hiện nằm trong một thuộc tính khoá,
 * số truy vấn hằng theo số người (R11).
 *
 * Con số của từng cột được đo ở `PerformanceFormulasTest`; tệp này đo thứ chỉ màn hình làm sai được.
 * Mặc định xem lúc 15/10/2026 10:00, kỳ "tháng trước" (tháng 9). Hàm toàn cục mang tiền tố `m13bPg`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Trang']);
});

function m13bPgOpen(User $viewer): Testable
{
    test()->actingAs($viewer, 'web');

    return Livewire::test(Performance::class);
}

/** @return list<int|string> khoá các dòng của bảng, theo thứ tự hiện */
function m13bPgOrder(Testable $page): array
{
    return $page->instance()->getTableRecords()->keys()->all();
}

/** HTML của đúng dòng bảng mang tên `$name`. */
function m13bPgRow(string $html, string $name): string
{
    preg_match_all('/<tr\b.*?<\/tr>/s', $html, $rows);

    $matching = array_values(array_filter($rows[0], fn (string $row): bool => str_contains($row, e($name))));

    expect($matching)->toHaveCount(1);

    return $matching[0];
}

/** @return array<string, string> nhãn cột => chữ trong ô của dòng mang tên `$name` */
function m13bPgCells(string $html, string $name): array
{
    preg_match('/<thead\b.*?<\/thead>/s', $html, $head);
    preg_match_all('/<th\b.*?<\/th>/s', $head[0] ?? '', $headers);
    preg_match_all('/<td\b.*?<\/td>/s', m13bPgRow($html, $name), $cells);

    $text = fn (string $cell): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES)));

    $labels = array_map($text, $headers[0]);
    $values = array_map($text, $cells[0]);

    expect($labels)->toHaveCount(count($values));

    return array_combine($labels, $values);
}

function m13bPgLawyer(string $name): User
{
    return User::factory()->withRole(Role::Lawyer)->create(['name' => $name]);
}

// =================================================================================================
// Cột, "Không áp dụng", nhãn
// =================================================================================================

it('prints "Không áp dụng" in the P4, P5 and P7 cells of an assistant, and numbers in every other cell', function () {
    $lead = m13bPgLawyer('Luật Sư Bảng');
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Bảng']);
    Matter::factory()->create(['lead_lawyer_id' => $lead->id])->addTeamMember($assistant, MatterRole::Assistant);

    $html = m13bPgOpen($this->manager)->html();
    $notApplicable = __('performance.not_applicable');
    $assistantCells = m13bPgCells($html, 'Trợ Lý Bảng');

    expect(array_keys(array_filter($assistantCells, fn (string $cell): bool => $cell === $notApplicable)))->toBe([
        __('performance.columns.p4'),
        __('performance.columns.p5'),
        __('performance.columns.p7'),
    ])
        ->and(m13bPgRow($html, 'Luật Sư Bảng'))->not->toContain(e($notApplicable))
        ->and($assistantCells[__('performance.columns.p2')])->toBe('0')
        ->and($assistantCells[__('performance.columns.p6')])->toBe('0')
        ->and(m13bPgCells($html, 'Luật Sư Bảng')[__('performance.columns.p5')])->toBe('0')
        ->and(m13bPgCells($html, 'Luật Sư Bảng')[__('performance.columns.p7')])->toBe(Money::format(0));
});

/** Mỗi số một giá trị khác nhau: tiêu đề cột và ô bên dưới phải chỉ cùng một con số. */
it('prints each number under its own header', function () {
    $lawyer = m13bPgLawyer('Luật Sư Ô');
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'due_date' => '2026-09-10', 'is_completed' => true, 'completed_at' => '2026-09-10 09:00:00', 'created_at' => '2026-09-01 09:00:00']);
    foreach (range(1, 2) as $_) {
        Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'due_date' => '2026-09-20', 'deleted_at' => '2026-09-15 10:00:00', 'created_at' => '2026-09-01 09:00:00']);
    }
    $client = $matter->client;
    $clientUserId = ClientUser::factory()->activated()->create(['client_id' => $client->id])->id;
    ClientRequest::factory()->for($matter)->create(['client_user_id' => $clientUserId, 'status' => ClientRequestStatus::Answered, 'created_at' => '2026-09-02 09:00:00', 'answered_at' => '2026-09-02 12:00:00']);
    foreach (range(1, 2) as $_) {
        ClientRequest::factory()->for($matter)->create(['client_user_id' => $clientUserId, 'status' => ClientRequestStatus::New, 'created_at' => '2026-09-03 09:00:00']);
    }
    foreach (range(1, 4) as $_) {
        ClientRequest::factory()->for($matter)->create(['client_user_id' => $clientUserId, 'status' => ClientRequestStatus::Closed, 'created_at' => '2026-09-04 09:00:00', 'answered_at' => null]);
    }
    foreach (range(1, 5) as $index) {
        $log = StageLog::factory()->for($matter)->make(['occurred_at' => "2026-09-1{$index}", 'from_stage' => "s{$index}", 'to_stage' => 't'.$index]);
        $log->blameOn($lawyer);
        $log->save();
    }
    foreach (range(1, 6) as $_) {
        Matter::factory()->for($matter->matterType)->create(['lead_lawyer_id' => $lawyer->id])->forceFill(['closed_at' => '2026-09-25 10:00:00'])->save();
    }
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create();
    $this->travelTo(Carbon::parse('2026-09-26 10:00:00'));
    foreach (range(1, 7) as $_) {
        Audit::record(ReviewChecklistItem::AUDIT_EVENT, $item, ['matter_id' => $matter->id, 'status' => 'accepted'], causer: $lawyer);
    }
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 8_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 8_000_000, 'due_date' => '2026-09-15']);
    Payment::factory()->for($instalment)->create(['amount' => 8_000_000, 'paid_on' => '2026-09-16', 'attributed_lawyer_id' => $lawyer->id]);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $cells = m13bPgCells(m13bPgOpen($this->manager)->html(), 'Luật Sư Ô');

    expect($cells[__('performance.columns.p1')])->toContain(__('performance.ratio.insufficient', ['n' => 1]))
        ->and($cells[__('performance.columns.p1')])->toContain(__('performance.period_page.p1_breakdown', ['on_time' => 1, 'late' => 0, 'missed' => 0]))
        ->and($cells[__('performance.columns.p2')])->toBe('2')
        ->and($cells[__('performance.columns.p3')])->toContain(__('performance.period_page.p3_state', ['answered' => 1, 'received' => 3]))
        ->and($cells[__('performance.columns.p3')])->toContain('3 giờ')
        ->and($cells[__('performance.columns.p10')])->toBe('4')
        ->and($cells[__('performance.columns.p4')])->toBe(__('performance.period_page.p4_state', ['entries' => 5, 'matters' => 1]))
        ->and($cells[__('performance.columns.p5')])->toBe('6')
        ->and($cells[__('performance.columns.p6')])->toBe('7')
        ->and($cells[__('performance.columns.p7')])->toBe(Money::format(8_000_000))
        ->and($cells[__('performance.columns.p9')])->toContain(__('performance.period_page.p9_breakdown', ['deadlines_done' => 1, 'deadlines' => 1, 'answered' => 1, 'received' => 3]))
        ->and($cells[__('performance.columns.main_areas')])->toContain('(7)');
});

it('marks a running period, and not a closed one', function () {
    $running = e(__('performance.period.running'));

    expect(m13bPgOpen($this->manager)->html())->not->toContain($running)
        ->and(m13bPgOpen($this->manager)->set('data.period', 'this_month')->call('applyPeriod')->html())->toContain($running);
});

it('lists every explanation, the closed period sentence, why there is no ranking, and the scope sentence', function () {
    $html = m13bPgOpen($this->manager)->html();

    foreach (['p1', 'p2', 'p3', 'p10', 'p4', 'p5', 'p6', 'p7', 'p9', 'main_areas', 'reference', 'closed_period', 'not_applicable'] as $code) {
        expect(__("performance.explain.{$code}"))->not->toStartWith('performance.')
            ->and($html)->toContain(e(__("performance.explain.{$code}")));
    }

    expect($html)->toContain(e(__('performance.how_computed')))
        ->and($html)->toContain(e(__('performance.period_page.no_ranking.heading')))
        ->and($html)->toContain(e(__('performance.scope_note')))
        ->and(__('performance.explain.p3'))->toContain('giờ lịch');

    foreach (__('performance.period_page.no_ranking.reasons') as $reason) {
        expect($html)->toContain(e($reason));
    }
});

it('leaves the reference sentence and the revenue sentence off the page of someone without that row or that column', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Câu']);
    $html = m13bPgOpen($assistant)->html();

    expect($html)->not->toContain(e(__('performance.explain.reference')))
        ->and($html)->not->toContain(e(__('performance.explain.p7')))
        ->and($html)->toContain(e(__('performance.explain.closed_period')))
        ->and($html)->toContain(e(__('performance.period_page.no_ranking.heading')));
});

/**
 * Cột doanh thu có khi người xem đọc được tiền trên MỌI dòng của trang. Nhân chứng: một luật sư được cấp
 * thẳng `performance.viewAny` và là người duy nhất được theo dõi — dòng của chính họ đọc được tiền
 * (`billing.view`, chính mình), dòng "Chung" thì không (thiếu `revenue.viewAny`): không có cột, và Action
 * không tính đồng nào.
 */
it('hides the revenue column when the reference row may not show money, even if every person row may', function () {
    $this->manager->delete();
    $witness = m13bPgLawyer('Luật Sư Nhân Chứng');
    $witness->givePermissionTo('performance.viewAny');
    $witness = $witness->fresh();

    $contract = Contract::factory()->for(Matter::factory()->create(['lead_lawyer_id' => $witness->id]))->active()->create(['total_amount' => 2_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 2_000_000, 'due_date' => '2026-09-15']);
    Payment::factory()->for($instalment)->create(['amount' => 2_000_000, 'paid_on' => '2026-09-16', 'attributed_lawyer_id' => $witness->id]);

    $page = m13bPgOpen($witness);
    $rows = $page->instance()->getTableRecords()->all();

    expect(array_keys($rows))->toBe([Performance::REFERENCE_KEY, $witness->id])
        ->and($rows[$witness->id]['revenueCollected'])->toBeNull()
        ->and($rows[Performance::REFERENCE_KEY]['revenueCollected'])->toBeNull()
        ->and($page->html())->not->toContain(e(__('performance.columns.p7')));
});

it('shows no revenue column on a page without any row', function () {
    $witness = User::factory()->create(['name' => 'Nhân Chứng Không Vai Trò']);
    $witness->givePermissionTo(['matter.view', 'billing.view']);

    $page = m13bPgOpen($witness->fresh());

    expect($page->instance()->getTableRecords()->all())->toBe([])
        ->and($page->html())->not->toContain(e(__('performance.columns.p7')));
});

/** R2: "người xem không được thấy" thì Action không tính P7 chút nào — không một truy vấn tiền. */
function m13bPgPaymentQueries(User $viewer): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    m13bPgOpen($viewer);
    $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from\s+["`]payments["`]/i', $query['query']) === 1)->count();
    DB::disableQueryLog();

    return $count;
}

it('runs no money query for a viewer who may not see the revenue column, and one for a viewer who may', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Không Tiền']);

    expect(m13bPgPaymentQueries($assistant))->toBe(0)
        ->and(m13bPgPaymentQueries($this->manager))->toBeGreaterThan(0);
});

it('links each person to their own page, and never the reference row', function () {
    $lawyer = m13bPgLawyer('Luật Sư Liên Kết');
    $html = m13bPgOpen($this->manager)->html();

    expect(m13bPgRow($html, 'Luật Sư Liên Kết'))->toContain(e(TeamMember::getUrl(['user' => $lawyer->id], panel: 'admin')))
        ->and(m13bPgRow($html, __('performance.period_page.reference_name')))->not->toContain('href=')
        ->and(unregisteredColourVariables($html))->toBe([]);
});

// =================================================================================================
// Tập người theo kỳ (R3)
// =================================================================================================

it('keeps a lawyer deactivated on 5 November on the October page viewed on 10 November, without the switch', function () {
    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
    $left = m13bPgLawyer('Luật Sư Nghỉ Tháng Mười Một');

    $this->travelTo(Carbon::parse('2026-11-05 17:00:00'));
    $left->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));

    expect(m13bPgOrder(m13bPgOpen($this->manager)))->toContain($left->id);
});

it('leaves out a lawyer deactivated on 20 September from October, unless the switch is on', function () {
    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
    $left = m13bPgLawyer('Luật Sư Nghỉ Tháng Chín');

    $this->travelTo(Carbon::parse('2026-09-20 17:00:00'));
    $left->update(['is_active' => false]);

    // Dữ liệu cũ: nghỉ việc mà không có dòng nhật ký — coi như nghỉ trước mọi kỳ.
    $legacy = m13bPgLawyer('Luật Sư Nghỉ Từ Lâu');
    DB::table('users')->where('id', $legacy->id)->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
    $page = m13bPgOpen($this->manager);

    expect(m13bPgOrder($page))->not->toContain($left->id)
        ->and(m13bPgOrder($page))->not->toContain($legacy->id)
        ->and(m13bPgOrder($page->set('tableFilters.include_inactive.isActive', true)))->toContain($left->id)
        ->and(m13bPgOrder($page))->toContain($legacy->id)
        ->and(m13bPgRow($page->html(), 'Luật Sư Nghỉ Tháng Chín'))->toContain(e(__('performance.period_page.inactive')));
});

it('keeps a lawyer deactivated at midnight on the first day of the period, and leaves out one deactivated a second earlier', function () {
    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
    $firstDay = m13bPgLawyer('Luật Sư Nghỉ Ngày Đầu');
    $dayBefore = m13bPgLawyer('Luật Sư Nghỉ Hôm Trước');

    $this->travelTo(Carbon::parse('2026-10-01 00:00:00'));
    $firstDay->update(['is_active' => false]);
    $this->travelTo(Carbon::parse('2026-09-30 23:59:59'));
    $dayBefore->update(['is_active' => false]);

    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
    $order = m13bPgOrder(m13bPgOpen($this->manager));

    expect($order)->toContain($firstDay->id)
        ->and($order)->not->toContain($dayBefore->id);
});

/**
 * Lần vô hiệu hoá GẦN NHẤT quyết định: nghỉ, quay lại, rồi nghỉ lần nữa trong kỳ thì có dòng; một lần sửa
 * tên lúc đã nghỉ (dòng `updated` không mang `is_active`) không phải một lần vô hiệu hoá.
 */
it('reads the latest deactivation, and does not take another change of a deactivated person for one', function () {
    $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
    $twice = m13bPgLawyer('Luật Sư Nghỉ Hai Lần');
    $renamed = m13bPgLawyer('Luật Sư Đổi Tên');

    $this->travelTo(Carbon::parse('2026-08-15 09:00:00'));
    $twice->update(['is_active' => false]);
    $renamed->update(['is_active' => false]);
    $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
    $twice->update(['is_active' => true]);
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
    $twice->update(['is_active' => false]);
    $renamed->update(['name' => 'Luật Sư Đã Đổi Tên']);

    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
    $order = m13bPgOrder(m13bPgOpen($this->manager));

    expect($order)->toContain($twice->id)
        ->and($order)->not->toContain($renamed->id);
});

it('keeps a person who left and came back before the period, as an active person', function () {
    $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
    $back = m13bPgLawyer('Luật Sư Quay Lại');

    $this->travelTo(Carbon::parse('2026-08-15 09:00:00'));
    $back->update(['is_active' => false]);
    $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));
    $back->update(['is_active' => true]);

    $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));

    expect(m13bPgOrder(m13bPgOpen($this->manager)))->toContain($back->id);
});

it('never shows a soft-deleted person, switch or not', function () {
    $gone = m13bPgLawyer('Luật Sư Đã Xoá');
    $gone->delete();

    $page = m13bPgOpen($this->manager);

    expect(m13bPgOrder($page))->not->toContain($gone->id)
        ->and(m13bPgOrder($page->set('tableFilters.include_inactive.isActive', true)))->not->toContain($gone->id)
        ->and($page->html())->not->toContain(e('Luật Sư Đã Xoá'));
});

// =================================================================================================
// Livewire: người xem không đổi được tập người, kế toán 404, không sắp xếp theo số (R8)
// =================================================================================================

it('shows one row to a lawyer who injects another person\'s id into the filters and the form', function () {
    $lawyer = m13bPgLawyer('Luật Sư Tò Mò');
    $colleague = m13bPgLawyer('Luật Sư Đồng Nghiệp');

    $page = m13bPgOpen($lawyer)
        ->set('tableFilters.include_inactive.isActive', true)
        ->set('tableFilters.subjects', [$colleague->id])
        ->set('tableFilters.user_id', $colleague->id)
        ->set('data.user_id', $colleague->id)
        ->set('data.subjects', [$colleague->id])
        ->call('applyPeriod');

    expect(m13bPgOrder($page))->toBe([$lawyer->id])
        ->and($page->html())->not->toContain(e('Luật Sư Đồng Nghiệp'));
});

it('answers 404 to the accountant through Livewire', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    m13bPgOpen($accountant)->assertNotFound();
});

it('keeps the order by name when a number column is sorted, and keeps the reference row first when names are sorted backwards', function () {
    foreach (['An', 'Bình', 'Cường', 'Dũng'] as $name) {
        m13bPgLawyer("Luật Sư {$name}");
    }

    $page = m13bPgOpen($this->manager);
    $byName = m13bPgOrder($page);

    foreach (['completionRatio', 'onTimeRatio', 'deadlinesRemoved', 'itemsReviewed', 'revenueCollected', 'stageEntries'] as $column) {
        expect(m13bPgOrder($page->call('sortTable', $column, 'desc')))->toBe($byName)
            ->and(m13bPgOrder($page->call('sortTable', $column, 'asc')))->toBe($byName);
    }

    $people = array_values(array_filter($byName, fn (int|string $key): bool => $key !== Performance::REFERENCE_KEY));

    expect($byName[0])->toBe(Performance::REFERENCE_KEY)
        ->and(m13bPgOrder($page->call('sortTable', 'name', 'desc')))->toBe([Performance::REFERENCE_KEY, ...array_reverse($people)])
        ->and(collect($page->instance()->getTable()->getColumns())->filter->isSortable()->keys()->all())->toBe(['name']);
});

// =================================================================================================
// Kỳ: form, khoá, lỗi, nhật ký (R14, R16)
// =================================================================================================

it('writes performance_viewed when a performance.viewAny holder opens the page and when the period changes, and at no other request', function () {
    $page = m13bPgOpen($this->manager);

    $page->call('applyPeriod')
        ->set('data.period', 'last_quarter')->call('applyPeriod')
        ->call('applyPeriod')
        ->set('tableFilters.include_inactive.isActive', true)
        ->call('sortTable', 'name', 'desc')
        ->call('$refresh');

    $rows = Activity::query()->where('event', 'performance_viewed')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('causer_id')->unique()->all())->toBe([$this->manager->id])
        ->and($rows->pluck('subject_id')->unique()->all())->toBe([null])
        ->and($rows[0]->properties->all())->toBe(['page' => 'performance', 'period' => 'last_month', 'from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and($rows[1]->properties->all())->toBe(['page' => 'performance', 'period' => 'last_quarter', 'from' => '2026-07-01', 'to' => '2026-09-30']);
});

it('writes no performance_viewed line for a lawyer or an assistant reading their own row', function (Role $role) {
    $viewer = User::factory()->withRole($role)->create();

    m13bPgOpen($viewer)->set('data.period', 'last_quarter')->call('applyPeriod');

    expect(Activity::query()->where('event', 'performance_viewed')->count())->toBe(0);
})->with([Role::Lawyer, Role::Assistant]);

it('refuses a custom period of 367 days on the page in vietnamese and keeps showing the period it had', function () {
    $page = m13bPgOpen($this->manager)
        ->set('data.period', 'custom')
        ->set('data.date_from', '2025-10-14')
        ->set('data.date_to', '2026-10-15')
        ->call('applyPeriod')
        ->assertHasErrors(['data.date_to']);

    expect($page->errors()->first('data.date_to'))->toBe(__('performance.period.errors.too_long', ['max' => 366, 'days' => 367]))
        ->and($page->get('appliedPeriod'))->toBe(['period' => 'last_month'])
        ->and(Activity::query()->where('event', 'performance_viewed')->count())->toBe(1);
});

it('applies a valid custom period through the form', function () {
    $page = m13bPgOpen($this->manager)
        ->set('data.period', 'custom')
        ->set('data.date_from', '2026-09-05')
        ->set('data.date_to', '2026-10-15')
        ->call('applyPeriod')
        ->assertHasNoErrors();

    expect($page->get('appliedPeriod'))->toBe(['period' => 'custom', 'date_from' => '2026-09-05', 'date_to' => '2026-10-15'])
        ->and($page->html())->toContain('05/09/2026')
        ->and($page->html())->toContain(e(__('performance.period.running')));
});

/**
 * Trong MỘT request, trang nhớ báo cáo và Filament nhớ các dòng của bảng. Đổi kỳ hay bật công tắc sau khi
 * chúng đã được tính phải tính lại — không bao giờ trả số của kỳ trước. Gọi thẳng trên một instance để
 * đổi kỳ sau lần tính đầu tiên trong cùng request.
 */
it('recomputes the numbers within one request once the period or the switch changes', function () {
    $lawyer = m13bPgLawyer('Luật Sư Bộ Nhớ');
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'due_date' => '2026-09-10', 'created_at' => '2026-09-01 09:00:00']);
    $this->travelTo(Carbon::parse('2026-09-01 09:00:00'));
    $left = m13bPgLawyer('Luật Sư Nghỉ Từ Tháng Tám');
    DB::table('users')->where('id', $left->id)->update(['is_active' => false]);
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $page = m13bPgOpen($this->manager)->instance();

    expect($page->report()->rows[$lawyer->id]->deadlinesMissed)->toBe(1)
        ->and($page->getTableRecords()[$lawyer->id]['deadlinesMissed'])->toBe(1);

    $page->data = ['period' => 'this_month', 'date_from' => null, 'date_to' => null];
    $page->applyPeriod();

    expect($page->report()->rows[$lawyer->id]->deadlinesMissed)->toBe(0)
        ->and($page->getTableRecords()[$lawyer->id]['deadlinesMissed'])->toBe(0)
        ->and($page->report()->rows)->not->toHaveKey($left->id);

    $page->tableFilters = ['include_inactive' => ['isActive' => true]];

    expect($page->report()->rows)->toHaveKey($left->id);
});

it('refuses to change the period shown through its locked property', function () {
    expect(fn () => m13bPgOpen($this->manager)->set('appliedPeriod', ['period' => 'custom', 'date_from' => '2000-01-01', 'date_to' => '2026-10-15']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

// =================================================================================================
// Số truy vấn (R11)
// =================================================================================================

/** Số truy vấn của một lần mount trang (sau một lần chạy làm nóng bộ nhớ đệm quyền của spatie). */
function m13bPgQueries(User $viewer): int
{
    m13bPgOpen($viewer);

    DB::flushQueryLog();
    DB::enableQueryLog();
    m13bPgOpen($viewer);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Một luật sư được theo dõi có việc ở mọi cột của kỳ; trả về vụ của họ. */
function m13bPgBusyLawyer(int $index): Matter
{
    $lawyer = m13bPgLawyer("Luật Sư Bận {$index}");
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'due_date' => '2026-09-10', 'created_at' => '2026-09-01 09:00:00']);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $lawyer->id, 'due_date' => '2026-09-11', 'created_at' => '2026-09-01 09:00:00', 'deleted_at' => '2026-09-12 09:00:00']);
    $item = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create();
    Audit::record(ReviewChecklistItem::AUDIT_EVENT, $item, ['matter_id' => $matter->id, 'status' => 'accepted'], causer: $lawyer);
    ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::Answered, 'created_at' => '2026-09-02 09:00:00', 'answered_at' => '2026-09-02 11:00:00']);
    $log = StageLog::factory()->for($matter)->make(['occurred_at' => '2026-09-12', 'from_stage' => 'intake', 'to_stage' => 'collecting_documents']);
    $log->blameOn($lawyer);
    $log->save();
    Matter::factory()->create(['lead_lawyer_id' => $lawyer->id])->forceFill(['closed_at' => '2026-09-20 10:00:00'])->save();
    $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => 1_000_000, 'signed_at' => '2026-08-01']);
    $instalment = Instalment::factory()->for($contract)->create(['amount' => 1_000_000, 'due_date' => '2026-09-15']);
    Payment::factory()->for($instalment)->create(['amount' => 1_000_000, 'paid_on' => '2026-09-16', 'attributed_lawyer_id' => $lawyer->id]);

    return $matter;
}

it('runs as many queries for three people as for twelve, permission checks included', function () {
    $matter = m13bPgBusyLawyer(1);
    $assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ Lý Bận']);
    $matter->addTeamMember($assistant, MatterRole::Assistant);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $assistant->id, 'due_date' => '2026-09-12', 'created_at' => '2026-09-01 09:00:00']);

    $three = m13bPgQueries($this->manager);

    foreach (range(2, 10) as $index) {
        m13bPgBusyLawyer($index);
    }

    $twelve = m13bPgQueries($this->manager);

    expect(m13bPgOrder(m13bPgOpen($this->manager)))->toHaveCount(13)
        ->and($twelve)->toBe($three);
});
