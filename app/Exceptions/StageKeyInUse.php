<?php

namespace App\Exceptions;

use App\Models\MatterType;
use DomainException;

/**
 * Chốt chặn thứ hai cho việc đổi `key` của một giai đoạn ĐANG được hồ sơ hoặc dòng tiến độ dùng
 * (xem docblock `MatterTypeStage::booted()`). Tầng form (`StagesRelationManager`) đã chặn đường
 * bấm nút thật bằng một lỗi gắn vào ô `key`; lớp này phủ mọi đường ghi khác — Action, artisan,
 * seeder, factory — cùng lý do `DuplicateStageKey` tồn tại song song với `scopedUnique`.
 */
class StageKeyInUse extends DomainException
{
    public static function make(MatterType $matterType, string $key): self
    {
        return new self(__('exceptions.stage_key_in_use', ['name' => $matterType->name, 'key' => $key]));
    }

    /**
     * M9 Task 6: lý do là `$count` đợt thanh toán còn chờ hồ sơ chạm giai đoạn này
     * (`MatterTypeStage::instalmentsAwaitingStage()`) — câu nêu số đợt, cùng con số với form và
     * với luật xoá.
     */
    public static function awaitedByInstalments(MatterType $matterType, string $key, int $count): self
    {
        return new self(__('exceptions.stage_key_awaited_by_instalments', ['name' => $matterType->name, 'key' => $key, 'count' => $count]));
    }
}
