<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DraftContract;
use App\Actions\Billing\RecordPayment;
use App\Actions\Schedule\ReconcileStageTriggeredInstalments;
use App\Actions\TransitionMatterStage;
use App\Enums\InstalmentState;
use App\Enums\InstalmentTrigger;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Filament\Admin\Widgets\Revenue\ReceivablesDonutWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/**
 * M9 Task 13 bước 5 — "nhập hợp đồng đang chạy lúc go-live": hợp đồng ký trong quá khứ, khoản đã thu
 * ghi lùi `paid_on`, vụ đã ở giữa chừng. Quy trình viết thành mục "Nhập hợp đồng đang chạy khi bắt đầu
 * dùng hệ thống" ở `docs/QUY-TRINH.md` (Giai đoạn 5); tệp này là phép đo của mục đó.
 *
 * Vụ nhập vào hệ thống thì bắt đầu ở giai đoạn đầu (`Matter::creating`, không dòng `stage_logs`), và
 * quản trị viên đưa nó THẲNG tới giai đoạn hiện tại bằng một lần chuyển ghi lùi ngày (vai `admin` bỏ
 * qua `allowed_next`): chỉ có MỘT dòng tiến độ — vào giai đoạn hiện tại. Các giai đoạn trước đó (nộp
 * đơn…) không có dòng nào, nên một đợt gắn vào chúng không bao giờ được kích hoạt — kể cả bởi lượt đối
 * chiếu hằng ngày, vốn chỉ đọc `stage_logs` có thật. Luật nhập: đợt của giai đoạn đã qua nhập là
 * `due_date` (ngày đã hẹn thật) và tiền đã thu ghi lùi; đợt của giai đoạn hiện tại có thể để `stage`
 * (dòng nhập là lần chạm của nó); đợt của giai đoạn chưa tới để `stage`.
 *
 * Mốc giờ 2026-10-03 10:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-03 10:00:00'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    $this->lead = User::factory()->withRole(Role::Lawyer)->create();

    // Mã ba chữ → bộ giai đoạn dân sự đầy đủ: intake → … → filed → court_accepted → … → first_instance.
    $type = MatterType::factory()->withStages()->create(['code' => 'GLV']);

    $this->matter = Matter::factory()->for($type, 'matterType')->create([
        'lead_lawyer_id' => $this->lead->id,
        'opened_at' => '2026-03-01',
    ]);

    // Nhập: quản trị viên đưa vụ thẳng tới "Toà thụ lý", ghi đúng ngày toà thụ lý thật.
    $this->actingAs($this->admin, 'web');
    $this->importLog = app(TransitionMatterStage::class)->handle(
        matter: $this->matter->fresh(),
        actor: $this->admin,
        toStage: 'court_accepted',
        occurredAt: '2026-06-15',
        internalNote: 'Nhập hồ sơ đang chạy lúc bắt đầu dùng hệ thống.',
        publicContent: null,
        nextStep: null,
        clientAction: null,
        expectedNextUpdateAt: null,
        publish: false,
    );
});

/**
 * Lịch thu nhập theo luật: tạm ứng `on_signing`; "khi nộp đơn" (giai đoạn đã qua, không có dòng tiến
 * độ) nhập là `due_date` đúng ngày đã hẹn; "khi toà thụ lý" để `stage` (dòng nhập là lần chạm của nó);
 * "khi xét xử sơ thẩm" (chưa tới) để `stage`.
 */
function goLiveContract(Matter $matter, User $lead, string $filedRowAs = 'due_date'): Contract
{
    $filedRow = $filedRowAs === 'due_date'
        ? ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::DueDate, 'due_date' => '2026-04-20']
        : ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'due_days_after_trigger' => 15];

    $draft = app(DraftContract::class)->handle($lead, $matter, ['total_amount' => 100_000_000], [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => 20_000_000, 'trigger_type' => InstalmentTrigger::OnSigning, 'due_days_after_trigger' => 7],
        $filedRow,
        ['name' => 'Thanh toán đợt 3 khi toà thụ lý', 'amount' => 20_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'court_accepted', 'due_days_after_trigger' => 30],
        ['name' => 'Thanh toán đợt 4 khi toà xét xử sơ thẩm', 'amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'first_instance', 'due_days_after_trigger' => 15],
    ]);

    return app(ActivateContract::class)->handle($lead, $draft, '2026-03-05');
}

/** @return list<Instalment> */
function goLiveRows(Contract $contract): array
{
    return $contract->instalments()->orderBy('sequence')->get()->all();
}

function goLivePayBack(User $accountant, Instalment $instalment, string $paidOn): void
{
    app(RecordPayment::class)->handle($accountant, $instalment->fresh(), $instalment->amount, $paidOn, PaymentMethod::BankTransfer, 'UNC nhập lúc go-live', null, null);
}

it('releases at signing exactly the instalment of the stage the import really recorded, dated from that record', function () {
    [$onSigning, $filed, $accepted, $firstInstance] = goLiveRows(goLiveContract($this->matter, $this->lead));

    expect($onSigning->due_date->toDateString())->toBe('2026-03-12')
        ->and($filed->due_date->toDateString())->toBe('2026-04-20')
        // Dòng nhập (15/06) là lần chạm "Toà thụ lý" — +30 ngày.
        ->and($accepted->due_date->toDateString())->toBe('2026-07-15')
        ->and($accepted->triggered_by_stage_log_id)->toBe($this->importLog->id)
        ->and($firstInstance->due_date)->toBeNull()
        ->and($firstInstance->state())->toBe(InstalmentState::Scheduled);
});

it('leaves no false overdue once the money already collected is recorded back-dated, on the donut and in the overdue scope', function () {
    [$onSigning, $filed, $accepted] = goLiveRows(goLiveContract($this->matter, $this->lead));

    goLivePayBack($this->accountant, $onSigning, '2026-03-07');
    goLivePayBack($this->accountant, $filed, '2026-04-25');
    goLivePayBack($this->accountant, $accepted, '2026-07-10');

    expect(Instalment::query()->overdue()->count())->toBe(0);

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin, 'web');

    // Kỳ "năm nay" chứa ngày ký 05/03/2026.
    $data = Livewire::test(ReceivablesDonutWidget::class, ['pageFilters' => ['period' => 'this_year']])->instance();
    $method = new ReflectionMethod($data, 'getData');
    $method->setAccessible(true);

    // [đã thu, còn phải thu chưa tới hạn, quá hạn]
    expect($method->invoke($data)['datasets'][0]['data'])->toBe([70_000_000, 30_000_000, 0]);
});

it('lets the daily reconciliation find nothing to release on an imported matter, and never invent a stage the matter has no record of', function () {
    goLiveContract($this->matter, $this->lead);

    expect(app(ReconcileStageTriggeredInstalments::class)->handle())->toBe(['triggered' => 0, 'failed' => 0]);
});

/**
 * Cái bẫy luật nhập tránh: đợt "khi nộp đơn" nhập là `stage` trên một vụ đã qua bước nộp đơn trước
 * ngày dùng hệ thống không bao giờ đến hạn — không dòng tiến độ nào VÀO `filed` — và đối chiếu hằng
 * ngày không cứu được nó. Nó nằm im ở "chưa đến đợt" trong khi tiền đó lẽ ra đã phải thu.
 */
it('leaves a filed-stage instalment waiting forever on a matter imported past filing, which is why the rule says import it as a due date', function () {
    [, $filed] = goLiveRows(goLiveContract($this->matter, $this->lead, filedRowAs: 'stage'));

    app(ReconcileStageTriggeredInstalments::class)->handle();

    expect(StageLog::query()->where('matter_id', $this->matter->id)->where('to_stage', 'filed')->exists())->toBeFalse()
        ->and($filed->fresh()->due_date)->toBeNull()
        ->and($filed->fresh()->state())->toBe(InstalmentState::Scheduled);
});
