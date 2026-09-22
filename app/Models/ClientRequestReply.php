<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\ClientRequestReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ClientRequestReply extends Model
{
    /** @use HasFactory<ClientRequestReplyFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['request_id', 'author_type', 'author_id', 'content'];

    /**
     * `whereHas('request')` kế thừa nguyên điều kiện của `ClientRequest`, nên cách đọc "của
     * chính mình" — **theo `Client`, không theo `ClientUser`** (phán quyết 19/09/2026) — chỉ tồn
     * tại một chỗ: {@see ClientRequest::applyClientPortalConstraints()}. Khách đọc được cả câu
     * mình hỏi lẫn câu văn phòng trả lời, đúng SPEC §8.3 mục 7 ("xem lại lịch sử trao đổi").
     *
     * `author_type`/`author_id` KHÔNG phải một điều kiện lọc: một trả lời của nhân sự trong một
     * yêu cầu khách thấy được là thứ khách PHẢI đọc được — đó chính là phản hồi.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereHas('request');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class, 'request_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
