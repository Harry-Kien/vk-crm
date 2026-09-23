<?php

namespace App\Actions\Schedule;

use App\Models\SystemHealth;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gọi HTTP GET tới dịch vụ giám sát cron bên ngoài (SPEC §2, biến môi trường `HEARTBEAT_URL`).
 *
 * **KHÔNG BAO GIỜ ĐƯỢC NÉM.** Đây là luật quan trọng nhất của lớp này, và lý do đáng nói ra:
 * nếu một dịch vụ giám sát chết mà làm chết luôn lịch chạy, thì chính cái đồng hồ báo cháy trở
 * thành đám cháy. Mọi hỏng hóc — tên miền không phân giải được, hết thời gian chờ, mã 500 — đều
 * bị bắt tại đây, ghi vào `last_heartbeat_error`, và tác vụ kết thúc bình thường.
 *
 * URL RỖNG LÀ TRẠNG THÁI HỢP LỆ, không phải lỗi cấu hình: trên máy dev và trong CI thì biến đó
 * luôn rỗng, và một cảnh báo mỗi năm phút ở đó sẽ dạy người ta bỏ qua cảnh báo. Rỗng thì không
 * gọi, và không ghi lỗi.
 */
class SendHeartbeat
{
    /** Ngắn có chủ ý: đây là một lần ping, không phải một lần tải dữ liệu. */
    private const TIMEOUT_SECONDS = 5;

    public function __invoke(): void
    {
        $this->handle();
    }

    public function handle(): ?SystemHealth
    {
        $url = (string) config('vkcrm.heartbeat_url');

        if ($url === '') {
            return null;
        }

        $health = SystemHealth::current();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);

            $health->forceFill($response->successful()
                ? ['last_heartbeat_at' => now(), 'last_heartbeat_error' => null]
                // Mã lỗi ghi kèm, vì "không gọi được" và "gọi được nhưng dịch vụ trả 500" là hai
                // việc phải xử lý khác nhau: một cái là mạng của mình, một cái là bên kia.
                : ['last_heartbeat_error' => 'HTTP '.$response->status()]);
        } catch (Throwable $e) {
            // Tên lớp đi kèm thông điệp: "Connection timed out" đọc giống hệt nhau cho một tên
            // miền sai, một cổng bị chặn và một dịch vụ đang quá tải.
            $health->forceFill([
                'last_heartbeat_error' => $e::class.': '.$e->getMessage(),
            ]);
        }

        $health->save();

        return $health;
    }
}
