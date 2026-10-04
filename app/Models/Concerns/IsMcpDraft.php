<?php

namespace App\Models\Concerns;

use App\Exceptions\McpDraftDiscardIncomplete;
use App\Exceptions\McpDraftNotDestroyable;
use App\Models\ClientRequestReplyDraft;
use App\Models\StageLogDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Luật chung của hai bảng nháp do AI soạn (M11 R5, Task 7): `stage_log_drafts`
 * ({@see StageLogDraft}) và `client_request_reply_drafts`
 * ({@see ClientRequestReplyDraft}).
 *
 * Một nháp có đúng ba trạng thái: **đang chờ** (chưa dùng, chưa bỏ), **đã dùng** (cột "đã dùng"
 * của từng model — `used_stage_log_id` / `used_reply_id` — trỏ tới bản ghi thật mà một người trong
 * `/admin` đã gửi từ nháp, Task 12), và **đã bỏ** (`discarded_at`, kèm người và lý do).
 *
 * Hai điều không Action nào được làm khác, nên chúng nằm ở model (cùng cách làm với `Payment`):
 *
 *  - **Không xoá, vô điều kiện** (M6.5 R14 "sửa không xoá lịch sử"): hook `deleting` ném
 *    {@see McpDraftNotDestroyable}. Hai bảng không có `deleted_at`. Chỉ chặn được lời gọi qua model —
 *    một `DB::table()->delete()` hay một `Builder::delete()` không bắn sự kiện, cùng giới hạn với
 *    `Payment` và `StageLog`.
 *  - **Không có lần bỏ nào thiếu người hay thiếu lý do**: hook `saving` ném
 *    {@see McpDraftDiscardIncomplete} khi `discarded_at` có giá trị mà `discarded_by` rỗng, hoặc
 *    `discard_reason` rỗng hay chỉ có khoảng trắng. Action "Bỏ nháp" (Task 12) kiểm lý do trước bằng
 *    lỗi validation trên ô nhập, và ghi audit; đây là chốt cuối cho mọi đường ghi khác.
 *
 * Không `HasBlameable`: hai bảng không có `updated_by`, và `created_by` luôn do Action ghi tường
 * minh — trong request MCP không có phiên `web` nào để đoán.
 */
trait IsMcpDraft
{
    /** Tên cột trỏ tới bản ghi thật đã sinh ra từ nháp này. */
    abstract public static function usedColumn(): string;

    public static function bootIsMcpDraft(): void
    {
        static::deleting(function (): void {
            throw McpDraftNotDestroyable::make();
        });

        static::saving(function (Model $draft): void {
            if ($draft->getAttribute('discarded_at') === null) {
                return;
            }

            if ($draft->getAttribute('discarded_by') === null
                || trim((string) $draft->getAttribute('discard_reason')) === '') {
                throw McpDraftDiscardIncomplete::make();
            }
        });
    }

    /**
     * Nháp **đang chờ**: chưa dùng, chưa bỏ — định nghĩa duy nhất cho "nháp đang có" (khối "Nháp từ
     * AI (n)" của Task 12, số nháp trả lời đang có của `get_client_request` ở Task 11).
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull($this->qualifyColumn(static::usedColumn()))
            ->whereNull($this->qualifyColumn('discarded_at'));
    }

    /** Người sở hữu token MCP đã soạn nháp này. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function discarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }
}
