<?php

namespace App\Exceptions;

use App\Models\Matter;
use DomainException;

/**
 * Race giữa hai lần chuyển giai đoạn ĐỒNG THỜI trên CÙNG một vụ việc (`stage/stage-05`, Review
 * Focus 4, M6.5 Task 10). `TransitionMatterStage::handle()` khoá dòng `matters` NGAY LẦN CHẠM ĐẦU
 * TIÊN vào CSDL trong transaction (`lockForUpdate()`), rồi so giai đoạn ĐỌC ĐƯỢC DƯỚI KHOÁ với giai
 * đoạn mà `$matter` caller cầm trong tay TRƯỚC transaction (tức trước khi xin khoá). Khác nhau nghĩa
 * là một lần chuyển giai đoạn KHÁC đã chen vào và COMMIT trong lúc request này còn đợi khoá.
 *
 * **Vì sao một lớp RIÊNG, không dùng lại `InvalidStageTransition`.** `to_stage` của request này có
 * thể vẫn hợp lệ với `allowed_next` của giai đoạn CŨ mà form được dựng lên — lỗi không nằm ở
 * `to_stage`, mà ở việc giai đoạn thật đã đổi dưới chân người dùng trong lúc họ thao tác. Ném lại
 * `InvalidStageTransition` (nói "không nằm trong allowed_next") sẽ đúng triệu chứng nhưng sai
 * nguyên nhân, và không nói cho luật sư biết việc CẦN LÀM là tải lại trang để xem giai đoạn mới
 * nhất — khác hẳn một lựa chọn thật sự không hợp lệ.
 */
class MatterStageChanged extends DomainException
{
    public static function make(Matter $matter): self
    {
        return new self(__('exceptions.matter_stage_changed', [
            'code' => $matter->code,
        ]));
    }
}
