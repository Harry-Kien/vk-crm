<?php

namespace App\Actions\Matter;

/**
 * Một dòng kết quả của {@see ReassignMatters} — MỘT vụ việc trong lô bàn giao hàng loạt (M7 Task
 * 2). Mảng trả về của `ReassignMatters::handle()` có đúng một phần tử cho mỗi id được truyền vào,
 * theo ĐÚNG thứ tự truyền vào (SPEC "báo cáo kết quả từng vụ, kể cả vụ thất bại, thay vì một
 * thông điệp chung").
 *
 * **`$matterCode`/`$matterTitle` là `null` khi — và CHỈ khi — actor không qua được `manageTeam`
 * trên vụ việc này ({@see ReassignMatters} bắt `AuthorizationException` riêng, không lẫn với ba
 * họ lỗi còn lại).** Đây là lớp chống rò rỉ DUY NHẤT cần có ở đây: `manageTeam` là câu ĐẦU TIÊN
 * `ReassignMatter::handle()` hỏi (trước cả `DB::transaction`), nên MỌI exception khác
 * (`ValidationException`, `DomainException`, `ModelNotFoundException`) chỉ ném ra được SAU KHI
 * actor đã qua cổng đó — tức actor đã hợp lệ để THẤY vụ việc này, và mã/tiêu đề của nó không còn
 * gì để giấu. Một vụ `restricted` mà actor (ví dụ trưởng phòng, không phải admin/lead) không
 * `manageTeam` được thì `$matterCode`/`$matterTitle` ở đây PHẢI là `null` — trang gọi hàm này
 * không được tự ý gắn lại mã/tiêu đề từ một nguồn khác (ví dụ đọc thẳng `Matter::find($matterId)`)
 * để "hiển thị đẹp hơn": làm vậy sẽ mở lại đúng lỗ rò mà việc tách trường `null` này tồn tại để
 * đóng.
 */
final readonly class BulkReassignMatterResult
{
    public function __construct(
        public int $matterId,
        public bool $success,
        public string $message,
        public ?string $matterCode = null,
        public ?string $matterTitle = null,
        public bool $suggestIntroduction = false,
    ) {}
}
