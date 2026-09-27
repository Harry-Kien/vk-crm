<?php

namespace App\Filament\Admin\Widgets\Revenue;

use App\Models\Matter;
use App\Models\User;
use App\Support\Billing\BillingSummary;
use App\Support\Billing\Money;
use App\Support\Billing\RevenueFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * "Hồ sơ đã kết thúc còn công nợ" — bảng, KHÔNG phải biểu đồ (kế hoạch M9, "Tiền trên một vụ việc
 * đã đóng…"): vụ việc `closed_at` khác null mà còn công nợ KHÔNG bị chặn đóng hồ sơ, nhưng phải
 * nhìn thấy được — đây là nơi thấy nó trên trang doanh thu.
 *
 * **KHÔNG phụ thuộc bộ lọc thời gian của trang.** Một khoản nợ trên hồ sơ đã đóng không "thuộc về"
 * một kỳ nào — nó tồn tại tới khi thu xong hoặc được miễn tường minh, bất kể hồ sơ đóng từ khi
 * nào. Lọc theo kỳ sẽ khiến một khoản nợ CŨ biến mất khỏi danh sách an toàn này chỉ vì người xem
 * đang xem "tháng này" — đúng tác dụng phụ tệ nhất có thể xảy ra với một lưới an toàn. Bộ lọc
 * luật sư (hiện tại) và lĩnh vực vẫn áp dụng — chúng thu hẹp PHẠM VI vụ việc, không phải THỜI
 * ĐIỂM.
 *
 * **`BillingSummary::outstandingPerMatterExpression()`** — cùng SQL-aggregate outstanding của
 * Task 8, một round-trip cho toàn bộ danh sách, không N+1 theo số hồ sơ đã đóng.
 */
class ClosedWithBalanceWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    public function getTableHeading(): string
    {
        return __('widgets.revenue_dashboard.closed_with_balance.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('widgets.revenue_dashboard.closed_with_balance.heading'))
            ->description(__('widgets.revenue_dashboard.closed_with_balance.description'))
            ->query(fn (): Builder => $this->rowsQuery())
            ->emptyStateHeading(__('widgets.revenue_dashboard.closed_with_balance.empty_heading'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('widgets.revenue_dashboard.closed_with_balance.columns.matter_code')),
                TextColumn::make('client.name')
                    ->label(__('widgets.revenue_dashboard.closed_with_balance.columns.client')),
                TextColumn::make('closed_at')
                    ->label(__('widgets.revenue_dashboard.closed_with_balance.columns.closed_at'))
                    ->date('d/m/Y'),
                TextColumn::make('outstanding_amount')
                    ->label(__('widgets.revenue_dashboard.closed_with_balance.columns.outstanding'))
                    ->state(fn (Matter $record): string => Money::format((int) $record->getAttribute('outstanding_amount'))),
            ])
            ->paginated([5, 10, 25]);
    }

    private function rowsQuery(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return Matter::query()->whereRaw('1 = 0');
        }

        $filters = RevenueFilters::fromPageFilters($this->pageFilters);

        return Matter::query()
            ->listableBy($user)
            ->whereNotNull('closed_at')
            ->when($filters->practiceAreaId, fn (Builder $q, int $v) => $q->where('matter_type_id', $v))
            ->when($filters->lawyerId, fn (Builder $q, int $v) => $q->where('lead_lawyer_id', $v))
            // `whereRaw`, không `havingRaw` trên bí danh: HAVING không GROUP BY tham chiếu một
            // bí danh không phải hàm tổng hợp là hành vi không chuẩn hoá giữa SQLite và MariaDB
            // (dự án chạy test trên cả hai — xem docblock `BillingSummary::outstandingExpression()`
            // về cùng mối lo). Lặp lại biểu thức trong `WHERE` là SQL chuẩn cả hai hiểu giống nhau.
            ->whereRaw('('.BillingSummary::outstandingPerMatterExpression().') > 0')
            ->selectRaw('matters.*, ('.BillingSummary::outstandingPerMatterExpression().') as outstanding_amount')
            ->with('client')
            ->orderByDesc('outstanding_amount');
    }
}
