<?php

namespace App\Models;

use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    /**
     * Mẫu ghi cho một thư KHÔNG khai báo mẫu nào (`App\Mail\OutboundHeaders::TEMPLATE`).
     *
     * Một giá trị nói thẳng, chứ không phải `null` và cũng không phải một lần ném lỗi. Nhật ký
     * này tồn tại để trả lời "tôi không nhận được thông báo", nên nó phải ghi được cả những thư
     * mà không ai nhớ là mình gửi — một `Mail::raw()` trong một lần vá vội vẫn để lại dấu vết,
     * và dấu vết ấy tự nói ra rằng nó chưa được khai báo.
     */
    public const TEMPLATE_UNDECLARED = 'undeclared';

    protected $fillable = [
        'channel', 'recipient', 'template', 'payload', 'related_type', 'related_id', 'status', 'sent_at', 'error',
    ];

    protected $attributes = ['status' => 'queued', 'channel' => 'email'];

    protected function casts(): array
    {
        return [
            'channel' => OutboundChannel::class,
            'status' => OutboundStatus::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Nhật ký thông báo gửi đi phục vụ tra cứu nội bộ (SPEC §4.15). Chặn sạch ở tầng truy vấn
     * thay vì trông vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
