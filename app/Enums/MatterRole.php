<?php

namespace App\Enums;

enum MatterRole: string
{
    case Lead = 'lead';
    case Associate = 'associate';
    case Assistant = 'assistant';
    case Observer = 'observer';

    public function label(): string
    {
        return __('enums.matter_role.'.$this->value);
    }

    /**
     * Các vai "giữ việc phụ" trong đội ngũ của một vụ (M13, cột N2 "Vụ đang tham gia"): luật sư
     * cộng sự và trợ lý. `lead` không có mặt vì người phụ trách đã được quy về qua
     * `matters.lead_lawyer_id` (N1); `observer` theo dõi chứ không giữ việc. Định nghĩa DUY NHẤT —
     * `Matter::scopeWithSupportingMember()` và `Matter::scopeWorkedOnBy()` đọc ở đây.
     *
     * @return list<self>
     */
    public static function supporting(): array
    {
        return [self::Associate, self::Assistant];
    }
}
