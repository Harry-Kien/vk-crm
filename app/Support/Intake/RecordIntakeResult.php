<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;
use App\Support\ConflictCheckResult;

/**
 * Kết quả `RecordIntake`: bản ghi vừa lưu, kết quả kiểm tra xung đột ở lần chạm đầu (ĐÃ lưu vào bản
 * ghi), và các gợi ý trùng (R4). Không mang `summary` — Action không bao giờ ghi câu chuyện.
 */
final readonly class RecordIntakeResult
{
    public function __construct(
        public IntakeRequest $intake,
        public ConflictCheckResult $conflict,
        public IntakeDuplicates $duplicates,
    ) {}
}
