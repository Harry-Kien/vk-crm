<?php

namespace App\Enums;

/**
 * Trạng thái của một việc giao nội bộ (làn tk, Task 1). Năm trạng thái, ba trong số đó còn "mở"
 * ({@see self::open()}): việc chờ người nhận bấm "Đã nhận", việc đang làm, và việc bị trả lại mà người
 * giao chưa giao lại hay huỷ. Mọi lần chuyển trạng thái đi qua một Action trong `app/Actions/Task/`,
 * dưới khoá dòng — xem `App\Actions\Task\Concerns\OpensTask`.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('tasks.status.'.$this->value);
    }

    /** Màu badge — chỉ hiển thị, không luật nghiệp vụ nào đọc nó. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'info',
            self::Declined => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** Việc còn mở: còn người phải làm gì đó với nó (nhận, làm, hay giao lại). */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Accepted, self::Declined];
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::open());
    }
}
