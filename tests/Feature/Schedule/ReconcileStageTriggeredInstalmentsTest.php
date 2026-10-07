<?php

use App\Actions\Billing\TriggerInstalmentsForStage;
use App\Actions\Schedule\ReconcileStageTriggeredInstalments;
use App\Enums\ContractStatus;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Spatie\Activitylog\Models\Activity;

/**
 * M9 Task 6 — lưới an toàn hằng ngày cho đợt theo giai đoạn. Nó phủ những đường listener không phủ:
 * một lần kích hoạt bị hỏng (listener `report()` rồi nuốt), dữ liệu ghi thẳng `stage_logs` không phát
 * sự kiện (seeder), và một đợt nằm trong lịch mà vụ đã qua giai đoạn của nó mà chưa được kích hoạt
 * (ở đây dựng bằng factory). Đợt do phụ lục thêm cho giai đoạn vụ đã qua thì `AmendContract` tự kích
 * hoạt ngay (lượt rà soát Task 6, I1 — `TriggerInstalmentsForStageTest`); đối chiếu chỉ còn là lưới.
 * Nó gọi ĐÚNG `TriggerInstalmentsForStage`, không bản sao logic.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-03 07:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->civilType = MatterType::factory()->withStages()->create(['code' => 'CIV']);
});

/** Vụ dân sự đang đứng ở `$stage`, kèm một hợp đồng `$status` có MỘT đợt chờ giai đoạn `$key`. */
function reconcileFixture(MatterType $type, User $lead, string $stage, string $key, ContractStatus $status = ContractStatus::Active, int $dueDays = 0): Instalment
{
    $matter = Matter::factory()->for($type, 'matterType')->atStage($stage)->create(['lead_lawyer_id' => $lead->id]);
    $contract = Contract::factory()->for($matter)->create(['total_amount' => 10_000_000]);
    $instalment = Instalment::factory()->for($contract)->create([
        'amount' => 10_000_000,
        'trigger_type' => InstalmentTrigger::Stage,
        'trigger_stage_key' => $key,
        'due_days_after_trigger' => $dueDays,
        'due_date' => null,
    ]);

    if ($status !== ContractStatus::Draft) {
        $contract->update(['status' => $status, 'signed_at' => '2026-09-01']);
    }

    return $instalment;
}

function reconcileNow(): array
{
    return app(ReconcileStageTriggeredInstalments::class)->handle();
}

/**
 * Như {@see reconcileNow()}, và khẳng định lượt đó KHÔNG mở transaction nào: một cặp (vụ, giai đoạn)
 * chắc chắn không có việc (hợp đồng không `active`, vụ đã xoá mềm, vụ chưa VÀO giai đoạn đó) bị loại
 * ngay ở truy vấn ứng viên, không phải đi tới tận khoá `matters` của Action rồi mới trả 0.
 */
function reconcileOpeningNoTransaction(): array
{
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    $result = reconcileNow();

    expect($transactions)->toBe(0);

    return $result;
}

it('releases an instalment added after the matter had already passed its stage', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'court_accepted', 'filed', dueDays: 15);
    $matter = $instalment->contract->matter;
    $entry = StageLog::factory()->for($matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-10 00:00:00']);
    StageLog::factory()->for($matter)->transition('filed', 'court_accepted')->create(['occurred_at' => '2026-09-20 00:00:00']);

    expect(reconcileNow())->toBe(['triggered' => 1, 'failed' => 0]);

    $instalment->refresh();

    expect($instalment->triggered_by_stage_log_id)->toBe($entry->id)
        ->and($instalment->due_date->toDateString())->toBe('2026-09-25')
        ->and($instalment->triggered_at->toDateTimeString())->toBe('2026-10-03 07:00:00');
});

it('releases only once when it runs twice', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    StageLog::factory()->for($instalment->contract->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-10 00:00:00']);

    expect(reconcileNow())->toBe(['triggered' => 1, 'failed' => 0]);

    $this->travelTo('2026-10-04 07:00:00');

    expect(reconcileNow())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($instalment->refresh()->triggered_at->toDateTimeString())->toBe('2026-10-03 07:00:00')
        ->and(Activity::query()->where('event', 'instalment_triggered')->count())->toBe(1);
});

/**
 * Dữ liệu mẫu (`MatterSeeder`) ghi `stage_logs` thẳng, có dòng mở đầu `from_stage = NULL` — đó vẫn
 * là một lần VÀO giai đoạn, và không sự kiện nào được phát cho nó: chỉ đối chiếu thấy nó.
 */
it('counts a stage log with no from_stage as an entry into its stage', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    $entry = StageLog::factory()->for($instalment->contract->matter)->create(['from_stage' => null, 'to_stage' => 'filed', 'occurred_at' => '2026-09-12 00:00:00']);

    expect(reconcileNow()['triggered'])->toBe(1)
        ->and($instalment->refresh()->triggered_by_stage_log_id)->toBe($entry->id);
});

/**
 * Một dòng cùng giai đoạn (§6.3 — "thêm cập nhật", hay dòng bàn giao nội bộ của `ReassignMatter`)
 * không phải một lần vào giai đoạn. Vụ đứng ở `filed` mà chỉ có dòng `filed → filed`: không kích hoạt.
 */
it('does not count a same-stage update as an entry', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    StageLog::factory()->for($instalment->contract->matter)->transition('filed', 'filed')->create(['occurred_at' => '2026-09-12 00:00:00']);

    expect(reconcileOpeningNoTransaction())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($instalment->refresh()->triggered_at)->toBeNull();
});

it('leaves an instalment whose stage the matter has not reached yet', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'drafting', 'filed');
    StageLog::factory()->for($instalment->contract->matter)->transition('collecting_documents', 'drafting')->create(['occurred_at' => '2026-09-12 00:00:00']);

    expect(reconcileOpeningNoTransaction())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($instalment->refresh()->triggered_at)->toBeNull();
});

it('leaves the instalments of a contract that is not active', function (ContractStatus $status) {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed', $status);
    StageLog::factory()->for($instalment->contract->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-12 00:00:00']);

    expect(reconcileOpeningNoTransaction())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($instalment->refresh()->triggered_at)->toBeNull();
})->with([
    'draft' => ContractStatus::Draft,
    'completed' => ContractStatus::Completed,
    'cancelled' => ContractStatus::Cancelled,
]);

it('leaves the instalments of a soft-deleted matter', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    $matter = $instalment->contract->matter;
    StageLog::factory()->for($matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-12 00:00:00']);
    DB::table('matters')->where('id', $matter->id)->update(['deleted_at' => now()]);

    expect(reconcileOpeningNoTransaction())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($instalment->refresh()->triggered_at)->toBeNull();
});

/** Phán quyết controller (2) trên đường đối chiếu: vụ X → Y → X, đợt thêm sau cả hai lần — gắn vào lần đầu. */
it('binds to the first entry when the matter entered the stage more than once', function () {
    $instalment = reconcileFixture($this->civilType, $this->lead, 'collecting_documents', 'collecting_documents', dueDays: 3);
    $matter = $instalment->contract->matter;
    $first = StageLog::factory()->for($matter)->transition('intake', 'collecting_documents')->create(['occurred_at' => '2026-09-05 00:00:00']);
    StageLog::factory()->for($matter)->transition('collecting_documents', 'on_hold')->create(['occurred_at' => '2026-09-08 00:00:00']);
    StageLog::factory()->for($matter)->transition('on_hold', 'collecting_documents')->create(['occurred_at' => '2026-09-20 00:00:00']);

    reconcileNow();

    expect($instalment->refresh()->triggered_by_stage_log_id)->toBe($first->id)
        ->and($instalment->due_date->toDateString())->toBe('2026-09-08');
});

/** Một cặp (vụ, giai đoạn) hỏng không chặn những cặp khác của cùng lượt; lỗi được `report()`. */
it('keeps going past a pair that fails, and reports it', function () {
    Exceptions::fake();
    $broken = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    $healthy = reconcileFixture($this->civilType, $this->lead, 'filed', 'filed');
    foreach ([$broken, $healthy] as $instalment) {
        StageLog::factory()->for($instalment->contract->matter)->transition('drafting', 'filed')->create(['occurred_at' => '2026-09-12 00:00:00']);
    }
    $brokenMatterId = $broken->contract->matter_id;
    $real = app(TriggerInstalmentsForStage::class);

    $this->mock(TriggerInstalmentsForStage::class)
        ->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function (Matter $matter, string $key, StageLog $log) use ($brokenMatterId, $real): int {
            if ($matter->id === $brokenMatterId) {
                throw new RuntimeException('Khoá hàng hết giờ chờ.');
            }

            return $real->handle($matter, $key, $log);
        });

    expect(reconcileNow())->toBe(['triggered' => 1, 'failed' => 1])
        ->and($healthy->refresh()->triggered_at)->not->toBeNull()
        ->and($broken->refresh()->triggered_at)->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

/**
 * Ngày thường của văn phòng: nhiều đợt đang chờ những giai đoạn vụ CHƯA tới. Một truy vấn ứng
 * viên trả lời "không có gì" — không một truy vấn nào cho từng đợt, không transaction nào.
 */
it('answers a quiet day with one candidate query and nothing else', function () {
    foreach (range(1, 5) as $i) {
        $instalment = reconcileFixture($this->civilType, $this->lead, 'drafting', 'filed');
        StageLog::factory()->for($instalment->contract->matter)->transition('collecting_documents', 'drafting')->create();
    }

    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    expect(reconcileNow())->toBe(['triggered' => 0, 'failed' => 0])
        ->and($queries)->toBe(1);
});
