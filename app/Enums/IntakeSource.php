<?php

namespace App\Enums;

/**
 * Người liên hệ tìm tới văn phòng bằng đường nào. `WebsiteForm` có từ đầu dù M10 chưa có đường công
 * khai nào (R6): nhân sự dùng nó khi nhập tay một lead gửi từ website; form công khai là một
 * milestone riêng sau M10.
 */
enum IntakeSource: string
{
    case Phone = 'phone';
    case Zalo = 'zalo';
    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case WebsiteForm = 'website_form';
    case Other = 'other';

    public function label(): string
    {
        return __('enums.intake_source.'.$this->value);
    }
}
