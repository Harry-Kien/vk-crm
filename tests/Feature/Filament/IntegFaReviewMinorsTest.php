<?php

use App\Actions\Intake\RecordIntake;
use App\Actions\Intake\RecordPrivacyNotice;
use App\Actions\Intake\UpdateIntakeSummary;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Task\TaskAssignees;
use App\Enums\Confidentiality;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\IntakeSource;
use App\Enums\MatterRole;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ConvertIntakeRequest;
use App\Filament\Admin\Resources\Matters\Pages\EditMatter;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Widgets\MyWorkQueueWidget;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueKpiWidget;
use App\Jobs\SendLeadChangeNotices;
use App\Models\Contract;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Instalment;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Staff\DeadlineHandedAwayAlert;
use App\Notifications\Staff\IntakeConvertedAlert;
use App\Notifications\Staff\LeadChangeAlert;
use App\Notifications\Staff\MatterAssignmentAlert;
use App\Notifications\Staff\MatterCancelledTasksAlert;
use App\Support\Audit;
use App\Support\Billing\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Gộp integ-fa — rà soát chéo các làn fa1/fa2/fa3 (2026-10-18), các mục nhỏ:
 *
 * 10. Huỷ hồ sơ mở nhầm: một chuông cho mỗi người giữ / người giao việc nội bộ còn mở (trừ người bấm), vụ hạn chế không
 *     lộ mã.
 * 12. "Đã thu" của vành khuyên Doanh thu tính cả hồ sơ đã huỷ như ô "Đã thu".
 * 13. Lô bàn giao có nhiều người phụ trách cũ: mỗi mã kèm đúng người cũ của nó.
 * 15. Chuyển tiếp nhận thành hồ sơ: mặc định luật sư phụ trách là người tư vấn; người tư vấn không phải luật sư phụ trách
 *     nhận chuông tin.
 * 16. Đổi người phụ trách mốc: người giữ cũ nhận chuông tin.
 * 17. "Chờ tôi duyệt" trên trang Tài liệu, và ô số trên bảng điều khiển.
 * 19. Tìm người nhận việc chỉ so phần tên, không phần "đang giữ … việc".
 *
 * Hàm mang tiền tố `ifm`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    Mail::fake();

    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Quản trị Bình']);
    $this->head = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng phòng Hà']);
    $this->lead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Khang']);
    $this->associate = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Minh']);
    $this->assistant = User::factory()->withRole(Role::Assistant)->create(['name' => 'Trợ lý Lan']);

    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id, 'code' => 'IFM-2026-01']);
    $this->matter->addTeamMember($this->associate, MatterRole::Associate);
    $this->matter->addTeamMember($this->assistant, MatterRole::Assistant);
});

function ifmBells(User $user, string $type): array
{
    return $user->notifications()->where('type', $type)->get()->pluck('data')->all();
}

// =============================================================================================
// 10. HUỶ HỒ SƠ MỞ NHẦM — NGƯỜI GIỮ VÀ NGƯỜI GIAO VIỆC ĐƯỢC BÁO
// =============================================================================================

/** Mutation probe: bỏ lời xếp `SendMatterCancelledTaskNotices` khỏi `CancelMatter` → đỏ. */
it('rings each holder and assigner of an open task once after a cancel, never the person cancelling', function () {
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Pending)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Declined)->create(['assignee_id' => $this->associate->id, 'assigned_by' => $this->lead->id]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Completed)->create(['assignee_id' => $this->associate->id, 'assigned_by' => $this->admin->id]);
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create(['assignee_id' => $this->admin->id, 'assigned_by' => $this->lead->id]);

    $this->actingAs($this->admin, 'web');
    $this->livewire(EditMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => 'Mở nhầm khách hàng, sẽ mở lại đúng.'])
        ->assertHasNoActionErrors();

    expect(Matter::withTrashed()->find($this->matter->id)->trashed())->toBeTrue();

    $assistantBells = ifmBells($this->assistant, MatterCancelledTasksAlert::class);
    $leadBells = ifmBells($this->lead, MatterCancelledTasksAlert::class);

    expect($assistantBells)->toHaveCount(1)
        ->and($assistantBells[0]['body'])->toContain('IFM-2026-01')
        ->toContain(__('internal_flow.matter_cancelled.held', ['count' => 2]))
        ->toContain('Quản trị Bình')
        ->and($leadBells)->toHaveCount(1)
        ->and($leadBells[0]['body'])->toContain(__('internal_flow.matter_cancelled.assigned', ['count' => 4]))
        // Người đã trả lại việc không còn giữ nó; người bấm không tự báo mình.
        ->and(ifmBells($this->associate, MatterCancelledTasksAlert::class))->toBe([])
        ->and(ifmBells($this->admin, MatterCancelledTasksAlert::class))->toBe([]);
});

/** Mutation probe: luôn in mã hồ sơ (bỏ `isListableBy()`) → đỏ. */
it('does not name a restricted matter to a holder who could not open it', function () {
    $this->matter->forceFill(['confidentiality' => Confidentiality::Restricted])->save();
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);

    $this->actingAs($this->admin, 'web');
    $this->livewire(EditMatter::class, ['record' => $this->matter->getKey()])
        ->callAction('cancelMatter', data: ['reason' => 'Mở nhầm khách hàng, sẽ mở lại đúng.'])
        ->assertHasNoActionErrors();

    expect(json_encode(ifmBells($this->assistant, MatterCancelledTasksAlert::class), JSON_UNESCAPED_UNICODE))
        ->toContain(__('internal_flow.team_removal.matter_hidden'))
        ->not->toContain('IFM-2026-01')
        ->and(json_encode(ifmBells($this->lead, MatterCancelledTasksAlert::class), JSON_UNESCAPED_UNICODE))->toContain('IFM-2026-01');
});

// =============================================================================================
// 12. "ĐÃ THU" CỦA VÀNH KHUYÊN = "ĐÃ THU" CỦA Ô SỐ, KỂ CẢ HỒ SƠ ĐÃ HUỶ
// =============================================================================================

/** Mutation probe: bỏ `withTrashed()` khỏi lát "đã thu" → đỏ. */
it('counts money collected on a since-cancelled matter in the donut, as the "Đã thu" card does', function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $cancelled = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);
    $live = Matter::factory()->create(['lead_lawyer_id' => $this->lead->id]);

    foreach ([[$cancelled, 7_000_000], [$live, 3_000_000]] as [$matter, $amount]) {
        $contract = Contract::factory()->for($matter)->active()->create(['total_amount' => $amount, 'signed_at' => '2026-10-02']);
        $instalment = Instalment::factory()->for($contract)->create(['amount' => $amount, 'due_date' => '2026-10-30']);
        Payment::factory()->for($instalment)->create(['amount' => $amount, 'paid_on' => '2026-10-05', 'attributed_lawyer_id' => $this->lead->id]);
    }

    // Dữ liệu từ trước làn fa3: hồ sơ đã huỷ mà còn khoản thu (nay `CancelMatter` từ chối).
    $cancelled->deleteQuietly();

    $this->actingAs($this->admin, 'web');

    $card = collect(Livewire::test(RevenueKpiWidget::class, ['pageFilters' => []])->instance()->cards())->keyBy('key')['collected'];
    $rows = collect(Livewire::test(ReceivablesDonutWidget::class, ['pageFilters' => []])->instance()->numberTableRows())->keyBy('label');

    expect($card['amount'])->toBe(10_000_000)
        ->and($rows[__('widgets.revenue_dashboard.donut.table.collected')]['value'])->toBe(Money::format(10_000_000));
});

// =============================================================================================
// 13. LÔ BÀN GIAO NHIỀU NGƯỜI PHỤ TRÁCH CŨ
// =============================================================================================

/** Mutation probe: dùng tên người cũ của hồ sơ đầu cho cả lô → đỏ. */
it('names each matter\'s own former lead in a bulk lot with different former leads', function () {
    $otherOld = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Tuấn']);
    $newLead = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Mới']);
    $second = Matter::factory()->create(['lead_lawyer_id' => $otherOld->id, 'code' => 'IFM-2026-02']);
    $second->addTeamMember($this->assistant, MatterRole::Assistant);

    $events = [];

    foreach ([[$this->matter, $this->lead], [$second, $otherOld]] as [$matter, $old]) {
        $matter->forceFill(['lead_lawyer_id' => $newLead->id])->save();
        $events[$matter->id] = Audit::record('matter_reassigned', $matter, ['from_user_id' => $old->id, 'to_user_id' => $newLead->id], $this->admin)->id;
    }

    (new SendLeadChangeNotices($events, $this->admin->id))->handle(app(ResolveStaffRecipients::class));

    $bell = ifmBells($this->assistant, LeadChangeAlert::class);

    expect($bell)->toHaveCount(1)
        ->and($bell[0]['body'])->toContain(e(__('internal_flow.lead_change.code_from', ['code' => 'IFM-2026-01', 'from' => 'Luật sư Khang'])))
        ->toContain(e(__('internal_flow.lead_change.code_from', ['code' => 'IFM-2026-02', 'from' => 'Luật sư Tuấn'])))
        ->toContain('Luật sư Mới')
        ->not->toContain('từ Luật sư Khang sang');
});

// =============================================================================================
// 15. CHUYỂN TIẾP NHẬN THÀNH HỒ SƠ
// =============================================================================================

function ifmIntake(User $actor, User $handler): IntakeRequest
{
    $type = MatterType::factory()->withStages()->create(['is_active' => true, 'name' => 'Dân sự']);
    $intake = app(RecordIntake::class)->handle($actor, [
        'contact_name' => 'Trần Thị Mới',
        'contact_phone' => '0832 270 898',
        'contact_email' => 'moi@example.test',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'matter_type_id' => $type->id,
        'quoted_amount' => '15.000.000',
    ], [
        ['name' => 'Công Ty Bên Kia', 'role' => PartyRole::Defendant, 'phone' => '0977 000 222', 'id_number' => '079088000111'],
    ])->intake;

    app(RecordPrivacyNotice::class)->handle($actor, $intake, true);
    app(UpdateIntakeSummary::class)->handle($actor, $intake->fresh(), 'Hợp đồng mua bán bị bên kia vi phạm thời hạn giao hàng.');
    $intake->forceFill(['assigned_to' => $handler->id])->save();

    return $intake->fresh();
}

/** Mutation probe: mặc định luôn là người bấm → đỏ. */
it('defaults the lead to the consulting lawyer of the intake, who then gets the "assigned a matter" notice', function () {
    $intake = ifmIntake($this->head, $this->associate);

    $this->actingAs($this->head, 'web');
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['lead_lawyer_id' => $this->associate->id])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);

    expect($matter->lead_lawyer_id)->toBe($this->associate->id)
        ->and(ifmBells($this->associate, MatterAssignmentAlert::class))->toHaveCount(1)
        // Người tư vấn là luật sư phụ trách: không thêm chuông tin.
        ->and(ifmBells($this->associate, IntakeConvertedAlert::class))->toBe([]);
});

/** Mutation probe: bỏ lời xếp `SendIntakeConvertedNotice` → đỏ. */
it('tells the consulting person with a bell when someone else converts the intake and leads the matter, without the code of a matter they cannot open', function () {
    $intake = ifmIntake($this->head, $this->assistant);

    $this->actingAs($this->head, 'web');
    $this->livewire(ConvertIntakeRequest::class, ['record' => $intake->getRouteKey()])
        ->assertFormSet(['lead_lawyer_id' => $this->head->id])
        ->call('convert')
        ->assertHasNoFormErrors();

    $matter = Matter::query()->findOrFail($intake->fresh()->matter_id);
    $bells = ifmBells($this->assistant, IntakeConvertedAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain((string) $intake->code)->toContain('Trưởng phòng Hà')
        ->not->toContain((string) $matter->code)
        ->and(ifmBells($this->head, IntakeConvertedAlert::class))->toBe([]);
});

// =============================================================================================
// 16. ĐỔI NGƯỜI PHỤ TRÁCH MỐC — NGƯỜI GIỮ CŨ ĐƯỢC BÁO
// =============================================================================================

/** Mutation probe: bỏ `queueHandedAwayNotice()` khỏi `ChangeDeadlineResponsible` → đỏ. */
it('tells the former holder of a deadline who it moved to, but not when they moved it themselves', function () {
    $deadline = Deadline::factory()->for($this->matter)->create([
        'name' => 'Hạn nộp bản tự khai', 'due_date' => today()->addDays(5), 'responsible_user_id' => $this->assistant->id,
    ]);
    $own = Deadline::factory()->for($this->matter)->create([
        'name' => 'Phiên hoà giải', 'due_date' => today()->addDays(9), 'responsible_user_id' => $this->lead->id,
    ]);

    $this->actingAs($this->lead, 'web');
    $tab = $this->livewire(DeadlinesRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class]);
    $tab->callTableAction('changeResponsible', $deadline, data: ['responsible_user_id' => $this->associate->id])->assertHasNoTableActionErrors();
    $tab->callTableAction('changeResponsible', $own, data: ['responsible_user_id' => $this->associate->id])->assertHasNoTableActionErrors();

    $bells = ifmBells($this->assistant, DeadlineHandedAwayAlert::class);

    expect($bells)->toHaveCount(1)
        ->and($bells[0]['body'])->toContain('Hạn nộp bản tự khai')->toContain('IFM-2026-01')->toContain('Luật sư Minh')
        ->and(ifmBells($this->lead, DeadlineHandedAwayAlert::class))->toBe([]);
});

// =============================================================================================
// 17. "CHỜ TÔI DUYỆT"
// =============================================================================================

/** Mutation probe: bỏ cổng `publish` khỏi `DocumentLibrary::awaitingApprovalIds()` → đỏ. */
it('lists, under "Chờ tôi duyệt", only the documents waiting for this viewer\'s approval, and counts them on the dashboard', function () {
    $doc = fn (Matter $matter, DocumentStatus $status, string $title): Document => Document::factory()->create([
        'matter_id' => $matter->id, 'group' => DocumentGroup::Issued, 'status' => $status, 'title' => $title,
        'client_can_view' => false, 'client_can_download' => false,
    ]);
    $mine = $doc($this->matter, DocumentStatus::PendingApproval, 'Đơn chờ duyệt');
    $draft = $doc($this->matter, DocumentStatus::InternalDraft, 'Bản nháp');
    $elsewhere = $doc(Matter::factory()->create(['lead_lawyer_id' => $this->associate->id]), DocumentStatus::PendingApproval, 'Đơn vụ khác');

    $this->actingAs($this->lead, 'web');

    $this->livewire(DocumentLibrary::class)
        ->filterTable(DocumentLibrary::AWAITING_MY_APPROVAL, true)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$draft, $elsewhere]);

    expect(MyWorkQueueWidget::countsFor($this->lead)['approvals'])->toBe(1)
        ->and(MyWorkQueueWidget::links($this->lead)['approvals'])->toContain(DocumentLibrary::AWAITING_MY_APPROVAL);

    // Trợ lý thấy văn bản đó (ngồi đội ngũ) nhưng không duyệt được: không vào tập, không có ô, không có bộ lọc.
    expect(DocumentLibrary::awaitingApprovalIds($this->assistant))->toBe([])
        ->and(MyWorkQueueWidget::countsFor($this->assistant))->not->toHaveKey('approvals');
    $this->actingAs($this->assistant, 'web');
    $this->livewire(DocumentLibrary::class)->assertTableFilterHidden(DocumentLibrary::AWAITING_MY_APPROVAL);
});

// =============================================================================================
// 19. TÌM NGƯỜI NHẬN VIỆC CHỈ SO PHẦN TÊN
// =============================================================================================

function ifmSearch(Testable $page, string $name, string $term): array
{
    $schema = $page->instance()->getMountedActionSchemaName();
    $select = collect($page->instance()->getSchema($schema)->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select && str_ends_with((string) $component->getKey(), '.'.$name));
    $results = null;

    $page->call('callSchemaComponentMethod', $select->getKey(), 'getSearchResultsForJs', ['search' => $term])
        ->assertReturned(function (mixed $returned) use (&$results): bool {
            $results = $returned;

            return is_array($returned);
        });

    return collect($results)->mapWithKeys(fn (array $option): array => [(int) $option['value'] => $option['label']])->all();
}

/** Mutation probe: bỏ `nameBefore` (so cả câu "đang giữ … việc") → đỏ. */
it('matches the assignee search on the person\'s name only, never on the workload words after it', function () {
    Task::factory()->forMatter($this->matter)->status(TaskStatus::Accepted)->create(['assignee_id' => $this->assistant->id, 'assigned_by' => $this->lead->id]);

    $this->actingAs($this->lead, 'web');
    $page = $this->livewire(TasksRelationManager::class, ['ownerRecord' => $this->matter, 'pageClass' => ViewMatter::class])
        ->mountTableAction('assign');

    expect(ifmSearch($page, 'assignee_id', 'dang giu'))->toBe([])
        ->and(ifmSearch($page, 'assignee_id', 'viec'))->toBe([])
        ->and(array_keys(ifmSearch($page, 'assignee_id', 'lan')))->toBe([$this->assistant->id]);

    expect(TaskAssignees::searchOptions([7 => 'Trợ lý Lan — đang giữ 3 việc anh/chị thấy được, 1 quá hạn'], 'qua han'))->toBe([]);
});
