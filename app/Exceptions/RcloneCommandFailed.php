<?php

namespace App\Exceptions;

use App\Actions\Backup\PushBackupArchiveToRclone;
use App\Support\Backup\RcloneProcess;
use RuntimeException;
use Throwable;

/**
 * Một lệnh `rclone` (copy/lsjson/deletefile) thất bại (M8a Task 2, Ruling 1 của brief) — ném bởi
 * {@see RcloneProcess}. Ba nguyên nhân:
 *
 * - {@see self::fromExitCode()}: tiến trình chạy xong nhưng mã thoát khác 0 (binary thiếu quyền,
 *   remote từ chối, hết hạn mức Google Drive, …) — nội dung là `stderr` (rơi về `stdout` nếu
 *   `stderr` rỗng), CẮT GỌN bằng {@see self::MAX_DETAIL_LENGTH} ký tự;
 * - {@see self::fromThrowable()}: `Illuminate\Support\Facades\Process` tự ném trước khi có mã
 *   thoát — hết hạn (`Illuminate\Process\Exceptions\ProcessTimedOutException`) hoặc binary không
 *   tồn tại trên một số hệ điều hành (`Symfony\Component\Process\Exception\
 *   ProcessFailedException` hay tương đương của `proc_open`);
 * - {@see self::verificationFailed()}: tiến trình `copy` báo thành công nhưng `rclone lsjson` sau
 *   đó KHÔNG thấy tệp, hoặc thấy tệp với dung lượng khác — "thành công" của rclone không đủ, xem
 *   docblock của {@see PushBackupArchiveToRclone}.
 *
 * KHÔNG BAO GIỜ đưa nội dung `--config`/token vào thông điệp: lớp này chỉ đọc `stdout`/`stderr`
 * của chính tiến trình, và dự án không bao giờ truyền `-v`/`-vv` cho rclone (mặc định của rclone
 * không in nội dung `rclone.conf` hay access token ra output ở mức log thường).
 */
class RcloneCommandFailed extends RuntimeException
{
    private const MAX_DETAIL_LENGTH = 500;

    public static function fromExitCode(int $exitCode, string $errorOutput): self
    {
        $detail = trim($errorOutput);
        $detail = $detail === '' ? __('backup.errors.rclone_no_output') : mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);

        return new self(__('backup.errors.rclone_exit_code', ['code' => $exitCode, 'detail' => $detail]));
    }

    public static function fromThrowable(Throwable $exception): self
    {
        return new self(__('backup.errors.rclone_process_error', [
            'detail' => mb_substr($exception->getMessage(), 0, self::MAX_DETAIL_LENGTH),
        ]));
    }

    public static function invalidOutput(): self
    {
        return new self(__('backup.errors.rclone_invalid_output'));
    }

    public static function verificationFailed(string $filename): self
    {
        return new self(__('backup.errors.rclone_verification_mismatch', ['file' => $filename]));
    }
}
