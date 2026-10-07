<?php

namespace App\Actions\Push;

use App\Enums\PushTopic;
use App\Models\ClientUser;
use App\Models\User;
use App\Notifications\PushAlert;
use App\Support\Push\VapidKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Xếp một thông báo đẩy cho một tập người nhận (M12 R10, R12) — ĐƯỜNG DUY NHẤT dựng `PushAlert`
 * (test cấu trúc `tests/Feature/Push/PushStructureTest.php`, cùng danh sách nơi được gọi hàm này).
 *
 * # Một định nghĩa người nhận, không hai (R10)
 *
 * Push không có luật người nhận của riêng nó. Nơi gửi thư tính người nhận MỘT lần (khách: luật R12
 * của M6.5, `ResolveClientRecipients`; nhân sự: `ResolveStaffRecipients`, R3), xếp thư cho tập đó,
 * rồi gọi hàm này với ĐÚNG collection đó (hay, theo phán quyết (d) của controller, với từng người
 * ngay sau khi thư của chính người đó đi được). Ở đây chỉ bỏ người không có máy nào — "nhận push hay
 * không" chính là "máy này đã bật chưa" (R14). Chống trùng là trí nhớ của thư (M6 R3), không có trí
 * nhớ mới ở đây.
 *
 * Tắt push theo TỪNG CHỦ ĐỀ không làm ở M12. Nếu cần về sau, đó là một bảng `(notifiable, topic)` mà
 * hàm này lọc SAU khi đã có tập người nhận của thư, tức vẫn không thành định nghĩa người nhận thứ
 * hai. Email không tắt được, với mọi chủ đề và mọi người (R14).
 *
 * # Thư trước, push sau; push hỏng không làm hỏng thư (R12)
 *
 * Người gọi xếp thư TRƯỚC. Hàm này không bao giờ ném vì lỗi lúc chạy (CSDL, hàng đợi, dựng đường
 * dẫn): lỗi được `report()` và người nhận đó bị bỏ qua — thư của họ đã đi, và không lượt chạy lại
 * nào của nơi gửi thư phải lặp vì một push. Nó CHỈ ném `InvalidArgumentException` cho một lời gọi sai
 * (bản ghi liên quan sai loại, người nhận sai panel, bậc mốc hạn lạ — {@see PushTopic::assertAccepts()},
 * {@see PushTopic::assertRecipient()}), TRƯỚC khi xếp bất cứ gì: đó là lỗi lập trình, test của nơi gọi
 * phải thấy nó.
 *
 * `PushAlert` mang `afterCommit`: gọi trong một transaction thì job chỉ được xếp khi transaction đó
 * commit (rollback = không gì được gửi). Không gọi hàm này BÊN TRONG `DB::transaction()` của một
 * Action (`tests/Feature/ArchitectureTest.php` cấm `->notify(` ở đó).
 *
 * Máy chủ chưa có khoá VAPID (R7): không xếp gì, trả 0 — push tắt êm.
 */
class SendPushAlert
{
    /**
     * @param  iterable<User|ClientUser>  $recipients  ĐÚNG người nhận của thư đi cùng
     * @param  ?string  $tier  bậc nhắc của mốc hạn (`CheckDeadlines::tierFor()`), chỉ cho
     *                         {@see PushTopic::StaffDeadlineReminder}
     * @return int số người đã được xếp một `PushAlert` (người có ít nhất một máy)
     */
    public function handle(iterable $recipients, PushTopic $topic, ?Model $related = null, ?string $tier = null): int
    {
        $topic->assertAccepts($related, $tier);

        /** @var Collection<int, User|ClientUser> $recipients */
        $recipients = Collection::make($recipients)
            ->unique(fn (User|ClientUser $recipient): string => $recipient->getMorphClass().':'.$recipient->getKey())
            ->values();

        $recipients->each(fn (User|ClientUser $recipient) => $topic->assertRecipient($recipient));

        if (! VapidKeys::configured()) {
            return 0;
        }

        $queued = 0;

        foreach ($recipients as $recipient) {
            try {
                if (! $recipient->pushSubscriptions()->exists()) {
                    continue;
                }

                $recipient->notify(new PushAlert($topic->message($related, $recipient, $tier)));
                $queued++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $queued;
    }
}
