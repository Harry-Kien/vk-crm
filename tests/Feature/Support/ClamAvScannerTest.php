<?php

use App\Exceptions\FileRejected;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\NullScanner;
use App\Support\Files\VirusScanner;
use Illuminate\Support\Str;

/**
 * `ClamAvScanner` là nhánh DUY NHẤT trong toàn hệ thống mà một tệp có thể được coi là sạch mà
 * không ai thật sự nhìn vào nó — nên nó là nhánh cần test nhất, và cho tới vòng rà soát này nó
 * hoàn toàn không có test nào ("lớp này CHƯA được kiểm thử với một `clamd` thật đang chạy", theo
 * đúng docblock của chính nó).
 *
 * Không có ClamAV trong container của dự án và cũng không được cài thêm (`CLAUDE.md`), nên các
 * test dưới đây dựng một `clamd` GIẢ: một tiến trình PHP riêng nghe trên Unix socket, nói đúng
 * giao thức `INSTREAM` (4 byte độ dài big-endian trước mỗi khối, khối độ dài 0 kết thúc), đếm số
 * byte thật sự nhận được, rồi trả về đúng câu trả lời mà test muốn. Như vậy kiểm được ba thứ mà
 * không mock nào kiểm được: toàn bộ tệp có tới nơi không, câu trả lời được hiểu đúng không, và
 * một daemon im lặng có treo worker không.
 */
function fakeClamdScript(): string
{
    return <<<'PHP'
    <?php
    // clamd giả: đối số [socket, reply, mode].
    // mode: instream (đọc hết luồng rồi trả lời) | silent (nhận kết nối rồi im) | ping
    [$socketPath, $reply, $mode] = [$argv[1], $argv[2], $argv[3]];

    @unlink($socketPath);
    $server = stream_socket_server('unix://'.$socketPath, $errno, $errstr);

    if ($server === false) {
        fwrite(STDERR, "khong mo duoc socket: {$errstr}\n");
        exit(1);
    }

    // Báo cho test biết socket đã sẵn sàng.
    file_put_contents($socketPath.'.ready', '1');

    $connection = @stream_socket_accept($server, 15);

    if ($connection === false) {
        exit(2);
    }

    $command = '';

    while (! str_contains($command, "\0")) {
        $byte = fread($connection, 1);

        if ($byte === false || $byte === '') {
            break;
        }

        $command .= $byte;
    }

    if ($mode === 'silent') {
        // Nhận kết nối rồi không bao giờ trả lời: đúng cảnh một daemon quá tải.
        sleep(30);
        exit(0);
    }

    if ($mode === 'ping') {
        fwrite($connection, "PONG\n");
        fclose($connection);
        exit(0);
    }

    $received = 0;

    while (true) {
        $header = '';

        while (strlen($header) < 4) {
            $part = fread($connection, 4 - strlen($header));

            if ($part === false || $part === '') {
                break 2;
            }

            $header .= $part;
        }

        $length = unpack('N', $header)[1];

        if ($length === 0) {
            break;
        }

        $read = 0;

        while ($read < $length) {
            $part = fread($connection, min(8192, $length - $read));

            if ($part === false || $part === '') {
                break 2;
            }

            $read += strlen($part);
        }

        $received += $read;
    }

    file_put_contents($socketPath.'.received', (string) $received);

    fwrite($connection, $reply);
    fclose($connection);
    exit(0);
    PHP;
}

/**
 * Khởi động clamd giả và đợi tới lúc nó nghe được. Trả về [đường dẫn socket, handle tiến trình].
 *
 * @return array{0: string, 1: resource}
 */
function startFakeClamd(string $reply, string $mode = 'instream'): array
{
    $scriptPath = sys_get_temp_dir().'/fake-clamd-'.Str::random(8).'.php';
    file_put_contents($scriptPath, fakeClamdScript());

    // Socket Unix có giới hạn ~108 ký tự đường dẫn nên dùng tên ngắn.
    $socketPath = sys_get_temp_dir().'/cd'.Str::random(6).'.sock';

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $socketPath, $reply, $mode],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    $deadline = microtime(true) + 10;

    while (! file_exists($socketPath.'.ready') && microtime(true) < $deadline) {
        usleep(20_000);
    }

    return [$socketPath, $process];
}

function stopFakeClamd(string $socketPath, $process): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }

    foreach ([$socketPath, $socketPath.'.ready', $socketPath.'.received'] as $path) {
        @unlink($path);
    }
}

function scannableFile(int $sizeBytes = 300_000): string
{
    $path = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($path, str_repeat('A', $sizeBytes));

    return $path;
}

it('gửi TRỌN VẸN tệp tới daemon, không phải một phần đầu', function () {
    // Bản đầu không kiểm giá trị trả về của một `fwrite()` nào: một lần ghi thiếu byte là vô
    // hình, nên clamd chỉ quét phần đầu tệp rồi trả `OK` — đúng cái "im lặng cho qua" mà docblock
    // của lớp này khẳng định là không thể xảy ra.
    //
    // Nói cho sòng phẳng: test này ĐÃ XANH cả với bản đầu, vì không có cách nào ép một socket
    // Unix cục bộ ghi thiếu byte theo ý muốn — buffer gửi ở đây không bao giờ đầy. Nó là một cái
    // pin chống thoái lui (ai gỡ `writeAll()` đi và thay bằng một vòng lặp sai sẽ thấy nó đỏ),
    // không phải một bằng chứng tái hiện được lỗi cũ. Bằng chứng cho `writeAll()` là chính đoạn
    // mã: `fwrite()` được PHÉP trả về ít hơn số byte đưa vào, và một lỗi chỉ xuất hiện khi máy
    // chủ đang bận là đúng loại lỗi không bao giờ lộ ra trong test.
    [$socket, $process] = startFakeClamd("stream: OK\n");
    $file = scannableFile(300_000);

    try {
        (new ClamAvScanner($socket))->scan($file);

        expect((int) file_get_contents($socket.'.received'))->toBe(300_000);
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('nhận ra một kết luận sạch của clamd', function () {
    [$socket, $process] = startFakeClamd("stream: OK\n");
    $file = scannableFile(1024);

    try {
        (new ClamAvScanner($socket))->scan($file);

        expect(true)->toBeTrue();
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('nhận ra một kết luận nhiễm của clamd', function () {
    [$socket, $process] = startFakeClamd("stream: Eicar-Test-Signature FOUND\n");
    $file = scannableFile(1024);

    try {
        expect(fn () => (new ClamAvScanner($socket))->scan($file))
            ->toThrow(FileRejected::class, __('documents.file_guard.virus_detected'));
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('không coi là sạch một câu trả lời chỉ TÌNH CỜ chứa hai chữ OK', function () {
    // `str_contains($response, 'OK')` không neo ở đâu cả: một tên chữ ký hay một câu lỗi có hai
    // chữ đó ở giữa là đủ để một tệp chưa quét xong được ghi vào hồ sơ như đã sạch.
    [$socket, $process] = startFakeClamd("stream: Heuristics.OK.Broken ERROR\n");
    $file = scannableFile(1024);

    try {
        expect(fn () => (new ClamAvScanner($socket))->scan($file))
            ->toThrow(FileRejected::class, __('documents.file_guard.scanner_unavailable'));
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('từ chối khi clamd trả về lỗi', function () {
    [$socket, $process] = startFakeClamd("INSTREAM size limit exceeded. ERROR\n");
    $file = scannableFile(1024);

    try {
        expect(fn () => (new ClamAvScanner($socket))->scan($file))
            ->toThrow(FileRejected::class, __('documents.file_guard.scanner_unavailable'));
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('không treo worker khi daemon nhận kết nối rồi im lặng', function () {
    // Timeout 5 giây trong `stream_socket_client` chỉ phủ lúc KẾT NỐI. Không có
    // `stream_set_timeout()` thì lần `fread()` chờ câu trả lời rơi về `default_socket_timeout`
    // (60 giây) — một daemon quá tải khoá một worker suốt một phút cho mỗi tệp.
    [$socket, $process] = startFakeClamd("stream: OK\n", 'silent');
    $file = scannableFile(1024);
    $startedAt = microtime(true);

    try {
        expect(fn () => (new ClamAvScanner($socket, timeoutSeconds: 2))->scan($file))
            ->toThrow(FileRejected::class, __('documents.file_guard.scanner_unavailable'));

        expect(microtime(true) - $startedAt)->toBeLessThan(15.0);
    } finally {
        unlink($file);
        stopFakeClamd($socket, $process);
    }
});

it('isActive hỏi thật daemon chứ không trả lời "có" theo trí nhớ', function () {
    // Bản đầu `return true;` cứng, nên `php artisan about` báo quét virus ĐANG BẬT trên một máy
    // chủ không có daemon nào — đúng một câu hỏi mà method này tồn tại để trả lời.
    expect((new ClamAvScanner('/khong/co/socket/nao/o-day.ctl'))->isActive())->toBeFalse();
});

it('isActive trả true khi daemon thật sự trả lời PING', function () {
    [$socket, $process] = startFakeClamd('', 'ping');

    try {
        expect((new ClamAvScanner($socket))->isActive())->toBeTrue();
    } finally {
        stopFakeClamd($socket, $process);
    }
});

it('scan từ chối khi không kết nối được socket, thay vì coi tệp là sạch', function () {
    $file = scannableFile(1024);

    try {
        expect(fn () => (new ClamAvScanner('/khong/co/socket/nao/o-day.ctl'))->scan($file))
            ->toThrow(FileRejected::class, __('documents.file_guard.scanner_unavailable'));
    } finally {
        unlink($file);
    }
});

it('bind VirusScanner theo cấu hình, để một lần đổi mặc định không âm thầm tắt quét virus', function () {
    // Không có test này thì một sửa đổi ở `AppServiceProvider` có thể để production chạy
    // `NullScanner` với `CLAMAV_ENABLED=true` mà không gì đỏ lên.
    config(['vkcrm.clamav.enabled' => false]);
    expect(app(VirusScanner::class))->toBeInstanceOf(NullScanner::class)
        ->and(app(VirusScanner::class)->isActive())->toBeFalse();

    app()->forgetInstance(VirusScanner::class);

    config(['vkcrm.clamav.enabled' => true]);
    expect(app(VirusScanner::class))->toBeInstanceOf(ClamAvScanner::class);
});
