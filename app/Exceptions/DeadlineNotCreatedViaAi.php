<?php

namespace App\Exceptions;

use App\Actions\Deadline\ConfirmAiDeadline;
use DomainException;

/**
 * "Xác nhận" chỉ có nghĩa với một mốc tạo qua AI (`created_via = mcp`): mốc nhân sự nhập trên web
 * không mang nhãn "chưa xác nhận" nào để gỡ ({@see ConfirmAiDeadline}).
 */
class DeadlineNotCreatedViaAi extends DomainException
{
    public static function make(): self
    {
        return new self(__('ai_drafts.deadline.not_from_ai'));
    }
}
