<?php

namespace App\Support\Mcp\Presenters;

use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;

/**
 * Một dòng nhật ký liên lạc trong kết quả của tool `log_communication` (kế hoạch M11, bảng tool 15;
 * Task 13) — dòng chính người gọi vừa ghi qua tool, hay bản xem trước của nó. Không có `created_by`,
 * `deleted_*`, lý do xoá. `summary` và `counterpart` là chữ văn phòng ghi (người liên lạc bỏ trống thì
 * là tên khách của vụ, thứ `get_matter` đã trả), không phải chữ khách viết.
 */
final class CommunicationLogPresenter
{
    use ReadsLoadedRelations;

    public const FIELDS = [
        'id', 'matter', 'type', 'type_label', 'occurred_at', 'duration_minutes', 'counterpart', 'summary',
        'is_visible_to_client', 'created_via', 'created_via_label', 'url',
    ];

    /** Bản xem trước: những gì SẼ được ghi — không id, không URL (bản chạy thử không tồn tại). */
    public const PREVIEW_FIELDS = [
        'matter', 'type', 'type_label', 'occurred_at', 'duration_minutes', 'counterpart', 'summary',
        'is_visible_to_client', 'created_via',
    ];

    /**
     * Cần nạp sẵn: `matter`.
     *
     * @return array<string, mixed>
     */
    public static function present(CommunicationLog $log): array
    {
        return [
            'id' => McpIds::encode(McpIds::COMMUNICATION, (int) $log->getKey()),
            ...self::preview($log),
            'created_via_label' => $log->created_via?->label(),
            'url' => AdminUrls::communicationLog($log),
        ];
    }

    /**
     * Cần nạp sẵn: `matter`.
     *
     * @return array<string, mixed>
     */
    public static function preview(CommunicationLog $log): array
    {
        /** @var Matter $matter */
        $matter = self::loaded($log, 'matter');

        return [
            'matter' => MatterPresenter::reference($matter),
            'type' => $log->type?->value,
            'type_label' => $log->type?->label(),
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'duration_minutes' => $log->duration_minutes,
            'counterpart' => $log->counterpart,
            'summary' => $log->summary,
            'is_visible_to_client' => (bool) $log->is_visible_to_client,
            'created_via' => $log->created_via?->value,
        ];
    }
}
