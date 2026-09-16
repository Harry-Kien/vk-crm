<?php

namespace App\Support\Files;

use App\Exceptions\FileRejected;
use Throwable;

/**
 * Quét virus thật qua `clamd`, dùng khi `CLAMAV_ENABLED=true` (SPEC §6.6 bước 5). Nói chuyện với
 * daemon bằng giao thức `INSTREAM` của ClamAV trực tiếp qua Unix socket
 * (`config('vkcrm.clamav.socket')`) — không qua một binding PHP nào cả, vì môi trường dev/CI của
 * dự án này không được cài thêm gói ngoài whitelist (không Redis/Horizon/Octane/... — xem
 * `CLAUDE.md`) và không có daemon ClamAV thật để cài đặt cùng.
 *
 * **Lớp này chưa chạy với một `clamd` THẬT, nhưng đã chạy với một daemon giả nói đúng giao thức.**
 * `ClamAvScannerTest` dựng một tiến trình PHP riêng nghe trên Unix socket, đọc đúng khung
 * `INSTREAM` (4 byte độ dài big-endian trước mỗi khối, khối độ dài 0 kết thúc), đếm số byte nhận
 * được và trả về đúng câu trả lời mà từng test muốn — kể cả câu trả lời im lặng. Nhờ vậy ba điều
 * quan trọng nhất đã được kiểm bằng hành vi chứ không bằng lời hứa: tệp tới nơi TRỌN VẸN, câu trả
 * lời được hiểu ĐÚNG, và một daemon im lặng KHÔNG treo worker.
 *
 * Một giới hạn phải nói thẳng: test đếm byte chỉ chứng minh luồng trọn vẹn ở ĐƯỜNG THUẬN. Không
 * có cách nào ép một socket Unix cục bộ ghi thiếu byte theo ý muốn, nên `writeAll()` có test
 * chống thoái lui chứ không có test tái hiện được lỗi cũ. Cái còn lại vẫn nên làm một lần
 * bằng tay khi bật `CLAMAV_ENABLED=true` trên máy chủ thật: đối chiếu câu chữ mà đúng bản `clamd`
 * ở đó trả về (một tệp EICAR là đủ).
 *
 * **Chặn khi không quét được, không cho qua.** Nếu không kết nối được socket, nếu một lần ghi
 * không đẩy hết byte đi, nếu daemon im quá `timeoutSeconds`, hoặc nếu câu trả lời không phải đúng
 * một kết luận sạch, lớp này từ chối tệp (`FileRejected::scannerUnavailable()`) thay vì coi như
 * sạch. Một tệp không quét được không phải một tệp đã được xác nhận sạch — im lặng cho qua khi
 * scanner hỏng biến `CLAMAV_ENABLED=true` thành một lời hứa suông đúng vào lúc nó cần hoạt động
 * nhất (daemon quá tải hay sập).
 *
 * **Ba chỗ mà bản đầu của lớp này tự mâu thuẫn với đoạn trên, nay đã sửa:**
 * 1. Không một giá trị trả về của `fwrite()` nào được kiểm. Một lần ghi thiếu byte là vô hình,
 *    nên clamd có thể chỉ quét phần đầu tệp rồi trả `OK` — lỗi im lặng đúng kiểu nguy hiểm nhất.
 *    Nay mọi lần ghi đi qua `writeAll()`, lặp tới khi hết byte hoặc từ chối tệp.
 * 2. Timeout 5 giây của `stream_socket_client` chỉ phủ lúc KẾT NỐI. Không có `stream_set_timeout()`
 *    thì lần đọc câu trả lời rơi về `default_socket_timeout` (60 giây), và một `continue` thay vì
 *    `break` trong vòng đọc tệp còn quay vô hạn được. Nay có timeout đọc/ghi riêng, có kiểm
 *    `stream_get_meta_data()['timed_out']`, và vòng lặp luôn thoát được.
 * 3. Câu trả lời được so bằng `str_contains($response, 'OK')` — không neo ở đâu cả, nên một tên
 *    chữ ký hay một câu lỗi có hai chữ đó ở giữa (`Heuristics.OK.Broken ERROR`) đủ để một tệp
 *    chưa quét xong được ghi vào hồ sơ như đã sạch. Nay so theo đúng khuôn của giao thức.
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK_SIZE = 8192;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    /** Câu trả lời của clamd là một dòng ngắn; nhiều hơn thế là dấu hiệu nói chuyện sai giao thức. */
    private const MAX_REPLY_BYTES = 4096;

    /**
     * @param  string  $socketPath  Unix socket của `clamd`.
     * @param  int  $timeoutSeconds  Hạn cho MỖI lần đọc/ghi sau khi đã kết nối. Mặc định lấy từ
     *                               `config('vkcrm.clamav.timeout')`: đủ dài cho một tệp 20 MB đi
     *                               qua trên máy chủ bận, đủ ngắn để một daemon câm không khoá
     *                               worker lâu hơn mức chấp nhận được.
     */
    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeoutSeconds = 0,
    ) {}

    public function scan(string $path): void
    {
        $socket = $this->connect();

        if ($socket === null) {
            throw FileRejected::scannerUnavailable();
        }

        try {
            $response = $this->sendStream($socket, $path);
        } finally {
            fclose($socket);
        }

        $this->interpret($response);
    }

    /**
     * Hỏi thẳng daemon bằng lệnh `PING` của ClamAV và đợi `PONG`.
     *
     * Bản đầu `return true;` cứng, nên `php artisan about` báo "quét virus ĐANG BẬT" trên một máy
     * chủ không có daemon nào — đúng một câu hỏi mà method này tồn tại để trả lời (xem docblock
     * `VirusScanner::isActive()`). Một câu trả lời "có" không kiểm chứng còn tệ hơn không có câu
     * trả lời nào: người vận hành ngừng đi kiểm tra.
     *
     * Không bao giờ ném: `about` và mọi màn hình chẩn đoán phải hiển thị được kể cả khi hạ tầng
     * đang hỏng — một lỗi 500 ở đúng trang dùng để chẩn đoán là thứ vô dụng nhất.
     */
    public function isActive(): bool
    {
        $socket = $this->connect();

        if ($socket === null) {
            return false;
        }

        try {
            $this->writeAll($socket, "zPING\0");

            return str_starts_with($this->readReply($socket), 'PONG');
        } catch (Throwable) {
            return false;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource|null
     */
    private function connect()
    {
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
        );

        if ($socket === false) {
            return null;
        }

        // Hạn cho từng lần đọc/ghi SAU khi đã kết nối. Thiếu dòng này, lần chờ câu trả lời rơi về
        // `default_socket_timeout` của PHP (60 giây) bất kể timeout kết nối ở trên là bao nhiêu.
        stream_set_timeout($socket, $this->timeout());

        return $socket;
    }

    private function timeout(): int
    {
        if ($this->timeoutSeconds > 0) {
            return $this->timeoutSeconds;
        }

        $configured = config('vkcrm.clamav.timeout');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 30;
    }

    /**
     * So câu trả lời với đúng khuôn của giao thức thay vì tìm chuỗi con.
     *
     * clamd trả về một dòng `<tên luồng>: <kết luận>` — `stream: OK`,
     * `stream: Eicar-Test-Signature FOUND`, hoặc `... ERROR`. Chỉ một câu KẾT THÚC bằng `OK`
     * đứng riêng sau dấu hai chấm (`stream: OK`), hoặc một câu `OK` trần, mới là kết luận sạch;
     * câu kết thúc bằng `FOUND` là nhiễm; mọi thứ khác — rỗng, `ERROR`, hay một câu chỉ có chữ
     * `OK` nằm lọt giữa như `Heuristics.OK.Broken ERROR` — là "không xác nhận được", tức từ chối.
     *
     * @throws FileRejected
     */
    private function interpret(string $response): void
    {
        if (preg_match('/\bFOUND$/', $response) === 1) {
            throw FileRejected::virusDetected();
        }

        if (preg_match('/(?:^|:\s)OK$/', $response) !== 1) {
            throw FileRejected::scannerUnavailable();
        }
    }

    /**
     * @param  resource  $socket
     *
     * @throws FileRejected
     */
    private function sendStream($socket, string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw FileRejected::unreadable();
        }

        $this->writeAll($socket, "zINSTREAM\0");

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                // `break`, KHÔNG `continue`: `fread()` trả `''` mà chưa `feof()` nghĩa là handle
                // không đọc tiếp được: `continue` ở đây quay vô hạn và khoá worker mãi mãi.
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
            }
        } finally {
            fclose($handle);
        }

        // Khối độ dài 0 báo cho clamd biết luồng đã kết thúc.
        $this->writeAll($socket, pack('N', 0));

        return $this->readReply($socket);
    }

    /**
     * Ghi cho tới khi hết byte, hoặc từ chối tệp.
     *
     * `fwrite()` được phép ghi ÍT hơn số byte đưa vào — trên một socket đó là chuyện bình thường
     * khi buffer gửi đầy. Bỏ qua giá trị trả về nghĩa là một tệp bị cắt cụt vẫn được gửi đi như
     * thể trọn vẹn, clamd quét phần đầu và trả `OK`, và hệ thống ghi nhận một tệp "đã sạch" mà
     * phần đuôi chưa ai nhìn. Đây là đường duy nhất trong cả hệ thống dẫn tới một lần cho qua im
     * lặng, nên nó phải là đường được kiểm kỹ nhất.
     *
     * @param  resource  $socket
     *
     * @throws FileRejected
     */
    private function writeAll($socket, string $data): void
    {
        $remaining = strlen($data);

        while ($remaining > 0) {
            $written = @fwrite($socket, substr($data, strlen($data) - $remaining), $remaining);

            // `0` cũng là thất bại, không phải "thử lại": với timeout đã đặt ở trên, một lần ghi
            // không nhích được byte nào nghĩa là socket đã hết hạn hoặc phía kia đã đóng.
            if ($written === false || $written === 0) {
                throw FileRejected::scannerUnavailable();
            }

            $remaining -= $written;
        }
    }

    /**
     * Đọc đúng một dòng trả lời, có hạn giờ và có hạn độ dài.
     *
     * @param  resource  $socket
     *
     * @throws FileRejected
     */
    private function readReply($socket): string
    {
        $reply = '';

        while (strlen($reply) < self::MAX_REPLY_BYTES) {
            $chunk = fread($socket, 1024);

            if ($chunk === false || $chunk === '') {
                // `fread()` trả rỗng vì hết hạn giờ là một daemon CÂM, không phải một daemon nói
                // "sạch" — phân biệt được hai thứ này chính là điều mà `stream_set_timeout()` ở
                // trên mua về.
                if (stream_get_meta_data($socket)['timed_out'] ?? false) {
                    throw FileRejected::scannerUnavailable();
                }

                break;
            }

            $reply .= $chunk;

            if (str_contains($reply, "\n")) {
                break;
            }
        }

        return trim($reply);
    }
}
