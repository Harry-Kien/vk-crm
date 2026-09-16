<?php

namespace App\Exceptions;

use App\Support\ConflictCheckResult;
use DomainException;

/**
 * Kết quả kiểm tra xung đột lợi ích ở mức vàng, nhưng caller CHƯA xác nhận đã xem xét (SPEC §11
 * bullet 3: "cho lưu nhưng hiện cảnh báo, bắt người tạo tích xác nhận đã xem xét"). Khác
 * `ConflictBlocked`: đây không phải một lệnh cấm vĩnh viễn — `OpenMatter` LUÔN cho lưu ở mức
 * vàng, chỉ cần caller hiển thị `result` (danh sách bản ghi trùng) cho người dùng tích xác nhận,
 * rồi gọi lại `OpenMatter::handle()` với `acknowledged: ConflictLevel::Yellow`. Cùng khuôn với
 * `ConflictBlocked` (mang `ConflictCheckResult` ra ngoài) để một nơi (Filament) xử lý cả hai
 * luồng bằng cùng một cách hiển thị lại bảng bản ghi trùng.
 */
class ConflictAcknowledgementRequired extends DomainException
{
    public function __construct(
        string $message,
        public readonly ConflictCheckResult $result,
    ) {
        parent::__construct($message);
    }

    public static function make(ConflictCheckResult $result): self
    {
        return new self(__('exceptions.conflict_acknowledgement_required'), $result);
    }
}
