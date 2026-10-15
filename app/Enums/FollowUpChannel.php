<?php

namespace App\Enums;

/**
 * Kênh của một lần chăm sóc lead (làn kd, 2026-10-14, `intake_follow_ups.channel`): gọi điện, Zalo, email,
 * gặp trực tiếp — bốn cách văn phòng liên hệ lại một khách tiềm năng.
 */
enum FollowUpChannel: string
{
    case Call = 'call';
    case Zalo = 'zalo';
    case Email = 'email';
    case Meeting = 'meeting';

    public function label(): string
    {
        return __('leads.channels.'.$this->value);
    }
}
