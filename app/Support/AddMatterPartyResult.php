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
 */
final readonly class AddMatterPartyResult
{
    public function __construct(
        public MatterParty $party,
        public ConflictCheckResult $result,
    ) {}
}
