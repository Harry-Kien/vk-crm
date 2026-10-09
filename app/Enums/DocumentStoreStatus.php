<?php

namespace App\Enums;

/**
 * Tình trạng kho tài liệu (Google Drive) ở lần kiểm sức khoẻ gần nhất — cột
 * `system_health.document_store_status` (kế hoạch M14, "Mô hình dữ liệu"; R9, R13). NULL = chưa có lần
 * kiểm nào ghi cột này.
 *
 * - `ok`: kho trả lời, chia sẻ đúng luật;
 * - `degraded`: kho trả lời được nhưng có điều người vận hành cần xem; điều kiện cụ thể do lượt
 *   kiểm sức khoẻ kho đặt ra (kế hoạch M14, Task 5);
 * - `unavailable`: lỗi TẠM THỜI (mạng, 429, 5xx, ngắt mạch đang mở) — tải xuống trả trang 503;
 * - `misconfigured`: lỗi KHÔNG tự hết (khoá sai, hết hạn mức, mất quyền, Shared Drive không tồn
 *   tại) — cần người sửa cấu hình, thử lại không giúp gì.
 */
enum DocumentStoreStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case Misconfigured = 'misconfigured';

    public function label(): string
    {
        return __('enums.document_store_status.'.$this->value);
    }
}
