<?php

namespace App\Actions\Preference;

use App\Enums\ChartKind;
use App\Filament\Admin\Widgets\Concerns\HasSwitchableChartKind;
use App\Models\ChartPreference;
use App\Models\User;

/**
 * Ghi dạng biểu đồ một nhân sự vừa chọn cho một biểu đồ: một dòng cho mỗi cặp (người, biểu đồ), lần sau
 * ghi đè lần trước.
 *
 * Action này KHÔNG biết widget nào cho phép dạng nào — đó là việc của widget
 * ({@see HasSwitchableChartKind::updatedChartKind()} chỉ gọi tới đây sau khi đã đối chiếu với
 * `chartKinds()` của chính nó), và chiều đọc cũng tự đối chiếu lại
 * ({@see HasSwitchableChartKind::activeChartKind()}), nên một dòng "sai dạng" trong bảng không vẽ ra
 * được biểu đồ sai.
 *
 * Không ghi nhật ký hoạt động: đây là một lựa chọn xem của cá nhân, không phải thay đổi hồ sơ.
 * Một câu `upsert` trên khoá duy nhất (user_id, widget) của bảng, nên nguyên tử: hai lượt bấm đồng thời
 * của cùng một người trên cùng một biểu đồ thì lượt sau thắng, không lượt nào vấp ràng buộc duy nhất.
 */
class RememberChartKind
{
    public function handle(User $user, string $widget, ChartKind $kind): void
    {
        $now = now();

        ChartPreference::query()->upsert(
            [[
                'user_id' => $user->getKey(),
                'widget' => $widget,
                'chart_kind' => $kind->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'widget'],
            ['chart_kind', 'updated_at'],
        );
    }
}
