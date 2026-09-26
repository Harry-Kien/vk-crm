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
    // Thứ tự SPEC §7.1: mục 6, tức SAU "Tài liệu chờ duyệt" (mục 3, -2), "Hồ sơ thiếu giấy tờ
    // quá 14 ngày" (mục 4, -1) và "Khách chưa xem cập nhật" (mục 5, 0). Giá trị này từng là -1
    // và trùng với widget mục 4, nên hai widget đứng theo thứ tự Filament tình cờ nạp lớp — xem
    // DashboardWidgetOrderTest.
    //
    // Dời từ 0 lên 1 ở M5 Task 6: `$sort` của Filament là `?int`, nên khi mục 5 ra đời thì giữa
    // -1 và 0 không còn số nguyên nào. Đây là một lần ĐÁNH SỐ LẠI, không phải một thay đổi về
    // thứ tự — vị trí tương đối của mọi widget vẫn đúng SPEC §7.1, và DashboardWidgetOrderTest
    // so sánh theo thứ tự tương đối nên nó đo được đúng điều đó.
    protected static ?int $sort = 1;

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

        // Join trực tiếp thay vì tải toàn bộ model: chỉ cần đếm theo giai đoạn, không cần dữ
        // liệu vụ việc. matter_type_stages.key không phải khoá ngoại độc lập — mỗi loại vụ việc
        // có bộ key riêng — nên join phải khớp cả matter_type_id lẫn key.
        //
        // **Gộp theo (matter_type_id, stage), KHÔNG theo nhãn.** Việc mang sang từ rà soát M3
        // vòng 2: nhãn giai đoạn không duy nhất trong cả hệ thống — nó chỉ duy nhất trong phạm vi
        // một loại vụ việc, và "Chuẩn bị hồ sơ" là cái tên hai loại bất kỳ đều có thể đặt. Gộp
        // theo nhãn cộng chung hai giai đoạn khác hẳn nhau vào một cột, nên con số hiện ra không
        // phải con số của giai đoạn nào cả, và không có gì trên màn hình nói rằng nó là tổng của
        // hai thứ. Nhãn cột vì vậy luôn kèm tên loại vụ việc: một cột mang tên "Chuẩn bị hồ sơ"
        // trơ trọi không trả lời được "của loại nào".
        $counts = Matter::query()
            ->listableBy($user)
            ->open()
            ->join('matter_type_stages', function ($join): void {
                $join->on('matter_type_stages.matter_type_id', '=', 'matters.matter_type_id')
                    ->on('matter_type_stages.key', '=', 'matters.stage')
                    ->whereNull('matter_type_stages.deleted_at');
            })
            ->join('matter_types', 'matter_types.id', '=', 'matter_type_stages.matter_type_id')
            ->selectRaw('matter_types.name as type_name, matter_type_stages.label as stage_label, count(*) as total')
            // MariaDB bật ONLY_FULL_GROUP_BY (sql_mode mặc định từ 10.x), nên mọi cột được CHỌN
            // mà không phải hàm tổng hợp đều phải có mặt ở GROUP BY. Cột dùng để SẮP XẾP cũng
            // vậy, nên matter_types.sort_order nằm trong danh sách dù không được chọn.
            ->groupBy(
                'matter_types.id', 'matter_types.name', 'matter_types.sort_order',
                'matter_type_stages.label', 'matter_type_stages.sort_order',
            )
            // matter_types.sort_order không đảm bảo duy nhất; id là tiêu chí phụ để thứ tự cột
            // luôn xác định, cùng thành ngữ MatterType::stages(). Nói thẳng: một mutation probe
            // gỡ dòng orderBy('matter_types.id') đi vẫn để cả bộ test xanh, vì SQLite lẫn MariaDB
            // hôm nay đều trả về theo id khi sort_order bằng nhau. Dòng này là CHỦ ĐÍCH ("thứ tự
            // tường minh"), không phải một tấm chắn hồi quy đã được chứng minh.
            ->orderBy('matter_types.sort_order')
            ->orderBy('matter_types.id')
            ->orderBy('matter_type_stages.sort_order')
            ->get();

        return [
            'labels' => $counts
                ->map(fn (Matter $row): string => __('widgets.matters_by_stage.stage_label', [
                    'type' => $row->type_name,
                    'stage' => $row->stage_label,
                ]))
                ->all(),
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
