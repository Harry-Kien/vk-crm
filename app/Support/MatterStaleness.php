<?php

namespace App\Support;

use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Quá hạn cập nhật cho khách" (SPEC §6.4, §7.1 mục 1, §7.2) — MỘT định nghĩa dùng chung giữa
 * {@see StaleMattersWidget} (danh sách trang chủ, lọc bằng SQL) và
 * {@see MattersTable} (cột tô màu trên danh sách vụ
 * việc, tính trên từng bản ghi đã có trong bộ nhớ). M6.5 Task 5, finding `stage/stage-09`: trước
 * bản sửa này hai nơi tự viết lại luật theo hai cách khác nhau — cột danh sách tô đỏ CẢ vụ đã
 * đóng lẫn vụ chưa công bố portal (widget thì loại cả hai), và cột danh sách không tô gì cho một
 * vụ CHƯA TỪNG cập nhật cho khách (widget coi đó là ca xấu nhất, dùng `stage_entered_at` làm đồng
 * hồ dự phòng — xem docblock cũ của `StaleMattersWidget::table()`).
 *
 * Ba điều kiện, đúng SPEC §6.4 "vụ việc chưa đóng" + đã công bố portal (khách không có đường nào
 * đọc một tiến độ khi cổng chưa bật) + đồng hồ quá hạn:
 *
 *  1. {@see Matter::scopeOpen()} — R8, đúng MỘT định nghĩa "đang mở" toàn hệ thống;
 *  2. `is_published_to_portal = true`;
 *  3. `COALESCE(last_client_update_at, stage_entered_at)` cũ hơn {@see self::DANGER_AFTER_DAYS}
 *     ngày — đồng hồ dự phòng vì SPEC §6.4 không nói rõ tính từ đâu khi CHƯA từng có
 *     `last_client_update_at`; `stage_entered_at` là mốc SPEC §6.4/§7.1 đã dùng cho khái niệm
 *     "kẹt ở một chỗ bao lâu" (xem docblock `TransitionMatterStage`).
 *
 * Hai hàm, hai hình dạng của CÙNG một luật: {@see self::scopeStale()} lọc bằng SQL cho một bảng
 * (widget, nhiều bản ghi, không muốn tải hết về PHP), {@see self::color()} tính trên một bản ghi
 * ĐÃ có trong tay (cột của `MattersTable`, một dòng tại một thời điểm). Ngưỡng ngày dùng chung
 * một hằng số ở cả hai, nên đổi số ngày chỉ đổi một chỗ.
 */
final class MatterStaleness
{
    /** SPEC §7.2: tô vàng khi cập nhật cuối cũ hơn 10 ngày. */
    public const WARNING_AFTER_DAYS = 10;

    /** SPEC §6.4/§7.1 mục 1/§7.2: quá hạn (đỏ) khi cũ hơn 14 ngày. */
    public const DANGER_AFTER_DAYS = 14;

    /**
     * @param  Builder<Matter>  $query
     * @return Builder<Matter>
     */
    public static function scopeStale(Builder $query): Builder
    {
        return $query
            ->open()
            ->where('is_published_to_portal', true)
            ->whereRaw(
                'COALESCE(last_client_update_at, stage_entered_at) < ?',
                [now()->subDays(self::DANGER_AFTER_DAYS)],
            );
    }

    /**
     * Màu của cột "cập nhật gần nhất cho khách" cho MỘT bản ghi đã tải — cùng ba điều kiện của
     * {@see self::scopeStale()}, viết lại bằng thuộc tính thay vì bằng `where`, vì `MattersTable`
     * tô màu từng dòng đã có sẵn chứ không lọc lại một truy vấn.
     */
    public static function color(Matter $matter): ?string
    {
        if (! $matter->isOpen() || ! $matter->is_published_to_portal) {
            return null;
        }

        $clock = $matter->last_client_update_at ?? $matter->stage_entered_at;

        if ($clock === null) {
            return null;
        }

        $daysSinceUpdate = $clock->diffInDays(now());

        return match (true) {
            $daysSinceUpdate > self::DANGER_AFTER_DAYS => 'danger',
            $daysSinceUpdate > self::WARNING_AFTER_DAYS => 'warning',
            default => null,
        };
    }
}
