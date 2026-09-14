<?php

namespace App\Models;

use App\Models\Concerns\RestrictedToClientPortal;
use Database\Factories\DocumentDownloadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentDownload extends Model
{
    /** @use HasFactory<DocumentDownloadFactory> */
    use HasFactory;

    use RestrictedToClientPortal;

    protected $fillable = ['document_id', 'downloader_type', 'downloader_id', 'ip', 'user_agent', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }

    /**
     * Nhật ký tải về là chứng cứ nội bộ (SPEC §4.12). Chặn sạch ở tầng truy vấn thay vì trông
     * vào việc không ai viết resource cho nó.
     */
    public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void
    {
        $query->whereRaw('1 = 0');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function downloader(): MorphTo
    {
        return $this->morphTo();
    }
}
