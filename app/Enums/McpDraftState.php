<?php

namespace App\Enums;

use App\Models\ClientRequestReplyDraft;
use App\Models\Concerns\IsMcpDraft;
use App\Models\StageLogDraft;

/**
 * Trạng thái của một nháp do AI soạn (M11 R5; Task 7 định nghĩa, Task 13 trả cho AI): **đang chờ**
 * (chưa dùng, chưa bỏ), **đã dùng** (một người trong `/admin` đã gửi từ nháp), **đã bỏ** (kèm người và
 * lý do). Không phải một cột: trạng thái suy từ cột "đã dùng" của từng bảng và `discarded_at`, đúng
 * định nghĩa của {@see IsMcpDraft} — enum này chỉ đặt tên và nhãn cho ba trạng thái đó.
 */
enum McpDraftState: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Discarded = 'discarded';

    public static function of(StageLogDraft|ClientRequestReplyDraft $draft): self
    {
        return match (true) {
            $draft->getAttribute($draft::usedColumn()) !== null => self::Used,
            $draft->getAttribute('discarded_at') !== null => self::Discarded,
            default => self::Pending,
        };
    }

    public function label(): string
    {
        return __('enums.mcp_draft_state.'.$this->value);
    }
}
