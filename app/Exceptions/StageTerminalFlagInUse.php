<?php

namespace App\Exceptions;

use DomainException;

/**
 * Final review X9 (C-I3): bật hay tắt `is_terminal` của một giai đoạn còn hồ sơ đứng ở đó đổi
 * nghĩa `closed_at` của những hồ sơ ấy mà không có lần chuyển giai đoạn nào — vụ đang chạy bỗng
 * "đã đóng" (hoặc ngược lại) ở mọi nơi dùng `Matter::scopeOpen()`. Chốt chặn ở tầng model
 * (`MatterTypeStage::booted()`), cho mọi đường ghi không qua form của `StagesRelationManager`.
 */
class StageTerminalFlagInUse extends DomainException
{
    public static function make(string $key, int $count): self
    {
        return new self(__('exceptions.stage_terminal_flag_in_use', ['key' => $key, 'count' => $count]));
    }
}
