<?php

namespace App\Support\Handover;

use App\Enums\DocumentGroup;
use Carbon\CarbonInterface;

/**
 * MỘT tệp của gói bàn giao (M7 Task 4): kết quả của `CollectHandoverEntries`, đầu vào chung của
 * việc dựng zip và việc dựng `MUC-LUC.pdf` — hai nơi phải dùng CÙNG MỘT danh sách và cùng một số
 * thứ tự (R8: "cho mục lục với zip cùng một cách đánh số").
 */
final readonly class HandoverEntry
{
    public function __construct(
        /** Số thứ tự trong mục lục, bắt đầu từ 1; cũng là `NN` đầu tên entry. */
        public int $number,
        public DocumentGroup $group,
        /** Tiêu đề gốc của tài liệu — dùng để IN trong mục lục, KHÔNG dùng làm tên entry. */
        public string $title,
        /** Đường dẫn entry trong zip: `<nhóm>/<NN>-<tên an toàn>.<đuôi>`. */
        public string $zipPath,
        /** Đường dẫn tuyệt đối tới tệp trên đĩa `private`. */
        public string $sourcePath,
        public int $documentId,
        public ?CarbonInterface $date,
    ) {}
}
