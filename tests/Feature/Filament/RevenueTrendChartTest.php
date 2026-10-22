<?php

use App\Enums\Role;
use App\Filament\Admin\Widgets\Revenue\RevenueByStageWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueOverTimeWidget;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\User;
use App\Support\Charts\MoneyChart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * Biểu đồ "Doanh thu theo tháng/quý/năm" là biểu đồ XU HƯỚNG (2026-10-21, chủ văn phòng: chọn "Theo quý"
 * khi đầu trang đang ở "Tháng này" chỉ ra MỘT cột "Quý 4/2026" phủ kín khung). Khoảng vẽ kết thúc ở cuối kỳ
 * đang chọn và bắt đầu ở đầu năm của ngày đó (tháng, quý) hoặc đầu năm của 4 năm trước (năm) — sớm hơn nếu
 * kỳ tuỳ chọn bắt đầu sớm hơn; kỳ chưa có tiền vẫn có cột 0; cột không bao giờ phình hết khung.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-21 10:00:00');

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->actingAs($this->lawyer, 'web');

    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $contract = Contract::factory()->for($matter)->active()->create([
        'total_amount' => 100_000_000, 'signed_at' => '2022-01-10',
    ]);
    $this->instalment = Instalment::factory()->for($contract)->create([
        'amount' => 100_000_000, 'due_date' => '2026-12-31',
    ]);
});

function rtcPay(Instalment $instalment, User $lawyer, int $amount, string $paidOn): void
{
    Payment::factory()->for($instalment)->create([
        'amount' => $amount, 'paid_on' => $paidOn, 'attributed_lawyer_id' => $lawyer->id,
    ]);
}

/** @return array{data: array<string, mixed>, description: string, rows: list<array{label: string, value: string}>} */
function rtcChart(string $unit, array $pageFilters = ['period' => 'this_month']): array
{
    $component = Livewire::test(RevenueOverTimeWidget::class, ['pageFilters' => $pageFilters])->set('filter', $unit);
    $instance = $component->instance();

    return [
        'data' => (new ReflectionMethod($instance, 'getData'))->invoke($instance),
        'description' => (string) $instance->getDescription(),
        'rows' => $instance->numberTableRows(),
    ];
}

function rtcQuarter(int $quarter, int $year): string
{
    return __('widgets.revenue_dashboard.over_time.quarter_label', ['quarter' => $quarter, 'year' => $year]);
}

it('shows every quarter of the year when "Theo quý" is chosen on "Tháng này", with an empty quarter at zero', function () {
    rtcPay($this->instalment, $this->lawyer, 7_000_000, '2026-05-12');
    rtcPay($this->instalment, $this->lawyer, 5_000_000, '2026-10-02');
    rtcPay($this->instalment, $this->lawyer, 9_000_000, '2025-12-30');

    $chart = rtcChart('quarter');

    expect($chart['data']['labels'])->toBe([rtcQuarter(1, 2026), rtcQuarter(2, 2026), rtcQuarter(3, 2026), rtcQuarter(4, 2026)])
        ->and($chart['data']['datasets'][0]['data'])->toBe([0, 7_000_000, 0, 5_000_000])
        ->and($chart['description'])->toContain('01/01/2026')->toContain('31/10/2026')
        ->and(count($chart['rows']))->toBe(4);
});

it('shows the months from January to the end of the period when "Theo tháng" is chosen on "Tháng này"', function () {
    rtcPay($this->instalment, $this->lawyer, 3_000_000, '2026-02-03');
    rtcPay($this->instalment, $this->lawyer, 4_000_000, '2026-10-20');

    $chart = rtcChart('month');

    expect($chart['data']['labels'])->toBe(['01/2026', '02/2026', '03/2026', '04/2026', '05/2026', '06/2026', '07/2026', '08/2026', '09/2026', '10/2026'])
        ->and($chart['data']['datasets'][0]['data'])->toBe([0, 3_000_000, 0, 0, 0, 0, 0, 0, 0, 4_000_000]);
});

it('shows five years ending in the period year when "Theo năm" is chosen', function () {
    rtcPay($this->instalment, $this->lawyer, 2_000_000, '2022-03-01');
    rtcPay($this->instalment, $this->lawyer, 6_000_000, '2026-07-01');
    rtcPay($this->instalment, $this->lawyer, 1_000_000, '2021-12-31');

    $chart = rtcChart('year');

    expect($chart['data']['labels'])->toBe(['2022', '2023', '2024', '2025', '2026'])
        ->and($chart['data']['datasets'][0]['data'])->toBe([2_000_000, 0, 0, 0, 6_000_000])
        ->and($chart['description'])->toContain('01/01/2022')->toContain('31/10/2026');
});

it('starts at the custom period start when it is earlier than the start of the year', function () {
    rtcPay($this->instalment, $this->lawyer, 8_000_000, '2025-11-15');
    rtcPay($this->instalment, $this->lawyer, 2_000_000, '2025-10-31');

    $chart = rtcChart('month', ['period' => 'custom', 'date_from' => '2025-11-01', 'date_to' => '2026-01-31']);

    expect($chart['data']['labels'])->toBe(['11/2025', '12/2025', '01/2026'])
        ->and($chart['data']['datasets'][0]['data'])->toBe([8_000_000, 0, 0]);
});

it('keeps the bars at a readable width instead of one bar filling the whole chart', function () {
    rtcPay($this->instalment, $this->lawyer, 5_000_000, '2026-10-02');

    expect(rtcChart('quarter')['data']['datasets'][0])->toHaveKey('maxBarThickness');
    expect(rtcChart('quarter')['data']['datasets'][0]['maxBarThickness'])->toBeLessThanOrEqual(64);
});

/**
 * 2026-10-21 (cùng lượt bấm thử của chủ văn phòng): nhãn dài của biểu đồ cột ngang "Tiền thu theo giai đoạn" bị
 * Chart.js cắt mất phần đầu ("…m (ứng khi ký) hợp đồng…"). Nhãn của dạng cột/đường được ngắt thành nhiều dòng;
 * dạng tròn (chú giải) và bảng số giữ nguyên câu.
 */
it('breaks a long by-stage label into short lines on the bar chart, and keeps the whole label in the number table and the pie', function () {
    rtcPay($this->instalment, $this->lawyer, 5_000_000, '2026-10-02');

    $component = Livewire::test(RevenueByStageWidget::class, ['pageFilters' => ['period' => 'this_month']]);
    $instance = $component->instance();
    $bar = (new ReflectionMethod($instance, 'getData'))->invoke($instance);
    $fullLabels = array_column($instance->numberTableRows(), 'label');

    expect($bar['labels'])->not->toBeEmpty();
    foreach ($bar['labels'] as $i => $lines) {
        expect($lines)->toBeArray()
            ->and(implode(' ', $lines))->toBe($fullLabels[$i]);
        foreach ($lines as $line) {
            expect(mb_strlen($line))->toBeLessThanOrEqual(MoneyChart::LABEL_LINE_WIDTH);
        }
    }

    $pie = Livewire::test(RevenueByStageWidget::class, ['pageFilters' => ['period' => 'this_month']])
        ->set('chartKind', 'pie')->instance();

    expect((new ReflectionMethod($pie, 'getData'))->invoke($pie)['labels'])->toBe($fullLabels);
});

it('never splits a single long word, and leaves a short label on one line', function () {
    expect(MoneyChart::wrapLabel('Ngắn'))->toBe(['Ngắn'])
        ->and(MoneyChart::wrapLabel(str_repeat('a', 40)))->toBe([str_repeat('a', 40)])
        ->and(MoneyChart::wrapLabel('Đất đai và bất động sản — Đợt trả trước (ứng khi ký) hợp đồng'))
        ->each->toBeString();
});
