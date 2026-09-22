<?php

namespace App\Actions\Deadline;

use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\SetMatterPortalPublication;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * "Đánh dấu hoàn thành" một mốc thời hạn (SPEC §7.2) — và đường lùi của nó.
 *
 * # Một cờ, không phải một chiều
 *
 * Cùng hình dạng {@see SetMatterPortalPublication::handle()}, và ở đây nó quan trọng
 * hơn ở đó: một mốc đã hoàn thành là một mốc mà `CheckDeadlines` (M6 Task 6) THÔI nhắc, nên một
 * lần bấm nhầm biến một hạn tố tụng thành một dòng im lặng cho tới ngày nó trôi qua. Với một văn
 * phòng luật đó không phải một phiền toái, đó là chỗ trách nhiệm nghề nghiệp bắt đầu. Đường lùi
 * phải tồn tại và phải đi qua cùng một cổng.
 *
 * # "Ai đánh dấu" — nói thẳng chỗ hệ thống YẾU
 *
 * SPEC §4.13 cho bảng này `completed_at` nhưng **không** có `completed_by`, và task này không
 * thêm cột (`deadlines` đã có từ M1; một cột mới là một migration mà kế hoạch M6 giao cho người
 * khác). Nên câu "ai đánh dấu hoàn thành mốc này" được trả lời ở hai chỗ, và chỉ một trong hai
 * chịu được một lần sửa về sau:
 *
 *  - `deadlines.updated_by` là người ghi **gần nhất**, không phải người đánh dấu. Một lần bật/tắt
 *    công bố sau đó đè lên nó. Nó đúng ngay sau thao tác này và chỉ ngay sau thao tác này.
 *  - Dòng `deadline_completion_set` trong nhật ký là bản ghi **append-only**, mang `causer` là
 *    chính `$actor` đã qua cổng. Đây là câu trả lời dùng được cho một câu hỏi đặt ra sáu tháng
 *    sau, và nó là lý do Action này ghi nhật ký chứ không chỉ ghi cột.
 *
 * # Gọi trùng không được ghi đè lịch sử
 *
 * Một cú bấm đúp, hai tab đang mở, hay một cron gọi lại (Phán quyết R4 của M6) không được dời
 * `completed_at` đã ghi, và không được đẻ thêm một dòng nhật ký nói rằng có người vừa hoàn thành
 * nó lần nữa. Nên khi bản ghi đã ở đúng trạng thái được yêu cầu, Action trả về và không ghi gì.
 *
 * # Mở lại KHÔNG xoá `reminders_sent`
 *
 * Phán quyết R3 của M6: nhật ký/cột nhắc là **trí nhớ chống gửi trùng**. Các thư đã gửi thì đã
 * gửi thật, và người phụ trách đã đọc chúng; xoá trí nhớ ấy sẽ bắn lại cả loạt nhắc cũ vào hộp
 * thư họ ngay sáng hôm sau. Nếu một mốc được mở lại vì ngày đến hạn ĐỔI, thứ cần đổi là ngày —
 * và lúc đó việc phát lại các bậc nhắc là quyết định của Task 6 trên `due_date` mới, không phải
 * một tác dụng phụ của cái nút này.
 */
class SetDeadlineCompletion
{
    use OpensDeadline;

    public function handle(Deadline $deadline, bool $completed, User $actor): Deadline
    {
        return DB::transaction(function () use ($deadline, $completed, $actor): Deadline {
            [$fresh, $matter] = $this->openDeadline($deadline, $actor);

            // Đã ở đúng trạng thái: không ghi cột, không ghi nhật ký. Xem docblock lớp.
            if ((bool) $fresh->is_completed === $completed) {
                return $fresh;
            }

            $fresh->blameOn($actor)->update([
                'is_completed' => $completed,
                'completed_at' => $completed ? now() : null,
            ]);

            Audit::record('deadline_completion_set', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'completed' => $completed,
                'due_date' => $fresh->due_date->toDateString(),
                'severity' => $fresh->severity->value,
            ], causer: $actor);

            return $fresh;
        });
    }
}
