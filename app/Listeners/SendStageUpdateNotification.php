<?php

namespace App\Listeners;

use App\Actions\Notification\NotifyClientOfStageUpdate;
use App\Events\StageLogPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Nối `StageLogPublished` với Action gửi thư. Mỏng có chủ ý: luật nằm trong Action, listener chỉ
 * là sợi dây — đúng luật của CLAUDE.md, và nhờ vậy một test gọi thẳng Action được mà không phải
 * dựng sự kiện.
 *
 * ĐĂNG KÝ BẰNG TỰ DÒ, không đăng ký tường minh. `Application::configure()` gọi `withEvents()`
 * theo mặc định, nên Laravel tự tìm lớp trong `app/Listeners` có phương thức `handle` và suy
 * kiểu sự kiện từ tham số. Vòng làm nhật ký thư đã trả giá cho bài học ngược lại: đặt tên
 * `handleMessageSending` RỒI đăng ký thêm bằng tay khiến mỗi thư sinh hai dòng nhật ký. Ở đây
 * chỉ có đúng một đường đăng ký, và có test khẳng định sự kiện thật sự gọi tới lớp này.
 *
 * # M6.5 Task 11 (`stage/stage-01`, `notify/notify-1`, `spec-gap/spec-gap-02`, `e2e/F2`) — `ShouldQueue`
 *
 * Trước Task 11, lớp này KHÔNG `ShouldQueue`: `StageLogPublished` (implements
 * `ShouldDispatchAfterCommit`) dispatch xong là listener chạy NGAY, đồng bộ, trong chính request
 * Livewire của luật sư. Một transport hỏng (SMTP chết, một địa chỉ bị từ chối) ném
 * `TransportException` thẳng lên form: luật sư thấy lỗi 500 dù `StageLog` đã commit với
 * `is_published = true` và `matters.stage` đã đổi. `notified_at` để trống, và vì không có job hay
 * lịch nào gọi lại `NotifyClientOfStageUpdate`, thư của dòng đó MẤT HẲN — không phải "chậm", mất
 * vĩnh viễn. Luật sư tưởng thao tác thất bại và bấm lại ("Thêm cập nhật"), sinh một `StageLog`
 * công bố thứ hai giống hệt (append-only, không xoá được): khách thấy hai dòng trùng.
 *
 * `ShouldQueue` sửa cả hai vế: `StageLogPublished` đã đợi transaction commit, nên khi listener
 * này CŨNG được đẩy vào hàng đợi, việc gọi `NotifyClientOfStageUpdate::handle()` xảy ra TÁCH RỜI
 * khỏi request Livewire — một transport hỏng chỉ làm job này thất bại (worker tự thử lại theo
 * `$tries`/`backoff()`), không bao giờ chạm lại tới form. Dưới hàng đợi `sync` (mặc định của bộ
 * test), job vẫn chạy NGAY tại chỗ dispatch — nên các test cũ dùng `Mail::fake()` không cần đổi gì
 * cả; test đo hành vi "không lỗi 500" phải tự chuyển sang hàng đợi `database` để job thật sự nằm
 * chờ thay vì chạy đồng bộ (xem `tests/Feature/Mail/StageUpdateNotificationTest.php`).
 *
 * `$tries`/`$backoff` đọc trực tiếp từ property công khai — không cần một phương thức
 * `tries()`/`backoff()`: `Illuminate\Events\Dispatcher::propagateListenerOptions()` (Laravel 13)
 * rơi về đọc thẳng property khi không có phương thức cùng tên và không có PHP attribute nào khai
 * báo, nên khai property là đủ, không cần thêm gì khác.
 */
class SendStageUpdateNotification implements ShouldQueue
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(private NotifyClientOfStageUpdate $notify) {}

    public function handle(StageLogPublished $event): void
    {
        $this->notify->handle($event->stageLog);
    }

    /**
     * Final review B-M3: chạy một lần, sau khi CẢ `$tries` lần gửi đều hỏng. Trước bản sửa này chỉ
     * còn một dòng `failed_jobs` — không ai trong văn phòng biết khách chưa được báo. Luật báo ai
     * nằm ở Action ({@see NotifyClientOfStageUpdate::reportFailure()}); listener chỉ là sợi dây.
     * `?Throwable` không dùng tới — cùng lý do `SendDeadlineReminderMail::failed()`.
     */
    public function failed(StageLogPublished $event, ?Throwable $exception): void
    {
        $this->notify->reportFailure($event->stageLog);
    }
}
