<?php

namespace App\Actions\Deadline;

use App\Actions\Deadline\Concerns\ChecksDeadlineHolder;
use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\SetMatterPortalPublication;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 * qua nút "Sửa" ({@see UpdateDeadline}, M6.5 Task 14), nơi đổi `due_date` dọn đúng những bậc mà
 * ngày mới chưa tới — không phải một tác dụng phụ của cái nút này.
 *
 * # Mở lại một mốc mà người giữ nó không còn hợp lệ (M6.5 Task 14, carried từ rà soát Task 3)
 *
 * Một mốc có thể nằm "đã hoàn thành" hàng tháng trời trong khi người đứng tên `responsible_user_id`
 * đã nghỉ việc, bị vô hiệu hoá, bị gỡ khỏi đội ngũ (R6 cho gỡ vì mốc của họ đã xong lúc ấy), hoặc
 * — vụ việc bị siết thành `restricted` sau đó — không còn `Gate::view()` được hồ sơ nữa. Mở lại
 * nguyên trạng giao mốc cho một cái tên không ai còn đọc được: đúng hình dạng "mốc im lặng" mà R3
 * sinh ra để chống, chỉ đi vào từ cửa khác (`is_completed`, không phải `reminders_sent`).
 *
 * **Quyết định của controller: mở lại VẪN chạy, và mốc về tay luật sư phụ trách hồ sơ.** Câu hỏi
 * "còn giữ được không" là {@see ChecksDeadlineHolder::canHoldDeadline()} — CÙNG luật mà
 * `ChangeDeadlineResponsible` và `UpdateDeadline` hỏi khi ghi cột này, không phải một định nghĩa
 * thứ hai. Lần chuyển người ghi một dòng `deadline_responsible_changed` RIÊNG (cùng khoá sự kiện
 * với nút "Đổi người phụ trách", để lịch sử "ai từng giữ mốc này" đọc ở MỘT chỗ), mang
 * `reason = reopened_holder_no_longer_qualifies` để phân biệt với một lần đổi do người dùng chọn.
 * Màn hình biết chuyện đó qua `$result->wasChanged('responsible_user_id')` trên bản ghi trả về và
 * nói ra bằng một thông báo tiếng Việt — xem `DeadlinesRelationManager::reopenAction()`.
 *
 * Luật sư phụ trách hồ sơ CŨNG không còn giữ được mốc (R7 chặn vô hiệu hoá họ khi còn dẫn vụ đang
 * mở, nhưng dữ liệu cũ vẫn có thể mang trạng thái đó): từ chối, mốc ở lại "đã xong", và câu từ
 * chối nói việc cần làm là bàn giao hồ sơ trước. Không bao giờ mở lại rồi để mốc trong tay một
 * người không ai còn nhắc tới.
 *
 * `User::withTrashed()->find()`, không phải quan hệ `$fresh->responsible`: quan hệ đó tự áp
 * `SoftDeletingScope` và trả `null` cho một tài khoản đã xoá mềm — cũng ra "không hợp lệ", nhưng
 * dòng nhật ký thì cần ĐÚNG id người giữ cũ, và đọc bằng khoá ngoại nói thẳng điều đó.
 */
class SetDeadlineCompletion
{
    use ChecksDeadlineHolder;
    use OpensDeadline;

    /** Giá trị `reason` của dòng `deadline_responsible_changed` do lần mở lại tự chuyển người. */
    public const REOPEN_HANDOVER_REASON = 'reopened_holder_no_longer_qualifies';

    public function handle(Deadline $deadline, bool $completed, User $actor): Deadline
    {
        return DB::transaction(function () use ($deadline, $completed, $actor): Deadline {
            [$fresh, $matter] = $this->openDeadline($deadline, $actor);

            // Đã ở đúng trạng thái: không ghi cột, không ghi nhật ký. Xem docblock lớp.
            if ((bool) $fresh->is_completed === $completed) {
                return $fresh;
            }

            // Mở lại (không áp cho lần ĐÁNH DẤU XONG): người giữ mốc phải còn hợp lệ HÔM NAY,
            // không phải hôm mốc được đánh dấu xong — xem docblock lớp.
            $handedOverFrom = null;

            if (! $completed) {
                $holder = User::withTrashed()->find($fresh->responsible_user_id);

                if ($holder === null || ! $this->canHoldDeadline($holder, $matter)) {
                    $lead = User::withTrashed()->find($matter->lead_lawyer_id);

                    if ($lead === null || ! $this->canHoldDeadline($lead, $matter)) {
                        throw ValidationException::withMessages([
                            'responsible_user_id' => [__('deadlines.validation.reopen_without_holder')],
                        ]);
                    }

                    $handedOverFrom = $fresh->responsible_user_id;
                    $fresh->responsible_user_id = $lead->getKey();
                }
            }

            $fresh->blameOn($actor)->update([
                'is_completed' => $completed,
                'completed_at' => $completed ? now() : null,
            ]);

            if ($handedOverFrom !== null) {
                Audit::record('deadline_responsible_changed', $fresh, [
                    'matter_id' => $matter->getKey(),
                    'client_id' => $matter->client_id,
                    'from' => $handedOverFrom,
                    'to' => $fresh->responsible_user_id,
                    'reason' => self::REOPEN_HANDOVER_REASON,
                ], causer: $actor);
            }

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
