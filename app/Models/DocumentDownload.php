<?php

namespace App\Models;

use Database\Factories\DocumentDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentDownload extends Model
{
    /** @use HasFactory<DocumentDownloadFactory> */
    use HasFactory;

    protected $fillable = ['document_id', 'downloader_type', 'downloader_id', 'ip', 'user_agent', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
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
