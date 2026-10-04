<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildMatterTypeMix;

/**
 * Bảng cơ cấu lĩnh vực trên trang của một người (M13 Task 5, R8 "ngữ cảnh thay cho chuẩn hoá"): số
 * vụ "bây giờ" của MỘT người, chia theo loại vụ việc, như MỘT người xem đọc được — mọi vụ đã nằm
 * trong `Matter::listableBy($viewer)` (R4). Dựng bởi {@see BuildMatterTypeMix}.
 *
 * `$byLead` nói bảng đếm trên tập nào, và màn hình in đúng câu đó lên tiêu đề:
 *  - `true` — vụ đang mở người đó phụ trách: chính tập của cột N1, nên tổng các dòng bằng N1;
 *  - `false` — người đó không đứng tên phụ trách vụ được (`TeamRoster::leadsMatters()` sai, ví dụ trợ
 *    lý): vụ đang mở người đó giữ ghế luật sư cộng sự hoặc trợ lý — tập của cột N2, tổng bằng N2.
 *
 * Theo QUYỀN, không theo "người đó có vụ nào không" (R6): một luật sư chỉ phụ trách vụ `restricted`
 * nhận bảng rỗng của tập N1 với trưởng phòng, không phải bảng của tập N2.
 */
final readonly class MatterTypeMix
{
    /**
     * @param  list<array{matterTypeId: int, name: string, matters: int}>  $rows  nhiều vụ nhất trước, bằng nhau thì theo tên
     */
    public function __construct(
        public bool $byLead,
        public array $rows,
    ) {}
}
