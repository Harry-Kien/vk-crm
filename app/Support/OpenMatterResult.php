<?php

namespace App\Support;

use App\Models\Matter;

/**
 * Kết quả một lần gọi `App\Actions\OpenMatter::handle()`: vụ việc vừa mở VÀ kết quả kiểm tra xung
 * đột lợi ích đã dẫn tới việc mở nó. Cùng khuôn với `AddMatterPartyResult`, vì hai Action là hai
 * nhánh của cùng một quy tắc SPEC §6.10 và caller của chúng phải hiển thị cùng một thứ.
 *
 * **Vì sao trả trần `Matter` là một lỗi thật, không phải chuyện gọn gàng (fix round 3, Critical).**
 * `handle()` trả về BÌNH THƯỜNG ở hai đường rất khác nhau: mức xanh sạch, và mức ĐỎ đã được một
 * manager ghi đè kèm lý do. Caller chỉ cầm một `Matter` thì không cách nào phân biệt hai đường đó,
 * nên `CreateMatter` suy ra "Action không ném gì ⟹ xanh sạch" và hiện câu "không tìm thấy bản ghi
 * trùng nào" MÀU XANH cho chính người vừa ghi đè một xung đột mức đỏ — về một xung đột chưa từng
 * được hiện ra cho họ xem, vì ở lượt gửi đầu tiên ô lý do ghi đè đã có sẵn nội dung. SPEC §6.10
 * bước 4 đòi "phải chứng minh được là đã kiểm tra"; một dòng activity log đúng mà màn hình nói
 * ngược lại thì phần chứng minh chỉ còn dành cho người đi đọc nhật ký sau này.
 *
 * Vì vậy object này mang thêm CHÍNH XÁC những gì màn hình cần để nói thật: `$overridden` (lần lưu
 * này có đi qua cổng ghi đè mức đỏ hay không) và `$overrideReason` (lý do đã ghi vào activity log,
 * đã `trim`, `null` khi không ghi đè). Không suy ra được từ `$result` — `$result->level` là ĐỎ ở cả
 * hai trường hợp "bị chặn" (ném ngoại lệ) và "được ghi đè" (trả về bình thường).
 */
final readonly class OpenMatterResult
{
    public function __construct(
        public Matter $matter,
        public ConflictCheckResult $result,
        public bool $overridden = false,
        public ?string $overrideReason = null,
    ) {}
}
