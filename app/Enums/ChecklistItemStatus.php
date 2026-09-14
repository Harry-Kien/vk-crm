<?php

namespace App\Enums;

enum ChecklistItemStatus: string
{
    case Missing = 'missing';
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return __('enums.checklist_item_status.'.$this->value);
    }
}
