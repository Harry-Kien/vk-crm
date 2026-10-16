<?php

namespace App\Actions\Mcp;

use App\Enums\AiOfficeStatus;
use App\Enums\AiSetupStep;
use Carbon\CarbonImmutable;

/**
 * Thẻ trạng thái tổng và danh sách "Các bước để bắt đầu dùng" của trang "Kết nối AI" (làn ai,
 * 2026-10-15) — DTO của {@see SummarizeAiSetup}. Mọi con số là trạng thái THẬT lúc đọc.
 *
 *  - `status`: {@see AiOfficeStatus} theo hai công tắc;
 *  - `assessmentFiledOn`: ngày đã nộp hồ sơ đánh giá tác động (R12 mục 3), `null` khi chưa ghi;
 *  - `staffEnabled`: nhân sự đang hoạt động có chế độ khác "Tắt";
 *  - `staffAcknowledged`, `staffConnected`: trong số đó, người đã cam kết đúng phiên bản chính sách
 *    hiện hành / người đang có ít nhất một kết nối;
 *  - `callsLastWeek`: số lần gọi tool trong 7 ngày, MỌI kết cục (cùng kỳ và cùng nhãn "Lượt gọi" với khối
 *    "Hoạt động", {@see SummarizeMcpActivity}); `callsOkLastWeek`: trong số đó, lần kết cục `ok` — thẻ
 *    ghi "x thành công, y không thành công" để con số không đọc như số lần dùng được việc (n2);
 *  - `mattersAllowed`: số vụ việc AI thấy được về mặt vụ — mang cờ "cho phép AI" (R9) và không phải
 *    vụ hạn chế (cùng mệnh đề 2 của {@see McpMatterScope}).
 */
final readonly class AiSetupSummary
{
    public function __construct(
        public AiOfficeStatus $status,
        public ?CarbonImmutable $assessmentFiledOn,
        public int $staffEnabled,
        public int $staffAcknowledged,
        public int $staffConnected,
        public int $callsLastWeek,
        public int $callsOkLastWeek,
        public int $mattersAllowed,
    ) {}

    /** Cảnh báo vàng của thẻ trạng thái: trợ lý AI đang bật mà chưa ghi ngày nộp hồ sơ. */
    public function needsAssessment(): bool
    {
        return $this->status !== AiOfficeStatus::Off && $this->assessmentFiledOn === null;
    }

    /** Một bước đã xong theo trạng thái thật — luật của từng bước ở docblock {@see AiSetupStep}. */
    public function isDone(AiSetupStep $step): bool
    {
        return match ($step) {
            AiSetupStep::Legal => $this->assessmentFiledOn !== null,
            AiSetupStep::Server => $this->status !== AiOfficeStatus::Off,
            AiSetupStep::StaffEnabled => $this->staffEnabled > 0,
            AiSetupStep::StaffAcknowledged => $this->staffEnabled > 0 && $this->staffAcknowledged === $this->staffEnabled,
            AiSetupStep::StaffConnected => $this->staffConnected > 0,
            AiSetupStep::Matters => $this->mattersAllowed > 0,
        };
    }
}
