<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Kho tài liệu (Google Drive) TẠM THỜI không trả lời được (kế hoạch M14, R9): lỗi kết nối, hết thời
 * gian chờ, 429, 5xx, hoặc ngắt mạch đang mở. Thử lại về sau có thể được.
 *
 * Luật cho nơi bắt (R9): người tải thấy trang 503 tiếng Việt kèm `Retry-After`, không bao giờ trang
 * 500; job đẩy tệp thử lại theo backoff của nó. Vì vậy thông điệp là câu hiển thị được cho người
 * dùng: không mã tệp, không chi tiết kỹ thuật. Nguyên nhân gốc (nếu có) nằm ở `getPrevious()`, chỉ
 * đi vào log.
 *
 * Khác {@see DocumentStorageMisconfigured}: lỗi đó không tự hết, cần người sửa cấu hình.
 */
class DocumentStorageUnavailable extends RuntimeException
{
    public static function temporarily(?Throwable $previous = null): self
    {
        return new self(__('storage.exceptions.unavailable'), 0, $previous);
    }
}
