<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Kết quả một lần chạy `RunConflictCheck` (SPEC §6.10). `level` là mức cao nhất trong số các
 * `matches` tìm được — `Green` nếu `matches` rỗng.
 */
final readonly class ConflictCheckResult implements Arrayable
{
    /** @param Collection<int, ConflictMatch> $matches */
    public function __construct(
        public ConflictLevel $level,
        public Collection $matches,
    ) {}

    /** Đỏ — chặn lưu, chỉ `manager`/`admin` ghi đè kèm lý do (quyết định ở OpenMatter, không ở đây). */
    public function isBlocking(): bool
    {
        return $this->level === ConflictLevel::Red;
    }

    /** Vàng hoặc đỏ — người tạo phải tích xác nhận đã xem xét trước khi lưu. */
    public function requiresAcknowledgement(): bool
    {
        return $this->level !== ConflictLevel::Green;
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'matches' => $this->matches->map(fn (ConflictMatch $match) => $match->toArray())->all(),
        ];
    }
}
