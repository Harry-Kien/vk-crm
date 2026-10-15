<?php

namespace App\Exceptions;

use App\Actions\Deadline\AddMatterDeadline;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Deadline\UpdateDeadline;
use DomainException;

/**
 * Làn fm, mục A1 (kiểm tra nghiệp vụ 2026-10-09): vụ việc đã kết thúc không nhận thêm mốc thời hạn
 * ({@see AddMatterDeadline}) và không sửa tên, ngày, mức độ của mốc cũ ({@see UpdateDeadline}). Làn fi
 * (B4; vòng sửa 1, I5): cùng luật đó, không MỞ LẠI một mốc đã xong ({@see SetDeadlineCompletion}) —
 * mốc mở lại trên vụ đã đóng cũng là một mốc mở không bao giờ được nhắc.
 *
 * Lý do: `CheckDeadlines` và widget "Mốc 7 ngày tới" chỉ đọc vụ đang mở (SPEC §6.7, chủ ý của M6.5
 * Task 5), nên một mốc đặt lên vụ đã đóng lưu được nhưng không bao giờ được nhắc — một hạn tố tụng
 * bị lỡ trong im lặng. Từ chối ở đây là phương án an toàn: không mất dữ liệu, và đường đi tiếp rõ
 * ràng (nhờ quản trị viên mở lại vụ qua "Chuyển giai đoạn" — chỉ quản trị viên mở lại được vụ đã kết
 * thúc, `TransitionMatterStage` — rồi đặt hay mở lại mốc như thường). Đánh dấu xong, đổi người giữ,
 * công bố và xoá mốc cũ vẫn làm được — đó là việc dọn dẹp, không tạo ra một hạn mới mà hệ thống sẽ bỏ
 * quên.
 *
 * Cùng loại với {@see MatterChecklistReadOnly}: một câu hỏi về TRẠNG THÁI vụ việc, không phải về
 * quyền, nên là `DomainException` thuần — `ReportsActionFailures` đổi nó thành một thông báo.
 */
class MatterClosedForDeadlines extends DomainException
{
    public static function make(): self
    {
        return new self(__('lifecycle.deadlines.closed_refused'));
    }

    /** Làn fi (B4): mở lại một mốc đã xong trên vụ đã kết thúc. */
    public static function reopen(): self
    {
        return new self(__('lifecycle.deadlines.reopen_refused'));
    }
}
