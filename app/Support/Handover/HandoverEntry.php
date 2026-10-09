<?php

namespace App\Support\Handover;

use App\Actions\Storage\MaterialiseStoredFile;
use App\Enums\DocumentGroup;
use Carbon\CarbonInterface;

/**
 * MỘT tệp của gói bàn giao (M7 Task 4): kết quả của `CollectHandoverEntries`, đầu vào chung của
 * việc dựng zip và việc dựng `MUC-LUC.pdf` — hai nơi phải dùng CÙNG MỘT danh sách và cùng một số
 * thứ tự (R8: "cho mục lục với zip cùng một cách đánh số").
 *
 * M14 (kế hoạch R12): entry KHÔNG còn mang đường dẫn tuyệt đối của tệp. Tệp có thể nằm trên kho Google
 * Drive, nơi `$disk->path()` trả một chuỗi không tồn tại mà không báo lỗi. Entry mang media nào, trên
 * đĩa nào, khoá nào, cỡ và md5 bao nhiêu; `BuildHandoverPackage` nhờ {@see MaterialiseStoredFile} cho
 * một đường dẫn cục bộ đã kiểm ngay trước khi nén.
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
        /** Mã dòng `media` của tệp — `BuildHandoverPackage` đọc lại dòng này ngay trước khi tải. */
        public int $mediaId,
        /** Đĩa ghi trên dòng `media` lúc chọn (`private` hay `documents_remote`). */
        public string $disk,
        /** Khoá của tệp trên đĩa đó (`<media_id>/<file_name>`). */
        public string $relativePath,
        /** Cỡ tệp theo dòng `media`, byte — cộng lại thành T của lần kiểm chỗ trống (R12). */
        public int $size,
        /** `media.checksum_md5` (ghi lúc đẩy lên kho); `null` cho tệp chưa từng đẩy. */
        public ?string $md5,
        public int $documentId,
        public ?CarbonInterface $date,
    ) {}
}
