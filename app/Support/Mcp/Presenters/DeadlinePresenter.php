<?php

namespace App\Support\Mcp\Presenters;

use App\Enums\CreatedVia;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;

/**
 * Một mốc thời hạn trong kết quả MCP (kế hoạch M11, tool `list_deadlines`, năm mốc của `get_matter`):
 * vụ việc (tham chiếu), tên, hạn, mức nghiêm trọng, người phụ trách, hoàn thành chưa, đã công bố chưa,
 * tạo qua đường nào, và `awaiting_confirmation` — mốc do AI tạo mà chưa ai bấm "Xác nhận" (nhãn "Tạo
 * qua AI, chưa xác nhận" trên web, R5). Không có `reminders_sent`, `created_by`, `confirmed_by`.
 */
final class DeadlinePresenter
{
    use ReadsLoadedRelations;

    public const FIELDS = [
        'id', 'matter', 'name', 'due_date', 'severity', 'severity_label', 'responsible', 'is_completed',
        'completed_at', 'is_published', 'created_via', 'created_via_label', 'awaiting_confirmation', 'url',
    ];

    /**
     * Cần nạp sẵn: `matter`, `responsible`.
     *
     * @return array<string, mixed>
     */
    public static function present(Deadline $deadline): array
    {
        /** @var Matter|null $matter */
        $matter = self::loaded($deadline, 'matter');
        /** @var User|null $responsible */
        $responsible = self::loaded($deadline, 'responsible');

        return [
            'id' => McpIds::encode(McpIds::DEADLINE, (int) $deadline->getKey()),
            'matter' => $matter === null ? null : MatterPresenter::reference($matter),
            'name' => (string) $deadline->name,
            'due_date' => $deadline->due_date?->toDateString(),
            'severity' => $deadline->severity?->value,
            'severity_label' => $deadline->severity?->label(),
            'responsible' => $responsible === null ? null : StaffPresenter::present($responsible),
            'is_completed' => (bool) $deadline->is_completed,
            'completed_at' => $deadline->completed_at?->toIso8601String(),
            'is_published' => (bool) $deadline->is_published,
            'created_via' => $deadline->created_via?->value,
            'created_via_label' => $deadline->created_via?->label(),
            'awaiting_confirmation' => $deadline->created_via === CreatedVia::Mcp && $deadline->confirmed_at === null,
            'url' => AdminUrls::deadline($deadline),
        ];
    }
}
