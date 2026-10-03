<?php

use App\Actions\Billing\TriggerInstalmentsForStage;
use App\Actions\TransitionMatterStage;
use App\Enums\ContractStatus;
use App\Enums\InstalmentState;
use App\Enums\InstalmentStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Events\MatterStageChanged;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Spatie\Activitylog\Models\Activity;

/**
 * M9 Task 6 — đợt thanh toán kích hoạt theo giai đoạn: `TriggerInstalmentsForStage` và listener
 * `ReleaseStageTriggeredInstalments` trên sự kiện `MatterStageChanged` (M7 Task 3).
 *
 * Đường listener đi qua `TransitionMatterStage` THẬT, không `Event::fake` sự kiện đổi giai đoạn —
 * test đo đúng sợi dây sự kiện → listener → Action, như một luật sư bấm "Chuyển giai đoạn".
 *
 * Bộ giai đoạn dân sự CỐ ĐỊNH (mã `CIV` → `StagePresets::civil()`): `intake` (đầu) →
 * `collecting_documents` ⇄ `on_hold`, `collecting_documents` → `drafting` → `filed` →
 * `court_accepted`. Mã ba chữ không bao giờ trùng mã hai chữ ngẫu nhiên của `MatterTypeFactory`
 * (2/676 lần ra `HS`/`DN` — bộ giai đoạn khác, xem `ActivateContractTest`).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-03 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->civilType = MatterType::factory()->withStages()->create(['code' => 'CIV']);
    $this->matter = Matter::factory()
        ->for($this->civilType, 'matterType')
        ->atStage('drafting')
        ->create(['lead_lawyer_id' => $this->lead->id]);
});

/**
 * Một hợp đồng ở `$status` với lịch thu cân đúng tổng (hook tầng 2 của bất biến tổng chặn mọi lịch
 * lệch trên hợp đồng `active`): dựng bản nháp, thêm các đợt, rồi mới đổi trạng thái.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function stageTriggerContract(Matter $matter, array $rows, ContractStatus $status = ContractStatus::Active, string $signedAt = '2026-09-01'): Contract
{
    $contract = Contract::factory()->for($matter)->create([
        'total_amount' => array_sum(array_column($rows, 'amount')),
    ]);

    foreach ($rows as $index => $row) {
        Instalment::factory()->for($contract)->create([
            'sequence' => $index + 1,
            'due_date' => null,
            ...$row,
        ]);
    }

    if ($status !== ContractStatus::Draft) {
        $contract->update(['status' => $status, 'signed_at' => $signedAt]);
    }

    return $contract->fresh();
}

/** Một đợt chờ giai đoạn `$key`. */
function awaitingStageRow(string $key, int $amount = 10_000_000, int $dueDays = 0): array
{
    return [
        'amount' => $amount,
        'trigger_type' => InstalmentTrigger::Stage,
        'trigger_stage_key' => $key,
        'due_days_after_trigger' => $dueDays,
    ];
}

/** Chuyển giai đoạn qua đúng Action thật (luật sư phụ trách), ngày xảy ra `$occurredAt`. */
function moveMatterTo(Matter $matter, User $actor, string $toStage, string $occurredAt = '2026-10-03'): StageLog
{
    return app(TransitionMatterStage::class)->handle(
        matter: $matter->fresh(),
        actor: $actor,
        toStage: $toStage,
        occurredAt: $occurredAt,
        internalNote: null,
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );
}

// --- Đường listener: TransitionMatterStage → MatterStageChanged → ReleaseStageTriggeredInstalments ---

it('releases exactly the instalment waiting on the stage the matter just entered, bound to the stage log just written', function () {
    $contract = stageTriggerContract($this->matter, [
        awaitingStageRow('filed', 30_000_000, 10),
        awaitingStageRow('court_accepted', 70_000_000, 30),
    ]);
    [$onFiled, $onAccepted] = $contract->instalments->all();

    $this->actingAs($this->lead, 'web');
    $log = moveMatterTo($this->matter, $this->lead, 'filed', '2026-10-01');

    $onFiled->refresh();

    expect($onFiled->due_date->toDateString())->toBe('2026-10-11')
        ->and($onFiled->triggered_at?->toDateTimeString())->toBe('2026-10-03 10:00:00')
        ->and($onFiled->triggered_by_stage_log_id)->toBe($log->id)
        ->and($onAccepted->refresh()->due_date)->toBeNull()
        ->and($onAccepted->triggered_at)->toBeNull()
        ->and($onAccepted->triggered_by_stage_log_id)->toBeNull();
});

/**
 * Test quan trọng nhất của task (kế hoạch): một dòng cập nhật CÙNG giai đoạn (§6.3) không phải một
 * lần chạm tới giai đoạn. Vụ đang ĐỨNG ở `filed` (không có dòng nào đưa nó vào đó) và luật sư thêm
 * một dòng cập nhật: không đợt nào được kích hoạt.
 */
it('releases nothing on a same-stage update, even while the matter stands at the trigger stage', function () {
    $matter = Matter::factory()->for($this->civilType, 'matterType')->atStage('filed')->create(['lead_lawyer_id' => $this->lead->id]);
    $contract = stageTriggerContract($matter, [awaitingStageRow('filed')]);

    $this->actingAs($this->lead, 'web');
    $log = moveMatterTo($matter, $this->lead, 'filed');

    $instalment = $contract->instalments->first()->refresh();

    expect($log->from_stage)->toBe('filed')
        ->and($instalment->due_date)->toBeNull()
        ->and($instalment->triggered_at)->toBeNull()
        ->and(Activity::query()->where('event', 'instalment_triggered')->exists())->toBeFalse();
});

/** Vào lại giai đoạn kích hoạt lần hai không kích hoạt lại: cổng là `triggered_at IS NULL`. */
it('does not release again when the matter comes back to the trigger stage', function () {
    $matter = Matter::factory()->for($this->civilType, 'matterType')->atStage('intake')->create(['lead_lawyer_id' => $this->lead->id]);
    $contract = stageTriggerContract($matter, [awaitingStageRow('collecting_documents', dueDays: 5)]);

    $this->actingAs($this->lead, 'web');
    $first = moveMatterTo($matter, $this->lead, 'collecting_documents', '2026-09-20');

    $this->travelTo('2026-10-05 09:00:00');
    moveMatterTo($matter, $this->lead, 'on_hold', '2026-10-05');
    moveMatterTo($matter, $this->lead, 'collecting_documents', '2026-10-05');

    $instalment = $contract->instalments->first()->refresh();

    expect($instalment->triggered_by_stage_log_id)->toBe($first->id)
        ->and($instalment->due_date->toDateString())->toBe('2026-09-25')
        ->and($instalment->triggered_at->toDateTimeString())->toBe('2026-10-03 10:00:00')
        ->and(Activity::query()->where('event', 'instalment_triggered')->count())->toBe(1);
});

/**
 * Phán quyết controller (2): "đã chạm giai đoạn X" là dòng ĐẦU TIÊN đưa vụ vào X. Một đợt thêm vào
 * lịch SAU lần chạm đầu (chưa được đối chiếu) mà vụ đi ra rồi vào lại: lần vào lại kích hoạt nó,
 * nhưng gắn vào — và tính ngày từ — lần chạm ĐẦU, không phải dòng vừa ghi.
 */
it('binds an instalment released on a re-entry to the first entry into that stage, not to the row just written', function () {
    $matter = Matter::factory()->for($this->civilType, 'matterType')->atStage('intake')->create(['lead_lawyer_id' => $this->lead->id]);

    $this->actingAs($this->lead, 'web');
    $first = moveMatterTo($matter, $this->lead, 'collecting_documents', '2026-09-10');
    moveMatterTo($matter, $this->lead, 'on_hold', '2026-09-15');

    $contract = stageTriggerContract($matter, [awaitingStageRow('collecting_documents', dueDays: 5)], signedAt: '2026-09-01');

    $reentry = moveMatterTo($matter, $this->lead, 'collecting_documents', '2026-10-02');

    $instalment = $contract->instalments->first()->refresh();

    expect($reentry->id)->not->toBe($first->id)
        ->and($instalment->triggered_by_stage_log_id)->toBe($first->id)
        ->and($instalment->due_date->toDateString())->toBe('2026-09-15');
});

it('does not release an instalment of a contract that is not active', function (ContractStatus $status) {
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')], $status);

    $this->actingAs($this->lead, 'web');
    moveMatterTo($this->matter, $this->lead, 'filed');

    $instalment = $contract->instalments->first()->refresh();

    expect($instalment->due_date)->toBeNull()
        ->and($instalment->triggered_at)->toBeNull()
        ->and($instalment->triggered_by_stage_log_id)->toBeNull();
})->with([
    'draft' => ContractStatus::Draft,
    'completed' => ContractStatus::Completed,
    'cancelled' => ContractStatus::Cancelled,
]);

/**
 * Kế hoạch điểm 2: ngày đến hạn tính từ ngày giai đoạn THẬT SỰ xảy ra (`occurred_at`), không từ
 * hôm nay — một lần chuyển ghi lùi ngày sinh ra một đợt đã quá hạn ngay khi ra đời, và đó là sự
 * thật. Hợp đồng ký trước ngày đó, nên phán quyết kẹp (3) không đổi gì ở đây.
 */
it('dates the instalment from the backdated day the stage really happened, so it can be overdue at birth', function () {
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed', dueDays: 30)], signedAt: '2026-08-01');

    $this->actingAs($this->lead, 'web');
    moveMatterTo($this->matter, $this->lead, 'filed', '2026-08-24');

    $instalment = $contract->instalments->first()->refresh();

    expect($instalment->due_date->toDateString())->toBe('2026-09-23')
        ->and($instalment->state())->toBe(InstalmentState::Overdue)
        ->and(Instalment::query()->overdue()->whereKey($instalment->id)->exists())->toBeTrue();
});

/**
 * Phán quyết controller (3): ngày gốc là `occurred_at` của dòng kích hoạt, nhưng KHÔNG sớm hơn ngày
 * ký hợp đồng — kẹp vào ngày muộn hơn trong hai ngày. Một giai đoạn ghi lùi về trước ngày ký không
 * sinh ra một khoản đến hạn trước khi khách ký.
 */
it('never dates the instalment before the contract was signed', function () {
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed', dueDays: 3)], signedAt: '2026-09-28');

    $this->actingAs($this->lead, 'web');
    moveMatterTo($this->matter, $this->lead, 'filed', '2026-09-02');

    expect($contract->instalments->first()->refresh()->due_date->toDateString())->toBe('2026-10-01');
});

/**
 * `MatterStageChanged` là `ShouldDispatchAfterCommit`: một transaction NGOÀI rollback sau khi
 * Action chuyển giai đoạn đã "xong" thì listener KHÔNG BAO GIỜ chạy — đo bằng một mock của Action
 * (`never()`), vì mọi lần ghi của nó (nếu nó lỡ chạy bên trong transaction) cũng rollback theo và
 * không để lại dấu nào để đọc.
 */
it('never runs the listener when an outer transaction rolls the transition back', function () {
    stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    $this->mock(TriggerInstalmentsForStage::class)->shouldNotReceive('handle');

    $this->actingAs($this->lead, 'web');

    try {
        DB::transaction(function () {
            moveMatterTo($this->matter, $this->lead, 'filed');

            throw new RuntimeException('Bước sau trong cùng transaction hỏng.');
        });
    } catch (RuntimeException) {
    }

    expect($this->matter->fresh()->stage)->toBe('drafting');
});

/** Cặp dương của test trên: cùng mock, không rollback — listener chạy đúng một lần với dòng vừa ghi. */
it('runs the listener once, after commit, with the matter, the entered stage and the stage log', function () {
    stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    $logId = null;
    $this->mock(TriggerInstalmentsForStage::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(function (Matter $matter, string $stageKey, StageLog $stageLog) use (&$logId): bool {
            $logId = $stageLog->id;

            return $matter->is($this->matter) && $stageKey === 'filed' && $stageLog->to_stage === 'filed';
        })
        ->andReturn(1);

    $this->actingAs($this->lead, 'web');
    $log = moveMatterTo($this->matter, $this->lead, 'filed');

    expect($logId)->toBe($log->id);
});

/**
 * Phán quyết controller (1): kích hoạt là hệ quả của một sự kiện, không phải quyết định của ai —
 * KHÔNG causer trên dòng nhật ký và `updated_by` của đợt giữ nguyên, kể cả khi listener chạy trong
 * request của luật sư vừa bấm "Chuyển giai đoạn" (không bao giờ rơi về người đang đăng nhập).
 * Nguồn gốc là `stage_log_id`.
 */
it('records the release with no causer and leaves updated_by alone, even inside the lawyer request', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->actingAs($accountant, 'web');
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed', dueDays: 7)]);
    $instalment = $contract->instalments->first();

    expect($instalment->updated_by)->toBe($accountant->id);

    $this->actingAs($this->lead, 'web');
    $log = moveMatterTo($this->matter, $this->lead, 'filed', '2026-10-02');

    $activity = Activity::query()->where('event', 'instalment_triggered')->sole();

    expect($instalment->refresh()->updated_by)->toBe($accountant->id)
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->causer_type)->toBeNull()
        ->and($activity->subject_type)->toBe('instalment')
        ->and($activity->subject_id)->toBe($instalment->id)
        ->and($activity->properties->all())->toBe([
            'stage_log_id' => $log->id,
            'stage' => 'filed',
            'due_date' => '2026-10-09',
        ]);
});

/** Lần ghi chéo của luật sư trên chính dòng tiến độ vẫn mang tên luật sư — chỉ dòng của đợt là "hệ thống". */
it('keeps the lawyer as the causer of the transition itself', function () {
    stageTriggerContract($this->matter, [awaitingStageRow('filed')]);

    $this->actingAs($this->lead, 'web');
    moveMatterTo($this->matter, $this->lead, 'filed');

    expect(Activity::query()->where('event', 'matter_stage_transitioned')->sole()->causer_id)->toBe($this->lead->id);
});

/**
 * Listener đồng bộ, lỗi được `report()` rồi nuốt: lần chuyển giai đoạn ĐÃ commit không được hiện
 * ra như thất bại vì bước tiền hỏng — lưới `ReconcileStageTriggeredInstalments` (07:00 hằng ngày)
 * kích hoạt lại đợt đó.
 */
it('reports a failing release and still lets the transition through', function () {
    Exceptions::fake();
    stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    $this->mock(TriggerInstalmentsForStage::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Khoá hàng hết giờ chờ.'));

    $this->actingAs($this->lead, 'web');
    $log = moveMatterTo($this->matter, $this->lead, 'filed');

    expect($log->exists)->toBeTrue()
        ->and($this->matter->fresh()->stage)->toBe('filed');
    Exceptions::assertReported(RuntimeException::class);
});

/**
 * Vụ đã xoá mềm thì không gì được kích hoạt — và đó là một câu trả lời bình thường của Action, không
 * phải một lỗi phải báo. Sự kiện được phát tay sau khi vụ bị xoá mềm bằng SQL (hook `Matter::deleting`
 * chặn xoá vụ còn nợ — đúng nên phải đi vòng): đó là khe giữa lúc lần chuyển giai đoạn commit và lúc
 * listener chạy.
 */
it('releases nothing, and reports nothing, for a matter that has been soft-deleted by the time the listener runs', function () {
    Exceptions::fake();
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-10-01']);
    DB::table('matters')->where('id', $this->matter->id)->update(['deleted_at' => now()]);

    event(new MatterStageChanged($log));

    expect($contract->instalments->first()->refresh()->triggered_at)->toBeNull();
    Exceptions::assertNothingReported();
});

/**
 * Cặp của test trên, ở tầng listener: listener KHÔNG tự quyết "vụ đã xoá mềm" — nó nạp cả vụ đã xoá
 * mềm và giao cho Action, nơi duy nhất trả lời câu đó (dưới khoá).
 */
it('hands a matter soft-deleted in the meantime to the Action, which alone decides', function () {
    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create();
    DB::table('matters')->where('id', $this->matter->id)->update(['deleted_at' => now()]);
    $this->mock(TriggerInstalmentsForStage::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (Matter $matter, string $stageKey, StageLog $stageLog): bool => $matter->id === $this->matter->id && $matter->trashed() && $stageKey === 'filed')
        ->andReturn(0);

    event(new MatterStageChanged($log));
});

/**
 * Một phiên cổng khách (của MỘT KHÁCH KHÁC) đang mở trong cùng tiến trình không được làm vụ, hợp
 * đồng, đợt hay dòng tiến độ thành "không có" (`ClientPortalScope`; bốn model tiền đóng kín `1 = 0`
 * với cổng). Mọi lần đọc trên đường listener → Action gỡ scope đó tường minh.
 */
it('still releases while another client portal session is active in the same process', function () {
    Exceptions::fake();
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed', dueDays: 4)]);
    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-10-01 00:00:00']);
    $outsider = ClientUser::factory()->create();

    ClientPortalScope::actingAs($outsider, fn () => event(new MatterStageChanged($log)));

    expect($contract->instalments->first()->refresh()->due_date?->toDateString())->toBe('2026-10-05');
    Exceptions::assertNothingReported();
});

// --- Action trực tiếp: các điều kiện của lõi ---------------------------------------------------

it('releases only pending, not yet released, stage-type instalments of that very stage', function () {
    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-10-01 00:00:00']);
    $contract = stageTriggerContract($this->matter, [
        awaitingStageRow('filed', 10_000_000, 2),
        ['amount' => 10_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'status' => InstalmentStatus::Waived, 'waived_reason' => 'Miễn khoản này theo thoả thuận riêng với khách hàng.'],
        ['amount' => 10_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'status' => InstalmentStatus::Paid],
        ['amount' => 10_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'due_date' => '2026-09-01', 'triggered_at' => '2026-09-01 08:00:00'],
        ['amount' => 10_000_000, 'trigger_type' => InstalmentTrigger::DueDate, 'trigger_stage_key' => 'filed', 'due_date' => '2027-01-31'],
        awaitingStageRow('court_accepted'),
    ]);

    $released = app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $log);

    $rows = $contract->instalments()->get()->keyBy('sequence');

    expect($released)->toBe(1)
        ->and($rows[1]->due_date->toDateString())->toBe('2026-10-03')
        ->and($rows[1]->triggered_by_stage_log_id)->toBe($log->id)
        ->and($rows[2]->triggered_at)->toBeNull()
        ->and($rows[3]->triggered_at)->toBeNull()
        ->and($rows[4]->triggered_at->toDateTimeString())->toBe('2026-09-01 08:00:00')
        ->and($rows[4]->due_date->toDateString())->toBe('2026-09-01')
        ->and($rows[4]->triggered_by_stage_log_id)->toBeNull()
        ->and($rows[5]->triggered_at)->toBeNull()
        ->and($rows[5]->due_date->toDateString())->toBe('2027-01-31')
        ->and($rows[6]->triggered_at)->toBeNull();
});

/**
 * `handle()` trả lời cho ĐÚNG giai đoạn được hỏi. Một đợt chờ giai đoạn khác mà vụ cũng đã chạm (chưa
 * kích hoạt vì một lần hỏng trước đó) để lại cho lối vào của chính giai đoạn đó — đối chiếu hằng ngày.
 */
it('releases only the stage it was asked about, even when the matter also reached another waiting stage', function () {
    StageLog::factory()->for($this->matter)->transition('collecting_documents', 'drafting')->create(['occurred_at' => '2026-09-20 00:00:00']);
    $filed = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-10-01 00:00:00']);
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed'), awaitingStageRow('drafting')]);

    expect(app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $filed))->toBe(1);

    $rows = $contract->instalments()->get()->keyBy('sequence');

    expect($rows[1]->triggered_by_stage_log_id)->toBe($filed->id)
        ->and($rows[2]->triggered_at)->toBeNull();
});

it('returns zero and releases nothing for a soft-deleted matter', function () {
    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-10-01']);
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    DB::table('matters')->where('id', $this->matter->id)->update(['deleted_at' => now()]);

    expect(app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $log))->toBe(0)
        ->and($contract->instalments->first()->refresh()->triggered_at)->toBeNull();
});

/**
 * Fail closed: Action chỉ nhận một dòng tiến độ THẬT SỰ đưa ĐÚNG vụ đó vào ĐÚNG giai đoạn đó. Một
 * dòng cùng giai đoạn (§6.3), một dòng của vụ khác, hay một dòng vào giai đoạn khác là lỗi lập
 * trình của nơi gọi, không phải "không có gì để làm".
 */
it('refuses a stage log that is not an entry of that matter into that stage', function (Closure $makeLog) {
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    $log = $makeLog($this->matter);

    expect($log)->toBeInstanceOf(StageLog::class)
        ->and(fn () => app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $log))
        ->toThrow(LogicException::class)
        ->and($contract->instalments->first()->refresh()->triggered_at)->toBeNull();
})->with([
    'same-stage update' => fn (Matter $matter) => StageLog::factory()->for($matter)->transition('filed', 'filed')->create(),
    'another matter' => fn (Matter $matter) => StageLog::factory()->transition('drafting', 'filed')->create(),
    'another stage' => fn (Matter $matter) => StageLog::factory()->for($matter)->transition('filed', 'court_accepted')->create(),
]);

/** Fail closed ở lõi: hợp đồng đưa vào phải thuộc đúng vụ đã khoá — không kích hoạt dưới khoá của vụ khác. */
it('refuses to release a contract under the lock of another matter', function () {
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')]);
    StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create();
    $other = Matter::factory()->for($this->civilType, 'matterType')->create(['lead_lawyer_id' => $this->lead->id]);

    expect(fn () => DB::transaction(fn () => app(TriggerInstalmentsForStage::class)->releaseLocked($other, $contract, 'filed')))
        ->toThrow(LogicException::class)
        ->and($contract->instalments->first()->refresh()->triggered_at)->toBeNull();
});

/**
 * "Đã chạm giai đoạn X" = dòng ĐẦU TIÊN vào X theo `occurred_at` (rồi `id`, cùng tiêu chí của
 * `Matter::stageLogs()`). Một dòng ghi SAU nhưng khai ngày xảy ra SỚM hơn là lần chạm đầu.
 */
it('binds to the earliest entry by occurred_at, even one recorded later', function () {
    $recordedFirst = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-20 00:00:00']);
    StageLog::factory()->for($this->matter)->transition('filed', 'drafting')->create(['occurred_at' => '2026-09-22 00:00:00']);
    $backdated = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-05 00:00:00']);
    $contract = stageTriggerContract($this->matter, [awaitingStageRow('filed')], signedAt: '2026-09-01');

    app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $recordedFirst);

    $instalment = $contract->instalments->first()->refresh();

    expect($instalment->triggered_by_stage_log_id)->toBe($backdated->id)
        ->and($instalment->due_date->toDateString())->toBe('2026-09-05');
});

/**
 * Lần thăm dò NGOÀI transaction: vụ không có hợp đồng, hay không đợt nào chờ giai đoạn đó, thì
 * không mở transaction tiền nào và không khoá hàng `matters` — mỗi lần chuyển giai đoạn của văn
 * phòng không phải xếp hàng sau một khoá tiền vô ích.
 */
it('opens no money transaction when nothing waits on the stage', function (bool $withContract) {
    if ($withContract) {
        stageTriggerContract($this->matter, [awaitingStageRow('court_accepted')]);
    }

    $log = StageLog::factory()->for($this->matter)->transition('drafting', 'filed')->create();
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    expect(app(TriggerInstalmentsForStage::class)->handle($this->matter, 'filed', $log))->toBe(0)
        ->and($transactions)->toBe(0);
})->with(['no contract' => false, 'nothing waits on that stage' => true]);
