<?php

namespace App\Actions\Schedule;

use App\Actions\Intake\AnonymiseProspect;
use App\Exceptions\ConflictCheckBusy;
use App\Models\IntakeRequest;
use App\Support\Scopes\ClientPortalScope;
use Throwable;

/**
 * Tác vụ hằng ngày của M10 R7b (Task 7): ẩn danh dữ liệu của người liên hệ KHÔNG thành khách đã quá hạn
 * lưu — `declined`, `lost` hoặc `merged`, chưa chuyển đổi, chưa ẩn danh, `retention_until` đã qua
 * (`IntakeRequest::scopeRetentionExpired()`, kể cả bản đã xoá mềm; bản đã gộp vào một bản về sau thành
 * vụ việc không còn hạn, và `expire()` hỏi lại chuỗi gộp trên dòng đã khoá). Việc ẩn danh là của
 * {@see AnonymiseProspect::expire()} — CÙNG Action với "Xoá dữ liệu theo yêu cầu" của admin.
 *
 * **Việc riêng, không lẫn với `FlagRetentionExpiry` của M7 Task 6:** tác vụ đó chỉ CẢNH BÁO và không
 * bao giờ xoá hồ sơ vụ việc (M7 R5); tác vụ này ẩn danh người chưa từng là khách (R7). Tên lịch riêng
 * (`prospects.anonymise`), lớp riêng, test cấu trúc riêng.
 *
 * Mỗi bản ghi một lần khoá `conflict-check` và một transaction riêng (không giữ khoá suốt cả lượt, để
 * người đang ghi tiếp nhận không phải chờ). Một bản ghi không lấy được khoá trong 10 giây
 * (`ConflictCheckBusy`) hoặc hỏng vì lý do khác thì bị bỏ qua — đếm vào `skipped`, lỗi lạ được `report()`
 * — và lượt chạy đi tiếp; nó vẫn thoả điều kiện nên lượt ngày mai làm lại. Chạy hai lần không đổi kết quả:
 * Action đọc lại điều kiện hết hạn trên dòng vừa khoá.
 */
class AnonymiseExpiredProspects
{
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * @return array{anonymised: int, skipped: int}
     */
    public function handle(): array
    {
        $anonymised = 0;
        $skipped = 0;

        $expired = IntakeRequest::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->retentionExpired()
            ->lazyById(100);

        foreach ($expired as $intake) {
            try {
                if (app(AnonymiseProspect::class)->expire($intake)) {
                    $anonymised++;
                }
            } catch (ConflictCheckBusy) {
                $skipped++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        return ['anonymised' => $anonymised, 'skipped' => $skipped];
    }
}
