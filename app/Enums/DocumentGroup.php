<?php

namespace App\Enums;

enum DocumentGroup: string
{
    case ClientProvided = 'A';
    case Issued = 'B';
    case Authority = 'C';
    case Internal = 'D';

    public function label(): string
    {
        return __('enums.document_group.'.$this->value);
    }

    /** Nhóm D không bao giờ ra portal, kể cả chỉ xem. */
    public function isInternal(): bool
    {
        return $this === self::Internal;
    }
}
