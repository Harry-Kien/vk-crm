<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Kết quả một lần chạy `RunConflictCheck` (SPEC §6.10). `level` là mức cao nhất trong số các
 * `matches` tìm được — `Green` nếu `matches` rỗng.
 *
 * `incompleteParties`: tên các bên ĐÃ KIỂM TRA nhưng không có `id_number_hash` lẫn
 * `phone_normalized` (chỉ so khớp được theo tên — mức tin cậy thấp nhất, xem `RunConflictCheck`).
 * Đây không phải là lỗi để im lặng bỏ qua: một bên thiếu định danh có thể đang che giấu một xung
 * đột thật (trùng số căn cước với ai đó) mà Action không có cách nào phát hiện vì `identify()`
 * chưa từng được gọi với dữ liệu gốc. Phải hiện rõ cho người xem xét, không được lặn vào mức xanh
 * lặng lẽ.
 */
final readonly class ConflictCheckResult implements Arrayable
{
    /**
     * @param  Collection<int, ConflictMatch>  $matches
     * @param  Collection<int, string>  $incompleteParties
     */
    public function __construct(
        public ConflictLevel $level,
        public Collection $matches,
        public Collection $incompleteParties,
    ) {}

    /** Đỏ — chặn lưu, chỉ `manager`/`admin` ghi đè kèm lý do (quyết định ở OpenMatter, không ở đây). */
    public function isBlocking(): bool
    {
        return $this->level === ConflictLevel::Red;
    }

    /**
     * Vàng, đỏ, hoặc có bên thiếu định danh (`hasIncompleteParties()`) — người tạo phải tích xác
     * nhận đã xem xét trước khi lưu. Một kết quả xanh với bên thiếu định danh KHÔNG đáng tin cậy
     * bằng xanh thật (xem docblock `RunConflictCheck` và `incompleteParties`): nếu chỉ nhìn
     * `requiresAcknowledgement()`/`isBlocking()`, caller không được phép hiện một form xanh trơn
     * mà giấu đi cảnh báo thiếu định danh — fix round 2.
     */
    public function requiresAcknowledgement(): bool
    {
        return $this->level !== ConflictLevel::Green || $this->hasIncompleteParties();
    }

    /** @return array<int, string> */
    public function incompleteParties(): array
    {
        return $this->incompleteParties->all();
    }

    public function hasIncompleteParties(): bool
    {
        return $this->incompleteParties->isNotEmpty();
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'matches' => $this->matches->map(fn (ConflictMatch $match) => $match->toArray())->all(),
            'incomplete_parties' => $this->incompleteParties->all(),
        ];
    }
}
