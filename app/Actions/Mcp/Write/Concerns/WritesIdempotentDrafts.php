<?php

namespace App\Actions\Mcp\Write\Concerns;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Mcp\Write\DraftOutcome;
use App\Actions\Mcp\Write\DraftProgressUpdate;
use App\Actions\Mcp\Write\DraftRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\StageLogDraft;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * `idempotency_key` của hai tool nháp (M11 R6): {@see DraftProgressUpdate}, {@see DraftRequestReply}.
 * Tool nháp chỉ một bước, nên khoá này là thứ làm "gọi lại" an toàn: cùng người, cùng khoá → cùng
 * nháp, không nháp thứ hai.
 *
 * **Không phân biệt hoa thường, giống nhau ở hai CSDL** (rà soát Task 7, m4). Cột unique
 * `(created_by, idempotency_key)` so theo `utf8mb4_unicode_ci` trên MariaDB (không phân biệt hoa
 * thường) nhưng phân biệt trên SQLite của bộ test; khoá được chuyển về chữ thường TRƯỚC khi đọc hay
 * ghi ({@see self::normalizedKey()}), nên "Draft-ABC12" và "draft-abc12" là một khoá ở cả hai nơi. Tool
 * chỉ nhận chữ cái ASCII, chữ số và `. _ : -`, nên chữ thường hoá không gộp hai khoá có dấu khác nhau.
 *
 * **Một lần trúng khoá chỉ trả nháp cũ khi đúng là CÙNG lần soạn** (rà soát Task 7, m5): cùng vụ (hay
 * cùng yêu cầu) và cùng nội dung. Vụ/yêu cầu được hỏi lại qua tập R3 và cổng của web TRƯỚC khi đọc khoá
 * (thứ tự trong hai Action), nên một nháp của vụ đã rời tập MCP không bao giờ được đọc ra. Khoá trùng mà
 * khác vụ hay khác nội dung là lỗi {@see self::conflict()} — không lộ gì của nháp cũ, không nháp mới.
 */
trait WritesIdempotentDrafts
{
    use ReadsWithoutPortalScope;

    /** Độ dài của `idempotency_key` (R6: 8–64 ký tự; cột `string(64)`). */
    public const IDEMPOTENCY_KEY_MIN_LENGTH = 8;

    public const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    /** Chữ cái ASCII, chữ số và `. _ : -` — dạng tool nhận. */
    public const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9._:\-]{8,64}\z/';

    protected function normalizedKey(string $key): string
    {
        return strtolower($key);
    }

    /**
     * Nháp đã có của người này với khoá này, ở mọi trạng thái.
     *
     * @template TDraft of StageLogDraft|ClientRequestReplyDraft
     *
     * @param  class-string<TDraft>  $model
     * @return TDraft|null
     */
    protected function existingDraft(string $model, User $actor, string $key): StageLogDraft|ClientRequestReplyDraft|null
    {
        return $this->scopelessly($model::query())
            ->where('created_by', $actor->getKey())
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Chạy lần ghi; nếu một lần ghi song song cùng khoá thắng trước (unique vỡ), trả nháp của lần đó
     * qua `$replay` thay vì lỗi 500.
     *
     * @param  Closure(): DraftOutcome|null  $write
     * @param  Closure(): DraftOutcome  $replay
     */
    protected function writeOnce(Closure $write, Closure $replay): ?DraftOutcome
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            return $replay();
        }
    }

    protected function conflict(): ValidationException
    {
        return ValidationException::withMessages(['idempotency_key' => [__('mcp.tool_errors.idempotency_conflict')]]);
    }

    /** Chuỗi đã cắt khoảng trắng hai đầu; rỗng thì `null` — cách nháp lưu và so một ô tuỳ chọn. */
    protected function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
