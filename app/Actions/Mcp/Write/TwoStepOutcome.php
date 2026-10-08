<?php

namespace App\Actions\Mcp\Write;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Kết quả của một tool ghi hai bước (M11 R6) cho tool trình bày — DTO, không bao giờ serialize ra MCP:
 *
 *  - **bản xem trước** ({@see self::preview()}): lần gọi thứ nhất. `$preview` là bản ghi của LẦN CHẠY
 *    THỬ — đã bị rollback, không tồn tại trong CSDL; presenter chỉ đọc các trường sẽ được ghi, không
 *    bao giờ id hay URL của nó. Kèm mã xác nhận và hạn;
 *  - **đã ghi** ({@see self::confirmed()}): lần gọi thứ hai. `$record` là bản ghi thật; `$replayed`
 *    khi mã này đã được dùng trước đó và `$record` là bản ghi lần đó đã tạo.
 */
final class TwoStepOutcome
{
    private function __construct(
        public readonly ?Model $preview,
        public readonly ?string $token,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?Model $record,
        public readonly bool $replayed,
    ) {}

    public static function preview(Model $preview, string $token, CarbonImmutable $expiresAt): self
    {
        return new self($preview, $token, $expiresAt, null, false);
    }

    public static function confirmed(Model $record, bool $replayed): self
    {
        return new self(null, null, null, $record, $replayed);
    }

    public function needsConfirmation(): bool
    {
        return $this->record === null;
    }
}
