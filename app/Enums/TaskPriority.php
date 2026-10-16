<?php

namespace App\Enums;

/** Mức ưu tiên của một việc giao nội bộ (làn tk, Task 1). Mặc định "Thường". */
enum TaskPriority: string
{
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return __('tasks.priority.'.$this->value);
    }

    /** Màu badge — chỉ hiển thị. */
    public function color(): string
    {
        return match ($this) {
            self::Normal => 'gray',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    /** @return array<string, string> giá trị => nhãn, theo thứ tự hiển thị trong ô chọn */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $priority): array => [$priority->value => $priority->label()])
            ->all();
    }
}
