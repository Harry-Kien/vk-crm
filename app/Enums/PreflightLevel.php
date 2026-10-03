<?php

namespace App\Enums;

use App\Actions\Deployment\RunPreflight;

/**
 * Mức độ của một dòng kết quả `vkcrm:preflight` (R1, kế hoạch M8 Task 1) —
 * {@see RunPreflight}. KHÔNG phải cột CSDL (không có bảng nào lưu kết
 * quả lệnh này), nhưng vẫn dùng enum backed-string theo quy ước CLAUDE.md để `label()` và mã màu
 * đi cùng một chỗ, và để hai lỗi chính tả "red"/"Red"/"RED" không lặng lẽ so sánh sai ở bất kỳ
 * chỗ nào đọc giá trị này.
 */
enum PreflightLevel: string
{
    case Red = 'red';
    case Yellow = 'yellow';
    case Green = 'green';

    public function label(): string
    {
        return __('preflight.levels.'.$this->value);
    }
}
