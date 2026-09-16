<?php

namespace App\Support\Files;

use App\Exceptions\FileRejected;

/**
 * Quét virus thật qua `clamd`, dùng khi `CLAMAV_ENABLED=true` (SPEC §6.6 bước 5). Nói chuyện với
 * daemon bằng giao thức `INSTREAM` của ClamAV trực tiếp qua Unix socket
 * (`config('vkcrm.clamav.socket')`) — không qua một binding PHP nào cả, vì môi trường dev/CI của
 * dự án này không được cài thêm gói ngoài whitelist (không Redis/Horizon/Octane/... — xem
 * `CLAUDE.md`) và không có daemon ClamAV thật để cài đặt cùng.
 *
 * **Quan trọng — lớp này CHƯA được kiểm thử với một `clamd` thật đang chạy.** Không có daemon
 * ClamAV trong môi trường container của milestone này để test tích hợp; `FileGuardTest` không
 * chạm tới lớp này. Đúng giao thức `INSTREAM` (4 byte độ dài big-endian trước mỗi khối dữ liệu,
 * kết thúc bằng một khối độ dài 0) đã được cài theo tài liệu chính thức của ClamAV, nhưng việc
 * bật `CLAMAV_ENABLED=true` lần đầu trên một máy chủ thật nên đi kèm một lần kiểm thử tay chống
 * lại `clamd` thật trước khi tin tưởng nhánh này.
 *
 * **Chặn khi không quét được, không cho qua.** Nếu không kết nối được socket, hoặc `clamd` trả
 * lời không đúng định dạng mong đợi (`... OK` / `... FOUND`), lớp này từ chối tệp
 * (`FileRejected::scannerUnavailable()`) thay vì coi như sạch. Một tệp không quét được không phải
 * một tệp đã được xác nhận sạch — im lặng cho qua khi scanner hỏng biến `CLAMAV_ENABLED=true`
 * thành một lời hứa suông đúng vào lúc nó cần hoạt động nhất (daemon quá tải hay sập).
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK_SIZE = 8192;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly string $socketPath,
    ) {}

    public function scan(string $path): void
    {
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
        );

        if ($socket === false) {
            throw FileRejected::scannerUnavailable();
        }

        try {
            $response = $this->sendStream($socket, $path);
        } finally {
            fclose($socket);
        }

        if (str_contains($response, 'FOUND')) {
            throw FileRejected::virusDetected();
        }

        if (! str_contains($response, 'OK')) {
            throw FileRejected::scannerUnavailable();
        }
    }

    public function isActive(): bool
    {
        return true;
    }

    /**
     * @param  resource  $socket
     */
    private function sendStream($socket, string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw FileRejected::unreadable();
        }

        fwrite($socket, "zINSTREAM\0");

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        } finally {
            fclose($handle);
        }

        // Khối độ dài 0 báo cho clamd biết luồng đã kết thúc.
        fwrite($socket, pack('N', 0));

        return trim((string) fread($socket, 4096));
    }
}
