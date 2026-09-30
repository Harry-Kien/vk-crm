<?php

namespace App\Support;

use App\Actions\Schedule\CheckStaleMatters;
use App\Filament\Admin\Resources\Matters\Tables\MattersTable;
use App\Filament\Admin\Widgets\StaleMattersWidget;
use App\Models\Matter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
 *
 * # M6 Task 7 — mốc 21 ngày (email) và "đợt đình trệ", cộng vào lớp này thay vì viết lại đồng hồ
 *
 * {@see CheckStaleMatters} (SPEC §6.4) cần hỏi thêm hai câu mà `scopeStale()`/`color()` chưa trả
 * lời: "đã qua 21 ngày (mốc gửi email) chưa" và "đợt đình trệ hiện tại bắt đầu từ lúc nào" (để
 * chống gửi trùng theo `outbound_messages`/`notifications`, R3 của kế hoạch M6 — không thêm cột
 * "đã nhắc lúc nào" ở đâu). Cả hai câu dùng ĐÚNG một đồng hồ `COALESCE(last_client_update_at,
 * stage_entered_at)` mà hai hàm trên đã dùng — {@see self::clock()} là nơi DUY NHẤT đọc nó, rồi
 * {@see self::color()} và {@see self::daysSinceUpdate()} (nên gián tiếp là
 * {@see self::olderThan()}) đều gọi qua nó, thay vì mỗi hàm tự viết lại `?? ` một lần nữa.
 * {@see self::olderThan()} KHÔNG tự hỏi lại `isOpen()`/`is_published_to_portal` — nó chỉ trả lời
 * phần "bao nhiêu ngày", cùng cách `CheckDeadlines::tierFor()` chỉ lo bậc chứ không lo lại điều
 * kiện "vụ có còn mở không"; gọi nó sau khi `scopeStale()` đã xác nhận vụ ĐANG quá hạn.
 */
final class MatterStaleness
{
    /** SPEC §7.2: tô vàng khi cập nhật cuối cũ hơn 10 ngày. */
    public const WARNING_AFTER_DAYS = 10;

    /** SPEC §6.4/§7.1 mục 1/§7.2: quá hạn (đỏ) khi cũ hơn 14 ngày. */
    public const DANGER_AFTER_DAYS = 14;

    /** SPEC §6.4 — mốc gửi thư `staff.stale_matter` (luật sư phụ trách + supervisorsFor()). */
    public const EMAIL_AFTER_DAYS = 21;

    /** R5 của kế hoạch M6 — "không quá một thư staff.stale_matter mỗi 7 ngày". */
    public const EMAIL_REPEAT_DAYS = 7;

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

        $daysSinceUpdate = self::daysSinceUpdate($matter);

        if ($daysSinceUpdate === null) {
            return null;
        }

        return match (true) {
            $daysSinceUpdate > self::DANGER_AFTER_DAYS => 'danger',
            $daysSinceUpdate > self::WARNING_AFTER_DAYS => 'warning',
            default => null,
        };
    }

    /**
     * Số ngày kể từ đồng hồ dùng chung — `null` khi vụ CHƯA TỪNG có cả `last_client_update_at`
     * LẪN `stage_entered_at` (chưa từng xảy ra thật, `Matter::booted()` luôn gán
     * `stage_entered_at` lúc tạo — nhưng không có gì đảm bảo một bản ghi cũ hay dựng thủ công
     * trong test luôn có nó).
     *
     * SỐ THỰC, không cắt cụt: `diffInDays()` của Carbon 3 trả số thực, và `scopeStale()` so thẳng
     * THỜI ĐIỂM (`< now() - 14 ngày`) chứ không so số ngày tròn — vụ 14 ngày 1 giờ đã nằm trong
     * widget nên cũng phải là "đỏ" ở cột danh sách và "quá 14 ngày" ở job. Ai cần số ngày tròn để
     * HIỂN THỊ thì tự `floor()` (xem `App\Mail\Staff\StaleMatterReminder`), không được cắt ở đây.
     */
    public static function daysSinceUpdate(Matter $matter): ?float
    {
        return self::clock($matter)?->diffInDays(now());
    }

    /**
     * "Cũ hơn N ngày", theo ĐÚNG đồng hồ mà {@see self::scopeStale()}/{@see self::color()} dùng —
     * tham số hoá theo số ngày (phán quyết setup của `task-7-brief.md`: "thêm vào MatterStaleness
     * một hằng số mới và một cách hỏi 'cũ hơn N ngày' dùng chung đồng hồ, không viết một truy vấn
     * COALESCE thứ hai ở Action"). Dùng bởi {@see CheckStaleMatters} để hỏi mốc 21 ngày
     * ({@see self::EMAIL_AFTER_DAYS}) trên MỘT bản ghi đã khoá dòng, không phải một truy vấn SQL
     * mới.
     */
    public static function olderThan(Matter $matter, int $days): bool
    {
        $daysSinceUpdate = self::daysSinceUpdate($matter);

        return $daysSinceUpdate !== null && $daysSinceUpdate > $days;
    }

    /**
     * Mốc bắt đầu "đợt đình trệ hiện tại" (R5 của kế hoạch M6, đọc lại ở `task-7-brief.md`): CHÍNH
     * đồng hồ `COALESCE(...)` hiện tại của vụ việc — một cập nhật công bố cho khách (hay vào giai
     * đoạn mới khi chưa từng cập nhật) DỜI đồng hồ này tới, nên tự nó đã là "đợt mới bắt đầu từ
     * lúc nào", không cần một cột episode riêng (R3: không thêm cột "đã nhắc lúc nào" nào khác
     * ngoài `deadlines.reminders_sent`). `CheckStaleMatters` dùng giá trị này làm cận dưới khi tra
     * `notifications`/`outbound_messages` chống gửi trùng.
     */
    public static function episodeStart(Matter $matter): ?Carbon
    {
        return self::clock($matter);
    }

    /**
     * Cận dưới (gồm cả nó) của cửa sổ "đã gửi thư staff.stale_matter trong {@see self::EMAIL_REPEAT_DAYS}
     * ngày qua", tính theo NGÀY LỊCH trong múi giờ ứng dụng: 00:00 của ngày (hôm nay - 6). Một thư
     * gửi ngày lịch D chặn các lượt chạy ngày D..D+6 và KHÔNG chặn lượt ngày D+7.
     *
     * Không dùng `now()->subDays(7)`: `stale-matters.check` chạy đúng một lần mỗi ngày lúc 07:30, còn
     * `outbound_messages.sent_at` được đóng dấu khi worker `queue:work --stop-when-empty` thật sự gửi —
     * luôn muộn hơn lượt chạy đã xếp job đó vài chục giây. Thư gửi 07:30:40 ngày D vẫn nằm trong
     * `now()->subDays(7)` = D 07:30:02 của lượt ngày D+7, nên chu kỳ 7 ngày sẽ thành 8 ngày. Dùng chung
     * cho `CheckStaleMatters::recentlyMailed()` và `SendStaleMatterMail::alreadyDelivered()` để hai lớp
     * chống trùng không lệch nhau.
     */
    public static function mailWindowStart(): Carbon
    {
        return today()->subDays(self::EMAIL_REPEAT_DAYS - 1);
    }

    /**
     * Đồng hồ dùng chung, phía PHP — nơi DUY NHẤT trong lớp này đọc `last_client_update_at ??
     * stage_entered_at` bằng `??`. {@see self::daysSinceUpdate()} và (gián tiếp) {@see
     * self::color()}/{@see self::olderThan()}/{@see self::episodeStart()} đều gọi qua đây, không
     * viết một lần `??` thứ hai. `scopeStale()` không gọi được hàm này (nó chạy phía SQL, trên
     * nhiều bản ghi chưa tải về PHP) — `COALESCE(last_client_update_at, stage_entered_at)` trong
     * `whereRaw()` của nó là bản SQL của ĐÚNG biểu thức này, không phải một định nghĩa thứ hai.
     */
    private static function clock(Matter $matter): ?Carbon
    {
        return $matter->last_client_update_at ?? $matter->stage_entered_at;
    }
}
