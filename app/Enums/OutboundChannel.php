<?php

namespace App\Enums;

enum OutboundChannel: string
{
    case Email = 'email';
    case Zns = 'zns';
    case Sms = 'sms';

    /**
     * Thông báo đẩy trên điện thoại (M12 R13, SPEC §4.15 đính chính 2026-10-04) — dòng do
     * `App\Actions\Notification\RecordOutboundPush` ghi, mỗi máy một dòng. Cột `channel` là
     * `string(10)`: vừa, không cần migration.
     */
    case Push = 'push';

    public function label(): string
    {
        return __('enums.outbound_channel.'.$this->value);
    }
}
