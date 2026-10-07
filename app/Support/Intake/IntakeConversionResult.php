<?php

namespace App\Support\Intake;

use App\Models\IntakeRequest;
use App\Support\OpenMatterResult;

/**
 * Kết quả `ConvertIntakeToMatter` (M10 Task 4, R3): bản ghi tiếp nhận đã `won` và đã liên kết hai
 * chiều, kết quả mở vụ của `OpenMatter` (vụ việc + kết quả kiểm tra xung đột lúc chuyển đổi + có ghi
 * đè hay không — màn hình nói thật về lần kiểm tra đó như form mở vụ), và người liên hệ có thành một
 * hồ sơ khách hàng MỚI hay được gắn vào một khách hàng đã có.
 */
final readonly class IntakeConversionResult
{
    public function __construct(
        public IntakeRequest $intake,
        public OpenMatterResult $opening,
        public bool $clientCreated,
    ) {}
}
