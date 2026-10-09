<?php

namespace App\Support\Storage\GoogleDrive;

use App\Exceptions\DocumentStorageMisconfigured;
use App\Exceptions\DocumentStorageUnavailable;
use App\Exceptions\StoredFileMissing;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Một lệnh gọi Google Drive thất bại (kế hoạch M14, R9): mang ĐÚNG những gì được phép vào log —
 * thao tác, phương thức, đường endpoint MẪU (`drive/v3/files/{fileId}`, không mã tệp), mã trạng thái,
 * `reason` của Google, lần thử. Không header, không token, không thân phản hồi, không URL (URL của một
 * phiên tải lên là một quyền ghi; URL tải chứa mã tệp, thứ không bao giờ rời máy chủ, R3).
 *
 * Phân loại (R9):
 *  - TẠM THỜI ({@see self::isTransient()}): lỗi kết nối (mã 0), 429, mọi 5xx, 403 với lý do
 *    `rateLimitExceeded`/`userRateLimitExceeded`. Được thử lại; hết lượt thì {@see DriveClient} ném
 *    {@see DocumentStorageUnavailable} bọc lỗi này;
 *  - 404 trên một TỆP ({@see self::isNotFound()}): {@see DriveClient} ném chính lỗi này; nơi biết khoá
 *    ({@see DriveAdapter}) đổi thành {@see StoredFileMissing};
 *  - mọi lỗi khác: không thử lại, {@see DriveClient} ném {@see DocumentStorageMisconfigured}.
 */
final class DriveApiError extends RuntimeException
{
    /** `reason` của lỗi kết nối (không có phản hồi HTTP). */
    public const CONNECTION = 'connection';

    /** `reason` của một tệp vừa tải lên hay vừa chép mà md5/kích thước do Google tính lệch bản gốc. */
    public const CHECKSUM_MISMATCH = 'md5Mismatch';

    /** Lý do 403 của Google mà R9 xếp vào lỗi tạm thời. */
    public const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded'];

    public function __construct(
        public readonly string $operation,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly int $status,
        public readonly ?string $reason,
        public readonly int $attempt,
    ) {
        parent::__construct(__('storage.drive.api_error', [
            'operation' => $operation,
            'status' => $status,
            'reason' => $reason ?? '—',
            'attempt' => $attempt,
        ]));
    }

    /**
     * Từ một phản hồi lỗi. `reason` đọc từ khuôn lỗi JSON của Drive v3 (`error.errors[0].reason`);
     * vắng thì `null`. Thân phản hồi không đi đâu khác.
     */
    public static function fromResponse(string $operation, string $method, string $endpoint, Response $response, int $attempt): self
    {
        $reason = $response->json('error.errors.0.reason');

        return new self($operation, $method, $endpoint, $response->status(), is_string($reason) ? $reason : null, $attempt);
    }

    public static function connection(string $operation, string $method, string $endpoint, int $attempt): self
    {
        return new self($operation, $method, $endpoint, 0, self::CONNECTION, $attempt);
    }

    public static function checksumMismatch(string $operation, string $method, string $endpoint): self
    {
        $error = new self($operation, $method, $endpoint, 0, self::CHECKSUM_MISMATCH, 1);
        $error->message = __('storage.drive.checksum_mismatch', ['operation' => $operation]);

        return $error;
    }

    public function isTransient(): bool
    {
        return $this->status === 0 && $this->reason === self::CONNECTION
            || $this->status === 429
            || $this->status >= 500
            || ($this->status === 403 && in_array($this->reason, self::RATE_LIMIT_REASONS, true));
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** Một dòng log `warning` đúng các trường được phép (xem docblock lớp). */
    public function log(): void
    {
        Log::warning(__('storage.drive.log.request_failed'), $this->context());
    }

    /** @return array{operation: string, method: string, endpoint: string, status: int, reason: ?string, attempt: int} */
    public function context(): array
    {
        return [
            'operation' => $this->operation,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'status' => $this->status,
            'reason' => $this->reason,
            'attempt' => $this->attempt,
        ];
    }
}
