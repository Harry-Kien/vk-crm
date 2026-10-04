<?php

use App\Actions\Billing\ActivateContract;
use App\Actions\Billing\DraftContract;
use App\Actions\TransitionMatterStage;
use App\Enums\InstalmentTrigger;
use App\Enums\Role;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

/**
 * M9 Task 13 — chỗ Task 6 (đợt tự đến hạn khi vụ chạm giai đoạn) gặp Task 10 (khách xem lịch thu trên
 * cổng), đo ở chính trang cổng của khách, qua HTTP.
 *
 * Hai Task được dựng song song ở hai worktree và mỗi bên chỉ thử phần của mình: Task 10 dựng đợt
 * "theo giai đoạn" bằng factory (chưa có listener), Task 6 không mở trang cổng. Tệp này đi đúng đường
 * thật của văn phòng — `DraftContract`, `ActivateContract`, rồi `TransitionMatterStage` (sự kiện
 * `MatterStageChanged` → listener `ReleaseStageTriggeredInstalments` → `TriggerInstalmentsForStage`,
 * không `Event::fake`) — và đọc khối "Hợp đồng và thanh toán" của khách trước và sau lần chuyển:
 * trước, đợt nói "đến hạn N ngày sau khi vụ việc tới bước <nhãn cho khách>"; sau, đúng dòng đó nói
 * ngày đến hạn (và "quá hạn" nếu lần chuyển ghi lùi đủ xa), còn đợt của bước sau vẫn chờ.
 *
 * Màn hình cổng đi qua HTTP; các bước của văn phòng là phần dựng dữ liệu (đã có test màn hình riêng ở
 * Task 4/6/7), nên gọi Action thật.
 *
 * Mốc giờ cố định 2026-10-03 10:00 (múi giờ ứng dụng).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-03 10:00:00'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    // Mã ba chữ → bộ giai đoạn dân sự đầy đủ (`StagePresets::civil()`): … → drafting → filed → …
    $type = MatterType::factory()->withStages()->create(['code' => 'CVP']);

    $client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $client->id]);

    $this->matter = Matter::factory()
        ->for($type, 'matterType')
        ->for($client)
        ->atStage('drafting')
        ->create(['lead_lawyer_id' => $this->lead->id, 'is_published_to_portal' => true]);

    $contract = app(DraftContract::class)->handle($this->lead, $this->matter, ['total_amount' => 100_000_000], [
        ['name' => 'Tạm ứng khi ký hợp đồng', 'amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::OnSigning, 'due_days_after_trigger' => 7],
        ['name' => 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'amount' => 40_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'filed', 'due_days_after_trigger' => 15],
        ['name' => 'Thanh toán đợt 3 khi toà xét xử sơ thẩm', 'amount' => 30_000_000, 'trigger_type' => InstalmentTrigger::Stage, 'trigger_stage_key' => 'first_instance', 'due_days_after_trigger' => 15],
    ]);

    $this->contract = app(ActivateContract::class)->handle($this->lead, $contract, '2026-08-01');

    $this->filedLabel = $type->stage('filed')->client_label;
    $this->firstInstanceLabel = $type->stage('first_instance')->client_label;
});

/** Dòng của một đợt trong khối tiền của trang cổng (từ tên đợt tới tên đợt kế tiếp). */
function stpInstalmentRow(string $html, string $name, ?string $nextName): string
{
    $start = strpos($html, e($name));

    expect($start)->not->toBeFalse();

    $end = $nextName === null ? strpos($html, e(__('portal_progress.billing.payments_heading')), $start) : strpos($html, e($nextName), $start);

    expect($end)->not->toBeFalse();

    return substr($html, $start, (int) $end - $start);
}

function stpPortalHtml(ClientUser $user, Matter $matter): string
{
    Filament::setCurrentPanel('portal');

    return test()->actingAs($user, 'client')
        ->get(MatterProgress::getUrl(['record' => $matter->getKey()], panel: 'portal'))
        ->assertOk()
        ->getContent();
}

function stpMoveTo(Matter $matter, User $actor, string $toStage, string $occurredAt): void
{
    test()->actingAs($actor, 'web');

    app(TransitionMatterStage::class)->handle(
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

it('shows the client a stage instalment as due some days after the matter reaches that step, named by its client label', function () {
    $html = stpPortalHtml($this->clientUser, $this->matter);

    $second = stpInstalmentRow($html, 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'Thanh toán đợt 3 khi toà xét xử sơ thẩm');
    $third = stpInstalmentRow($html, 'Thanh toán đợt 3 khi toà xét xử sơ thẩm', null);

    expect($second)->toContain(e(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => $this->filedLabel])))
        ->toContain(e(__('portal_progress.billing.state.scheduled')))
        ->not->toContain('filed')
        ->and($third)->toContain(e(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => $this->firstInstanceLabel])));
});

it('switches that row to a due date on the client page once the lawyer moves the matter to that step, and leaves the later step waiting', function () {
    stpMoveTo($this->matter, $this->lead, 'filed', '2026-10-03');

    $html = stpPortalHtml($this->clientUser, $this->matter);

    $second = stpInstalmentRow($html, 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'Thanh toán đợt 3 khi toà xét xử sơ thẩm');
    $third = stpInstalmentRow($html, 'Thanh toán đợt 3 khi toà xét xử sơ thẩm', null);

    expect($second)->toContain(e(__('portal_progress.billing.due.on', ['date' => '18/10/2026'])))
        ->toContain(e(__('portal_progress.billing.state.due')))
        ->not->toContain(e($this->filedLabel))
        ->and($third)->toContain(e(__('portal_progress.billing.due.stage_after', ['days' => 15, 'stage' => $this->firstInstanceLabel])))
        ->toContain(e(__('portal_progress.billing.state.scheduled')));
});

it('shows the client an instalment overdue at once when the move to its step is recorded far enough in the past', function () {
    // Nộp đơn ngày 10/09 (ghi lùi), +15 ngày = 25/09 — đã qua hôm nay 03/10: "sự thật, không phải lỗi"
    // (kế hoạch Task 6 điểm 2), và khách thấy đúng sự thật đó ngay lần mở trang kế tiếp.
    stpMoveTo($this->matter, $this->lead, 'filed', '2026-09-10');

    $second = stpInstalmentRow(stpPortalHtml($this->clientUser, $this->matter), 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'Thanh toán đợt 3 khi toà xét xử sơ thẩm');

    expect($second)->toContain(e(__('portal_progress.billing.due.on', ['date' => '25/09/2026'])))
        ->toContain(e(__('portal_progress.billing.state.overdue')));
});

it('keeps the client page in step with the admin record: the due date shown is the one the trigger wrote', function () {
    stpMoveTo($this->matter, $this->lead, 'filed', '2026-10-01');

    $instalment = Contract::query()->findOrFail($this->contract->id)->instalments()->where('trigger_stage_key', 'filed')->sole();

    expect($instalment->due_date->toDateString())->toBe('2026-10-16')
        ->and($instalment->triggered_by_stage_log_id)->not->toBeNull();

    $second = stpInstalmentRow(stpPortalHtml($this->clientUser, $this->matter), 'Thanh toán đợt 2 khi nộp đơn khởi kiện', 'Thanh toán đợt 3 khi toà xét xử sơ thẩm');

    expect($second)->toContain(e(__('portal_progress.billing.due.on', ['date' => $instalment->due_date->format('d/m/Y')])));
});
