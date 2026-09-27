<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Enums\ContractStatus;
use App\Enums\InstalmentStatus;
use App\Filament\Admin\Widgets\Revenue\Concerns\HasMoneyNumberTable;
use App\Filament\Admin\Widgets\Revenue\Concerns\RequiresBillingView;
use App\Models\Contract;
use App\Models\Instalment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use App\Support\Scopes\ClientPortalScope;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Đã thu / còn phải thu / quá hạn" — vành khuyên, BA lát, kèm một bảng số (M9 Task 9). Nơi DUY
 * NHẤT trên trang này một biểu đồ tròn xứng đáng: một tổng thể (giá trị đã ký trong kỳ của hợp
 * đồng `active`/`completed`, trừ phần đã miễn còn lại thật sự), ba phần cộng đúng lại.
 *
 * **Thời gian lọc `contracts.signed_at`** — "việc đã ký trong kỳ" — KHÔNG phải `payments.paid_on`.
 * Ba lát vẫn tính TẠI HÔM NAY.
 *
 * # C1 (Fix round 1, Critical) — "còn phải thu"/"quá hạn" không còn là một công thức riêng
 *
 * Bản trước tính `not_yet_due = signed - waived - collected - overdue` bằng phép trừ tay, sai hai
 * cách: (a) không lọc trạng thái hợp đồng, nên một hợp đồng đã `cancelled` vẫn góp phần dư vào
 * "chưa tới hạn" dù `BillingSummary` coi nó là 0; (b) trừ NGUYÊN GIÁ TRỊ MẶT của đợt đã miễn, nên
 * một đợt đã thu một phần rồi mới miễn bị trừ hai lần (vừa trừ qua "waived", vừa trừ qua phần đã
 * "collected" của chính nó).
 *
 * Sửa: "còn phải thu"/"quá hạn" đọc THẲNG từ {@see BillingSummary::pendingInstalmentsQuery()} (chỉ
 * hợp đồng `active`, đúng một công thức toàn dự án) và {@see Instalment::scopeOverdue()} — không
 * công thức nào khác. `not_yet_due = outstanding - overdue`, và phép trừ này AN TOÀN vì tập hợp
 * "quá hạn" là một TẬP CON chặt của tập hợp "còn phải thu" (cùng `pendingInstalmentsQuery()`, cộng
 * đúng hai điều kiện: `due_date < hôm nay` và `chưa thu đồng nào` — không đợt nào vừa "quá hạn" vừa
 * nằm ngoài "còn phải thu").
 *
 * **Quần thể của phần đối chiếu (`signed`/`written_off`/`cancelled`) là hợp đồng `active` +
 * `completed`.** Hợp đồng `cancelled` không bao giờ tính vào công nợ (khớp `BillingSummary`, vốn
 * chỉ đọc `active`) — giá trị đã ký của nó hiện ở một dòng RIÊNG trong bảng số
 * (`donut.table.cancelled_total`), không lẫn vào ba lát.
 *
 * **`written_off`** (nhãn "Đã miễn") là PHẦN CÒN LẠI thật sự bị xoá — `amount - đã thu` của mỗi đợt
 * `waived`, KHÔNG phải nguyên giá trị mặt của đợt — sửa đúng lỗi (b). Với hợp đồng active/completed
 * trong kỳ: `collected + outstanding + written_off = signed` là một đẳng thức CẤU TRÚC (mỗi đồng
 * của `total_amount` hoặc đã về, hoặc còn treo (`pending`), hoặc đã bị xoá (`waived`) — ba khả năng
 * loại trừ lẫn nhau, cộng `cancelled` các đợt không tính).
 *
 * # I2 (Fix round 1) — bộ lọc luật sư mang HAI NGHĨA, đúng P2
 *
 * **"Đã thu" lọc theo `payments.attributed_lawyer_id`** (luật sư phụ trách LÚC THU) — KHÔNG theo
 * `matters.lead_lawyer_id` như bản trước. **"Còn phải thu"/"quá hạn" lọc theo
 * `matters.lead_lawyer_id`** (luật sư phụ trách HIỆN TẠI). Khi có bộ lọc luật sư, `getDescription()`
 * in RÕ cả hai nghĩa. Dòng đối chiếu tổng ("ba lát cộng đúng…") chỉ hiện khi KHÔNG lọc luật sư —
 * khi lọc, hai lát dùng hai tập vụ việc khác nhau (một theo ai đã ghi khoản thu, một theo ai đang
 * phụ trách hôm nay), nên tổng không còn là "giá trị đã ký của MỘT tập vụ việc" nữa, và một dòng
 * đối chiếu lúc đó sẽ so sánh hai thứ không cùng bản chất.
 *
 * Bộ ba màu đã kiểm chứng máy (kế hoạch M9): đã thu `#0ca30c`, còn phải thu chưa tới hạn `#4a73bd`,
 * quá hạn `#d03b3b`.
 */
class ReceivablesDonutWidget extends ChartWidget
{
    use HasMoneyNumberTable;
    use InteractsWithPageFilters;
    use RequiresBillingView;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.admin.widgets.revenue.chart-with-table';

    /** Tránh tính hai lần khi cả `getData()` lẫn `numberTableRows()` cùng đọc (Fix round 1). */
    private ?array $amountsCache = null;

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.revenue_dashboard.donut.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $description = __('widgets.revenue_dashboard.donut.description', ['range' => $filters->rangeLabel()]);

        if ($filters->lawyerId !== null) {
            $description .= ' '.__('widgets.revenue_dashboard.donut.description_lawyer_filtered');
        }

        return $description;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function numberTableRows(): array
    {
        $amounts = $this->amounts();

        $rows = [
            ['label' => __('widgets.revenue_dashboard.donut.table.collected'), 'value' => Money::format($amounts['collected'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.not_yet_due'), 'value' => Money::format($amounts['not_yet_due'])],
            ['label' => __('widgets.revenue_dashboard.donut.table.overdue'), 'value' => Money::format($amounts['overdue'])],
        ];

        // I2: dòng đối chiếu tổng chỉ có nghĩa khi cả ba lát cùng một tập vụ việc — tức không lọc
        // luật sư (xem docblock lớp).
        if (! $amounts['lawyer_filtered']) {
            $rows[] = ['label' => __('widgets.revenue_dashboard.donut.table.signed_total'), 'value' => Money::format($amounts['signed'])];
            $rows[] = ['label' => __('widgets.revenue_dashboard.donut.table.written_off'), 'value' => Money::format($amounts['written_off'])];
            $rows[] = ['label' => __('widgets.revenue_dashboard.donut.table.cancelled_total'), 'value' => Money::format($amounts['cancelled'])];
        }

        return $rows;
    }

    protected function getData(): array
    {
        $amounts = $this->amounts();

        return [
            'labels' => [
                __('widgets.revenue_dashboard.donut.slices.collected', ['amount' => Money::format($amounts['collected'])]),
                __('widgets.revenue_dashboard.donut.slices.not_yet_due', ['amount' => Money::format($amounts['not_yet_due'])]),
                __('widgets.revenue_dashboard.donut.slices.overdue', ['amount' => Money::format($amounts['overdue'])]),
            ],
            'datasets' => [
                [
                    'data' => [$amounts['collected'], $amounts['not_yet_due'], $amounts['overdue']],
                    'backgroundColor' => ['#0ca30c', '#4a73bd', '#d03b3b'],
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
        ];
    }

    /**
     * @return array{signed: int, cancelled: int, written_off: int, collected: int, not_yet_due: int, overdue: int, lawyer_filtered: bool}
     */
    private function amounts(): array
    {
        return $this->amountsCache ??= $this->computeAmounts();
    }

    /** @return array{signed: int, cancelled: int, written_off: int, collected: int, not_yet_due: int, overdue: int, lawyer_filtered: bool} */
    private function computeAmounts(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return ['signed' => 0, 'cancelled' => 0, 'written_off' => 0, 'collected' => 0, 'not_yet_due' => 0, 'overdue' => 0, 'lawyer_filtered' => false];
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);
        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();
        $reconciledStatuses = [ContractStatus::Active->value, ContractStatus::Completed->value];

        // An ninh (listableBy) + lĩnh vực: ÁP DỤNG ĐỀU cho cả ba lát. KHÔNG gồm bộ lọc luật sư —
        // I2: mỗi lát tự quyết định NGHĨA của bộ lọc luật sư, ngay bên dưới.
        $baseMatterScope = fn (Builder $q): Builder => $q
            ->listableBy($user)
            ->when($filters->practiceAreaId, fn (Builder $q2, int $v) => $q2->where('matter_type_id', $v));

        // "Còn phải thu"/"quá hạn": luật sư phụ trách HIỆN TẠI của vụ.
        $outstandingMatterScope = fn (Builder $q): Builder => $baseMatterScope($q)
            ->when($filters->lawyerId, fn (Builder $q2, int $v) => $q2->where('lead_lawyer_id', $v));

        $contractPeriodScope = fn (Builder $q): Builder => $q
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereBetween('signed_at', [$from, $to]);

        $signed = (int) Contract::query()
            ->tap($contractPeriodScope)
            ->whereIn('status', $reconciledStatuses)
            ->whereHas('matter', $baseMatterScope)
            ->sum('total_amount');

        $cancelled = (int) Contract::query()
            ->tap($contractPeriodScope)
            ->where('status', ContractStatus::Cancelled->value)
            ->whereHas('matter', $baseMatterScope)
            ->sum('total_amount');

        // Phần THẬT SỰ bị xoá của một đợt đã miễn: amount trừ đã thu — KHÔNG nguyên giá trị mặt
        // (sửa lỗi (b), xem docblock lớp). `.first()` đọc bí danh selectRaw trực tiếp — KHÔNG
        // `.sum()`: aggregate() của query builder loại bỏ mọi cột SELECT hiện có (kể cả bí danh
        // này) trước khi chạy, nên `.sum('agg')` trên một cột chỉ tồn tại qua selectRaw sẽ vỡ vì
        // cột đó không còn trong câu SELECT bị viết lại.
        $writtenOffRow = Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('status', InstalmentStatus::Waived->value)
            ->whereHas('contract', fn (Builder $q) => $contractPeriodScope($q)
                ->whereIn('status', $reconciledStatuses)
                ->whereHas('matter', $baseMatterScope))
            ->selectRaw('coalesce(sum(amount - ('.BillingSummary::collectedExpression().')), 0) as agg')
            ->first();
        $writtenOff = (int) ($writtenOffRow?->agg ?? 0);

        $collected = (int) Payment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('voided_at')
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('attributed_lawyer_id', $v))
            ->whereHas('instalment.contract', fn (Builder $q) => $contractPeriodScope($q)
                ->whereIn('status', $reconciledStatuses)
                ->whereHas('matter', $baseMatterScope))
            ->sum('amount');

        // C1: còn phải thu — CHỈ từ BillingSummary::pendingInstalmentsQuery() (chỉ hợp đồng
        // `active`, đúng MỘT công thức toàn dự án).
        //
        // **`fromSub()->sum()`, KHÔNG `.get()->sum()`** (Fix round 2, minor): bản trước NẠP HẾT
        // mọi đợt còn công nợ thành model Eloquent chỉ để cộng một cột trong PHP — đúng chi phí mà
        // `BillingSummary::pendingInstalmentsQuery()` được viết ra để TRÁNH (một round-trip TÍNH
        // SẴN bằng SQL, không phải kéo dữ liệu về rồi tính tay). `outstanding_amount` là một bí
        // danh `selectRaw` nên không gọi thẳng `.sum('outstanding_amount')` được (xem docblock
        // `$writtenOffRow`: `aggregate()` của query builder xoá sạch SELECT hiện có trước khi
        // chạy) — bọc câu truy vấn Eloquent làm một BẢNG CON (`fromSub`, Laravel chấp nhận thẳng
        // một `Illuminate\Database\Eloquent\Builder`, xem `Query\Builder::parseSub()`) rồi mới
        // `.sum()` trên bảng con đó: cộng vẫn chạy trong CSDL, không một Instalment nào được hydrate.
        $outstanding = (int) DB::query()
            ->fromSub(
                BillingSummary::pendingInstalmentsQuery()
                    ->whereHas('contract', fn (Builder $q) => $contractPeriodScope($q)->whereHas('matter', $outstandingMatterScope)),
                'pending',
            )
            ->sum('outstanding_amount');

        $overdue = (int) Instalment::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->overdue()
            ->whereHas('contract', fn (Builder $q) => $contractPeriodScope($q)->whereHas('matter', $outstandingMatterScope))
            ->sum('amount');

        // An toàn: tập "quá hạn" là tập con chặt của tập "còn phải thu" (xem docblock lớp), nên
        // phép trừ này không bao giờ cần kẹp ở 0 trên dữ liệu hợp lệ — vẫn kẹp để không âm nếu một
        // ngày điều đó đổi.
        $notYetDue = max(0, $outstanding - $overdue);

        return [
            'signed' => $signed,
            'cancelled' => $cancelled,
            'written_off' => $writtenOff,
            'collected' => $collected,
            'not_yet_due' => $notYetDue,
            'overdue' => $overdue,
            'lawyer_filtered' => $filters->lawyerId !== null,
        ];
    }
}
