<?php

namespace App\Enums;

/**
 * Kết quả của MỘT lượt đẩy tệp từ vùng đệm (`private`) lên kho (kế hoạch M14, R2) — giá trị trả về
 * của Action đẩy tệp, dùng ở job hàng đợi, tác vụ quét và lệnh chuyển tệp cũ. Không lưu vào CSDL.
 *
 * - `pushed`: đã tải lên, md5 và kích thước khớp, đĩa của media đã đổi sang kho;
 * - `already_remote`: media đã ở trên kho từ trước (lượt khác làm xong), không làm gì;
 * - `gone`: media không còn (đã xoá trước hoặc trong lúc đẩy);
 * - `disabled`: kho chưa được bật (công tắc khác `google_drive` hoặc chưa có mốc bật);
 * - `locked`: một lượt khác đang giữ khoá đẩy của media này — job thả lại để chạy sau.
 */
enum PushOutcome: string
{
    case Pushed = 'pushed';
    case AlreadyRemote = 'already_remote';
    case Gone = 'gone';
    case Disabled = 'disabled';
    case Locked = 'locked';

    public function label(): string
    {
        return __('enums.push_outcome.'.$this->value);
    }
}
