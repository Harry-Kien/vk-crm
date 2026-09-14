<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case InternalDraft = 'internal_draft';
    case PendingApproval = 'pending_approval';
    case SignedFiled = 'signed_filed';
    case Published = 'published';

    public function label(): string
    {
        return __('enums.document_status.'.$this->value);
    }
}
