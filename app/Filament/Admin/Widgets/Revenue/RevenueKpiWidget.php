<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Widgets\Revenue\Concerns\RequiresBillingView;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\CollectedRevenue;
use App\Support\Billing\Money;
use App\Support\Billing\OpenReceivables;
use App\Support\Billing\RevenueColours;
use App\Support\Billing\RevenueFilters;
use App\Support\Billing\SignedContracts;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Hàng ô số đầu trang Doanh thu (làn rv, 2026-10-15 — chủ văn phòng: "nhiều chữ nhìn rối quá, làm như
 * các CRM chuyên nghiệp"): bốn con số lớn đọc được trong một giây, mỗi ô một nhãn ngắn; định nghĩa nằm
 * sau nút ⓘ của từng ô.
 *
 * **Không công thức thứ hai.** Mỗi ô đọc ĐÚNG truy vấn của biểu đồ / trang cùng định nghĩa:
 *
 *  - **Đã thu** — {@see CollectedRevenue::filtered()}, chung với "Doanh thu theo tháng": tiền về trong
 *    kỳ, luật sư = luật sư phụ trách LÚC THU, kể cả tiền của hợp đồng sau đó bị huỷ.
 *  - **Còn phải thu / Quá hạn** — {@see OpenReceivables::query()}, chung với vành khuyên và cùng nền
 *    {@see BillingSummary::pendingInstalmentsQuery()} của trang "Công nợ": phần CÒN LẠI của mọi đợt
 *    chưa thu trên hợp đồng đang hiệu lực, TẠI HÔM NAY — không theo kỳ (một khoản nợ không "thuộc về"
 *    một kỳ; cùng lý do `ClosedWithBalanceWidget`/`LoadPerLawyerWidget` không đọc kỳ). Luật sư = luật sư
 *    phụ trách HIỆN TẠI. Vì là ảnh chụp, hai ô này không có "so với kỳ trước" (không có ảnh chụp cũ để
 *    so mà không dựng lại lịch sử) — dòng phụ là số đợt kèm "tại hôm nay", in ngay trên ô: lát "Quá hạn"
 *    của vành khuyên cùng màu đỏ chỉ tính hợp đồng ký trong kỳ, nên đổi Kỳ thì vành khuyên đổi còn ô
 *    không — chữ trên ô phải nói vì sao (rà soát cuối làn rv, I1; lệch với "theo Kỳ" của brief §1, chờ
 *    controller xác nhận phán quyết).
 *  - **Hợp đồng ký mới** — {@see SignedContracts::query()}, chung với "Cơ cấu theo lĩnh vực": số hợp đồng
 *    đang hiệu lực/đã hoàn tất ký trong kỳ và tổng giá trị.
 *
 * **So với kỳ trước** — {@see RevenueFilters::previousPeriod()}: cùng khoảng đã qua của kỳ liền trước
 * (hay khoảng dài bằng nhau với "Tuỳ chọn"). ▲ xanh khi tăng, ▼ đỏ khi giảm, "0 %" khi bằng, "—" khi kỳ
 * trước bằng 0 (không chia cho 0, cũng không in "▲ ∞").
 *
 * **Tầm nhìn** — mọi truy vấn trên lấy tập vụ từ `Matter::scopeListableBy($user)`: vụ `restricted`
 * không lọt vào con số của người không xem được nó, luật sư chỉ thấy tiền vụ mình. Ô "Còn phải thu" và
 * "Quá hạn" thành liên kết tới trang "Công nợ" (ô quá hạn đã bật bộ lọc "Quá hạn") CHỈ khi người xem mở
 * được trang đó ({@see Receivables::canBeOpenedBy()}); luật sư thì không.
 */
class RevenueKpiWidget extends Widget
{
    use InteractsWithPageFilters;
    use RequiresBillingView;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.kpi-cards';

    protected int|string|array $columnSpan = 'full';

    /** @var list<array<string, mixed>>|null */
    private ?array $cardsCache = null;

    /**
     * Hook `boot` của Livewire: chạy trước `mount()` và trước MỌI request sau đó — 404 cho người không qua
     * `canView()` (`billing.view`), kể cả khi widget được mount thẳng, không chỉ ẩn khỏi trang.
     */
    public function boot(): void
    {
        abort_unless(static::canView(), 404);
    }

    /**
     * Bốn ô theo thứ tự hiển thị. Mỗi ô: `key`, `label`, `value` (đã định dạng), `amount` (số thô — đồng,
     * hay số hợp đồng với ô ký mới), `detail` (dòng phụ hay null), `delta` (`direction` up/down/flat/null
     * và `text`) hay null, `comparison` (khoảng so sánh) hay null, `url` hay null, `accent` (mã màu),
     * `tone` (`danger` khi con số là điều cần xử lý) và `explanation` (chữ của nút ⓘ).
     *
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        return $this->cardsCache ??= $this->computeCards();
    }

    /** @return list<array<string, mixed>> */
    private function computeCards(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $previous = $filters->previousPeriod();
        $comparison = __('widgets.revenue_dashboard.kpi.comparison', ['range' => $previous->rangeLabel()]);
        // Rà soát gộp làn rv (2026-10-16): trang "Công nợ" không lọc theo luật sư hay lĩnh vực, nên khi trang này
        // đang lọc một trong hai, ô "Còn phải thu"/"Quá hạn" không dẫn sang đó (số trên ô và tổng của trang mở ra
        // sẽ khác nhau mà không màn hình nào nói vì sao). Câu ⓘ nói rõ điều này.
        $narrowed = $filters->lawyerId !== null || $filters->practiceAreaId !== null;
        $receivablesUrl = ! $narrowed && Receivables::canBeOpenedBy($user) ? Receivables::getUrl(panel: 'admin') : null;

        $collected = (int) CollectedRevenue::filtered($user, $filters, $filters->bounds())->sum('amount');
        $collectedBefore = (int) CollectedRevenue::filtered($user, $filters, $previous->bounds())->sum('amount');

        $outstanding = BillingSummary::sumOutstanding(OpenReceivables::query($user, $filters));
        $outstandingCount = OpenReceivables::query($user, $filters)->count();
        $overdue = BillingSummary::sumOutstanding(OpenReceivables::query($user, $filters)->overdue());
        $overdueCount = OpenReceivables::query($user, $filters)->overdue()->count();

        $signedCount = SignedContracts::query($user, $filters, $filters->bounds())->count();
        $signedAmount = (int) SignedContracts::query($user, $filters, $filters->bounds())->sum('contracts.total_amount');
        $signedCountBefore = SignedContracts::query($user, $filters, $previous->bounds())->count();

        return [
            [
                'key' => 'collected',
                'label' => __('widgets.revenue_dashboard.kpi.collected.label'),
                'value' => Money::format($collected),
                'amount' => $collected,
                'detail' => null,
                'delta' => self::delta($collected, $collectedBefore),
                'comparison' => $comparison,
                'url' => null,
                'accent' => RevenueColours::COLLECTED,
                'tone' => null,
                'explanation' => __('widgets.revenue_dashboard.kpi.collected.explanation', ['range' => $filters->rangeLabel()]),
            ],
            [
                'key' => 'outstanding',
                'label' => __('widgets.revenue_dashboard.kpi.outstanding.label'),
                'value' => Money::format($outstanding),
                'amount' => $outstanding,
                'detail' => __('widgets.revenue_dashboard.kpi.outstanding.detail', ['count' => $outstandingCount]),
                'delta' => null,
                'comparison' => null,
                'url' => $receivablesUrl,
                'accent' => RevenueColours::OUTSTANDING,
                'tone' => null,
                'explanation' => __('widgets.revenue_dashboard.kpi.outstanding.explanation'),
            ],
            [
                'key' => 'overdue',
                'label' => __('widgets.revenue_dashboard.kpi.overdue.label'),
                'value' => Money::format($overdue),
                'amount' => $overdue,
                'detail' => __('widgets.revenue_dashboard.kpi.overdue.detail', ['count' => $overdueCount]),
                'delta' => null,
                'comparison' => null,
                'url' => $receivablesUrl === null ? null : Receivables::overdueListUrl(),
                'accent' => RevenueColours::OVERDUE,
                'tone' => $overdue > 0 ? 'danger' : null,
                'explanation' => __('widgets.revenue_dashboard.kpi.overdue.explanation'),
            ],
            [
                'key' => 'signed',
                'label' => __('widgets.revenue_dashboard.kpi.signed.label'),
                'value' => __('widgets.revenue_dashboard.kpi.signed.value', ['count' => $signedCount]),
                'amount' => $signedCount,
                'detail' => __('widgets.revenue_dashboard.kpi.signed.detail', ['amount' => Money::format($signedAmount)]),
                'delta' => self::delta($signedCount, $signedCountBefore),
                'comparison' => $comparison,
                'url' => null,
                'accent' => 'var(--gray-400)',
                'tone' => null,
                'explanation' => __('widgets.revenue_dashboard.kpi.signed.explanation', ['range' => $filters->rangeLabel()]),
            ],
        ];
    }

    /**
     * Phần trăm thay đổi so với kỳ trước, làm tròn tới đơn vị. Kỳ trước bằng 0 thì "—" (không có gốc để
     * chia), bằng nhau thì "0 %".
     *
     * @return array{direction: ?string, text: string}
     */
    private static function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['direction' => null, 'text' => '—'];
        }

        $percent = (int) round(abs($current - $previous) / $previous * 100);

        return match (true) {
            $current > $previous && $percent > 0 => ['direction' => 'up', 'text' => '▲ '.number_format($percent, 0, ',', '.').' %'],
            $current < $previous && $percent > 0 => ['direction' => 'down', 'text' => '▼ '.number_format($percent, 0, ',', '.').' %'],
            default => ['direction' => 'flat', 'text' => '0 %'],
        };
    }
}
