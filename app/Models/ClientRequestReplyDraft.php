<?php

namespace App\Models;

use App\Models\Concerns\IsMcpDraft;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\ClientRequestReplyDraftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nháp trả lời một yêu cầu của khách, do tool MCP `draft_request_reply` soạn (M11 R5; Task 13 ghi,
 * Task 12 dùng hoặc bỏ). Không phải {@see ClientRequestReply}: về cấu trúc nó không thể tới cổng khách
 * và không đổi trạng thái luồng. Không có cột người nhận — tool không nhận địa chỉ nào (R5, R11).
 * Một người trong `/admin` mở nháp, sửa, rồi bấm Gửi — câu trả lời thật sinh ra qua
 * `ReplyToClientRequest` dưới tên NGƯỜI BẤM, và `used_reply_id` trỏ tới nó.
 *
 * Luật không xoá và luật bỏ nháp: {@see IsMcpDraft}.
 */
class ClientRequestReplyDraft extends Model
{
    /** @use HasFactory<ClientRequestReplyDraftFactory> */
    use HasFactory;

    use IsMcpDraft;
    use RestrictedToClientPortal;

    /**
     * `created_by` và bốn cột dùng/bỏ (`used_reply_id`, `discarded_at`, `discarded_by`,
     * `discard_reason`) do Action ghi tường minh — xem {@see StageLogDraft::$fillable}.
     */
    protected $fillable = ['request_id', 'content', 'idempotency_key'];

    protected function casts(): array
    {
        return ['discarded_at' => 'datetime'];
    }

    public static function usedColumn(): string
    {
        return 'used_reply_id';
    }

    public static function draftType(): string
    {
        return 'client_request_reply_draft';
    }

    /** Nháp là thứ văn phòng CHƯA quyết định nói ra — xem {@see StageLogDraft::applyClientPortalConstraints()}. */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'request_id');
    }

    public function usedReply(): BelongsTo
    {
        return $this->belongsTo(ClientRequestReply::class, 'used_reply_id');
    }
}
