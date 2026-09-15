<?php

namespace App\Exceptions;

use App\Enums\ConflictLevel;
use App\Support\ConflictCheckResult;
use DomainException;

/**
 * Kết quả kiểm tra xung đột lợi ích ở mức đỏ và không có ghi đè hợp lệ (SPEC §6.10 bước 3,
 * §11 "Xung đột lợi ích"). Chỉ `manager`/`admin` kèm lý do không rỗng mới được ghi đè —
 * `OpenMatter` là nơi duy nhất quyết định việc đó, lớp này chỉ mang kết quả ra ngoài để
 * caller (Filament) hiển thị lại danh sách bản ghi trùng đã gây chặn.
 */
class ConflictBlocked extends DomainException
{
    public function __construct(
        string $message,
        public readonly ConflictCheckResult $result,
    ) {
        parent::__construct($message);
    }

    public static function make(ConflictCheckResult $result): self
    {
        $codes = $result->matches
            ->filter(fn ($match) => $match->level === ConflictLevel::Red)
            ->pluck('matterCode')
            ->unique()
            ->implode(', ');

        return new self(__('exceptions.conflict_blocked', ['codes' => $codes]), $result);
    }
}
