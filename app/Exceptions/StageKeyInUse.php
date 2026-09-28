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
}
