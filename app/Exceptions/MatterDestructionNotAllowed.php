<?php

namespace App\Exceptions;

use App\Actions\Matter\RecordMatterDestruction;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * M7 Task 6 (R5): hồ sơ chưa ở trạng thái ghi được quyết định tiêu huỷ
 * ({@see RecordMatterDestruction}). Lỗi về TRẠNG THÁI của bản ghi, không phải lỗi hệ thống — màn
 * hình hiện nguyên câu này cho người bấm.
 */
class MatterDestructionNotAllowed extends DomainException
{
    public static function matterDeleted(): self
    {
        return new self(__('archive.destruction.exceptions.matter_deleted'));
    }

    public static function noArchive(): self
    {
        return new self(__('archive.destruction.exceptions.no_archive'));
    }

    public static function notClosed(): self
    {
        return new self(__('archive.destruction.exceptions.not_closed'));
    }

    public static function retentionNotExpired(?Carbon $retentionUntil): self
    {
        // `retention_until` là cột NOT NULL; `?` chỉ vì kiểu của thuộc tính Eloquent.
        return new self(__('archive.destruction.exceptions.retention_not_expired', [
            'date' => $retentionUntil?->format('d/m/Y') ?? '—',
        ]));
    }

    public static function alreadyRecorded(): self
    {
        return new self(__('archive.destruction.exceptions.already_recorded'));
    }
}
