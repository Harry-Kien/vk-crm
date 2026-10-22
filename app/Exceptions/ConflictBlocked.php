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
        public readonly bool $resultChanged = false,
    ) {
        parent::__construct($message);
    }

    /**
     * Người được ghi đè đã gửi lý do cho một bảng Đỏ, nhưng lần kiểm tra lúc lưu cho ra bảng KHÁC (có
     * khớp mới) — lý do đó không viết cho bảng này (rà soát 2026-10-22, làn harden).
     */
    public static function changed(ConflictCheckResult $result): self
    {
        return new self(__('exceptions.conflict_result_changed_override'), $result, true);
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
