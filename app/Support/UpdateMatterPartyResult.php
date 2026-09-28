<?php

namespace App\Support;

use App\Models\MatterParty;

/**
 * Kết quả một lần gọi `App\Actions\UpdateMatterParty::handle()` (M6.5 Task 9, `conflict-05`): bên
 * vừa sửa VÀ kết quả kiểm tra xung đột lợi ích đã dẫn tới việc lưu đó.
 *
 * Cùng hình dạng, cùng lý do tồn tại như `AddMatterPartyResult`/`OpenMatterResult` (đọc docblock
 * hai lớp đó cho lập luận đầy đủ, không lặp lại ở đây): `handle()` trả về BÌNH THƯỜNG ở hai đường
 * rất khác nhau — mức xanh sạch, và mức ĐỎ đã được một manager ghi đè kèm lý do — nên caller chỉ
 * cầm `$result` không phân biệt được hai đường đó. Một lớp riêng (không dùng lại
 * `AddMatterPartyResult`) vì tên lớp phải nói đúng thao tác vừa xảy ra: `PartiesRelationManager`
 * gọi CẢ hai Action trên cùng một tab, và một kiểu trả về tên "Add" cho một lần SỬA sẽ đọc sai.
 */
final readonly class UpdateMatterPartyResult
{
    public function __construct(
        public MatterParty $party,
        public ConflictCheckResult $result,
        public bool $overridden = false,
        public ?string $overrideReason = null,
    ) {}
}
