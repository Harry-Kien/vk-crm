<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Permission;
use App\Models\Matter;
use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §7.1 mục 6: "Thống kê nhanh" — số vụ đang mở theo giai đoạn, biểu đồ cột ngang.
 * Chỉ đếm (không lộ tiêu đề/nội dung vụ việc) nên mở rộng hơn StaleMattersWidget: hiện cho ai có
 * matter.view HOẶC matter.viewAny (kế toán chỉ có viewAny, vẫn xem được thống kê tổng quát).
 */
class MattersByStageWidget extends ChartWidget
{
    protected static ?int $sort = -1;

    public function getHeading(): string|Htmlable|null
    {
        return __('widgets.matters_by_stage.heading');
    }

    public function getDescription(): string|Htmlable|null
    {
        return __('widgets.matters_by_stage.description');
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->can(Permission::MatterView->value) || $user->can(Permission::MatterViewAny->value));
    }

    protected function getData(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return ['labels' => [], 'datasets' => []];
        }

        // Join trực tiếp thay vì tải toàn bộ model: chỉ cần đếm theo nhãn giai đoạn, không cần
        // dữ liệu vụ việc. matter_type_stages.key không phải khoá ngoại độc lập — mỗi loại vụ
        // việc có bộ key riêng — nên join phải khớp cả matter_type_id lẫn key.
        $counts = Matter::query()
            ->listableBy($user)
            ->whereNull('matters.closed_at')
            ->join('matter_type_stages', function ($join): void {
                $join->on('matter_type_stages.matter_type_id', '=', 'matters.matter_type_id')
                    ->on('matter_type_stages.key', '=', 'matters.stage')
                    ->whereNull('matter_type_stages.deleted_at');
            })
            ->selectRaw('matter_type_stages.label as stage_label, matter_type_stages.sort_order as sort_order, count(*) as total')
            ->groupBy('matter_type_stages.label', 'matter_type_stages.sort_order')
            ->orderBy('matter_type_stages.sort_order')
            ->get();

        return [
            'labels' => $counts->pluck('stage_label')->all(),
            'datasets' => [
                [
                    'label' => __('widgets.matters_by_stage.heading'),
                    'data' => $counts->pluck('total')->all(),
                    'backgroundColor' => '#64748b',
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** Cột ngang (SPEC §7.1 mục 6): trục Chart.js đảo chiều bằng indexAxis: 'y'. */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
