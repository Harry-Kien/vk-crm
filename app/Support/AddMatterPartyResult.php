<?php

namespace App\Support;

use App\Models\MatterParty;

/**
 * Kết quả một lần gọi `App\Actions\AddMatterParty::handle()`: bên vừa lưu VÀ kết quả kiểm tra
 * xung đột lợi ích đã dẫn tới việc lưu đó. `AddMatterParty` chỉ trả về `MatterParty` trước fix
 * round 2 — lỗi thật: caller (`PartiesRelationManager`) không còn cách nào hiển thị lại kết quả
 * kiểm tra trên đường THÀNH CÔNG (xanh, hoặc đỏ/vàng đã được ghi đè/xác nhận), nên một lần ghi đè
 * mức đỏ lưu ĐƯỢC mà không ai thấy đã ghi đè cái gì — đúng lúc "hiện kết quả tại chỗ" (SPEC §7.2)
 * quan trọng nhất. Bọc cả hai trong một object thay vì trả mảng `[party, result]` để chữ ký hàm
 * tự mô tả, cùng quy ước với `ConflictCheckResult`/`ConflictMatch` trong namespace này.
 *
 * **Mang thêm `$overridden` và `$overrideReason` (fix round 4, Critical C-1).** Cùng hình dạng,
 * cùng thứ tự, cùng lý do như `OpenMatterResult` — hai object là hai nhánh của cùng một quy tắc
 * SPEC §6.10 và hai màn hình gọi chúng phải nói được cùng một sự thật. Round 2 mới chỉ trả thêm
 * `$result`, và với chừng đó thì `PartiesRelationManager` KHÔNG THỂ nói thật kể cả khi câu chữ
 * được viết lại: `handle()` trả về BÌNH THƯỜNG ở hai đường rất khác nhau — mức xanh sạch, và mức
 * ĐỎ đã được một manager ghi đè kèm lý do — nên màn hình chỉ cầm `$result` phải ĐOÁN, và nó đoán
 * sai theo đúng hướng nguy hiểm nhất.
 *
 *  - `$overridden`: lần lưu này có đi qua cổng ghi đè mức đỏ hay không. KHÔNG suy ra được từ
 *    `$result->level` — đỏ xuất hiện ở CẢ nhánh bị chặn (ném `ConflictBlocked`) lẫn nhánh được ghi
 *    đè (trả về bình thường). Đây chính là điểm mà một phép suy luận từ `level` sẽ hỏng.
 *  - `$overrideReason`: lý do ĐÃ `trim` và đã ghi vĩnh viễn vào dòng `matter_party_added`, `null`
 *    khi không ghi đè. Hiện lại nguyên văn để người vừa gõ nó thấy mình vừa ký vào cái gì.
 */
final readonly class AddMatterPartyResult
{
    public function __construct(
        public MatterParty $party,
        public ConflictCheckResult $result,
        public bool $overridden = false,
        public ?string $overrideReason = null,
    ) {}
}
