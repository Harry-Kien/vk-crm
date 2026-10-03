<?php

namespace App\Support;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\RemindUnseenUpdates;
use App\Filament\Admin\Widgets\UnseenUpdatesWidget;
use App\Models\Matter;
use App\Models\StageLog;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Khách chưa xem cập nhật" (SPEC §4.18, §7.1 mục 5) — MỘT định nghĩa, phần KHÔNG phụ thuộc người
 * đang hỏi, dùng chung giữa {@see UnseenUpdatesWidget} (danh sách trang chủ) và
 * {@see RemindUnseenUpdates} (nhắc luật sư phụ trách gọi điện lúc 08:30). Trước M6 Task 9 định
 * nghĩa nằm hẳn trong widget; Action mới mà tự viết lại nó thì hai nơi sẽ lệch nhau đúng chỗ luật
 * sư tin vào (widget nói một hồ sơ cần gọi, chuông nói hồ sơ khác), nên nó được tách ra đây.
 *
 * Bốn điều kiện trên `stage_logs`, rồi một điều kiện trên hồ sơ:
 *
 *  1. `is_published = true` và `published_at` không null — dòng khách CÓ THỂ thấy;
 *  2. `published_at` cũ hơn {@see self::AFTER_DAYS} ngày — đồng hồ là `published_at`, KHÔNG phải
 *     `occurred_at` (đồng hồ của SPEC §7.1 mục 5 đếm từ lúc văn phòng ĐƯA TIN ra); bất đẳng thức
 *     THẬT (đúng 5 ngày thì chưa);
 *  3. `whereDoesntHave('views')` — không một tài khoản portal nào của khách hàng đó đã mở trang
 *     chi tiết hồ sơ (chỉ cần MỘT người mở là đủ, "theo khách hàng" — xem docblock widget về ý
 *     nghĩa chính xác của "chưa xem");
 *  4. hồ sơ đang công bố lên cổng (`is_published_to_portal = true`) VÀ chưa hết hạn tra cứu —
 *     khách không có đường nào mở một hồ sơ đã rời cổng, nên nó không phải một việc phải gọi điện;
 *     hồ sơ xoá mềm tự rơi qua `SoftDeletingScope` của `whereHas('matter')`. "Chưa hết hạn tra cứu"
 *     (việc sau gộp M7, làn fu2): từ M7 Task 5 (R4) một hồ sơ đã kết thúc rời cổng khi quá
 *     `client_access_until` mà cờ giữ nguyên — cùng điều kiện thứ năm của
 *     `Matter::applyClientPortalConstraints()`, viết bằng đúng scope
 *     `MatterArchive::scopeClientAccessExpired()`, không định nghĩa thứ ba.
 *
 * **Cố ý KHÔNG lọc `closed_at`** (docblock widget, mục 3): một cập nhật cuối trên hồ sơ vừa đóng
 * mà khách chưa từng thấy là cuộc gọi đáng gọi nhất. Ai thêm {@see Matter::scopeOpen()} vào đây
 * sẽ làm đỏ cả test của widget lẫn test của Action.
 *
 * **Không có phạm vi người xem.** Widget ghép thêm `whereHas('matter', listableBy($user))` cho
 * từng nhân sự đang mở trang chủ; Action không ghép gì, vì người nhận do
 * {@see ResolveStaffRecipients} chọn theo R3 (`Gate::view()`), không
 * theo một câu truy vấn.
 *
 * `whereDoesntHave('views')` chạy KHÔNG qua `ClientPortalScope` — dưới guard `web` hay trong
 * scheduler/console scope đó không kích hoạt, nên câu hỏi con đếm mọi biên bản của mọi tài khoản
 * portal (đúng cách đọc "theo khách hàng").
 */
final class UnseenStageLogs
{
    /** SPEC §7.1 mục 5 và §4.18: "quá 5 ngày". */
    public const AFTER_DAYS = 5;

    /**
     * Các dòng tiến độ thoả định nghĩa, chưa giới hạn theo người xem.
     *
     * @return Builder<StageLog>
     */
    public static function query(): Builder
    {
        return StageLog::query()
            ->where('is_published', true)
            // `published_at`, không phải `occurred_at`: xem docblock lớp, điều kiện 2.
            ->whereNotNull('published_at')
            ->where('published_at', '<', now()->subDays(self::AFTER_DAYS))
            ->whereDoesntHave('views')
            ->whereHas('matter', fn (Builder $matter): Builder => $matter
                ->where('is_published_to_portal', true)
                // Điều kiện 4, vế "chưa hết hạn tra cứu". Gỡ `ClientPortalScope` cùng lý do ở
                // `Matter::applyClientPortalConstraints()`: `MatterArchive` mang scope chặn sạch đó,
                // và một tiến trình còn treo ngữ cảnh cổng không được làm vế này luôn đúng.
                ->whereDoesntHave('archive', fn (Builder $archive): Builder => $archive
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->clientAccessExpired()));
    }
}
