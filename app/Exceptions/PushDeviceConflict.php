<?php

namespace App\Exceptions;

use App\Actions\Push\RegisterPushDevice;
use RuntimeException;

/**
 * M12 R8 — {@see RegisterPushDevice} thua chỉ mục UNIQUE của `endpoint` hai lần liên tiếp (một lượt
 * Bật đồng thời khác của cùng trình duyệt cứ chen vào). Controller trả 409; trình duyệt thử lại ở cú
 * bấm sau.
 *
 * CỐ Ý không mang ngoại lệ gốc (`$previous`) và không mang endpoint trong thông điệp: ngoại lệ của
 * CSDL chứa câu SQL KÈM giá trị ràng buộc — tức endpoint, một URL mang quyền gửi — và mọi ngoại lệ
 * lọt lên tới bộ xử lý lỗi đều được ghi vào `laravel.log` cùng chuỗi `previous` (R8: endpoint không
 * bao giờ vào log).
 */
final class PushDeviceConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Push device registration lost the endpoint unique index twice.');
    }
}
