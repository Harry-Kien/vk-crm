<?php

namespace App\Filament\Admin\Widgets;

use App\Models\SystemHealth;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * SPEC §7.1 mục 7 / §2 "Giám sát cron": dải đỏ khi lịch chạy tự động đã chết.
 *
 * ĐÍNH CHÍNH CÓ NGÀY cho SPEC §7.1 (2026-09-23), cùng lập luận đã ghi cho hàng ô số: §7.1 đánh
 * số widget này thứ bảy, nhưng nó không phải một widget trong danh sách — nó là một DẢI cảnh báo,
 * và chính SPEC gọi nó là "dải đỏ". Một cảnh báo rằng hệ thống nhắc việc đã chết mà nằm dưới đáy
 * trang thì vô dụng, vì thứ nó cảnh báo chính là thứ khiến người ta không cuộn xuống. Nên nó đứng
 * trên cùng, và chỉ hiện khi có chuyện — im lặng khi mọi thứ bình thường.
 *
 * BA TRẠNG THÁI, không phải hai. "Chưa bao giờ chạy" là trạng thái một lần triển khai mới thật sự
 * gặp: hệ thống vừa dựng xong, dòng cron chưa cắm, và một cách viết ngây thơ sẽ im lặng vì `null`
 * không lớn hơn ngưỡng nào cả. Đó là lúc cảnh báo cần thiết nhất.
 */
class SystemHealthWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.system-health';

    // Trên cả hàng ô số (-5), vì đây là cảnh báo chứ không phải thông tin.
    protected static ?int $sort = -6;

    protected int|string|array $columnSpan = 'full';

    /**
     * Chỉ nhân sự nội bộ. Không hỏi quyền cụ thể nào: đây là tình trạng vận hành của hệ thống,
     * không phải dữ liệu hồ sơ, và người cần thấy nó nhất có thể là người ít quyền nhất đang
     * ngồi ở văn phòng vào sáng thứ Hai.
     */
    public static function canView(): bool
    {
        return Auth::user() instanceof User;
    }

    public function getViewData(): array
    {
        $health = SystemHealth::query()->where('singleton', 1)->first();

        return [
            // Chưa có dòng nào cũng là "chưa bao giờ chạy": bảng rỗng và cột rỗng nói cùng một
            // chuyện với người đọc, nên màn hình phải nói cùng một câu.
            'neverRan' => $health === null || $health->scheduleHasNeverRun(),
            'stale' => $health !== null && $health->scheduleIsStale(),
            'lastRunAt' => $health?->last_schedule_run_at,
            'staleAfterMinutes' => SystemHealth::STALE_AFTER_MINUTES,
        ];
    }
}
