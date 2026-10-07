<?php

namespace App\Support\Storage;

use App\Actions\Storage\ImportOfficeReceipts;
use App\Exceptions\OfficeReceiptRejected;
use App\Support\Storage\GoogleDrive\DriveObjectName;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JsonException;
use Throwable;

/**
 * Một biên nhận bản thứ hai mà `tools/backup/office-pull.sh` gửi từ máy văn phòng (M14 Task 7, kế
 * hoạch R10): đọc và kiểm khuôn. Không ghi gì; {@see ImportOfficeReceipts} quyết định đánh dấu dòng
 * nào.
 *
 * Khuôn bản 1:
 *
 * ```json
 * {
 *   "format": 1,
 *   "kho": {"team_drive": "<mã Shared Drive>", "root_folder_id": "<mã thư mục gốc>"},
 *   "started_at": "2026-10-07T18:00:03Z",
 *   "finished_at": "2026-10-07T18:41:10Z",
 *   "errors": 0,
 *   "files": [{"name": "1834~01k6xq0f9m2y7c4w8r3t5v6n1b.pdf", "md5": "<32 hex thường>", "size": 1024}]
 * }
 * ```
 *
 * {@see self::parse()} từ chối CẢ tệp ({@see OfficeReceiptRejected}) khi:
 * - không phải một object JSON, hoặc `format` không đúng số nguyên 1;
 * - `kho.team_drive` hay `kho.root_folder_id` khác Shared Drive và thư mục gốc đang cấu hình — script
 *   đọc hai giá trị đó từ cấu hình rclone của remote `vkkho`, không gõ tay, nên khác nghĩa là máy văn
 *   phòng đang sao một kho khác, và các md5 của nó không chứng minh gì cho kho này;
 * - `started_at` không phải thời điểm ISO-8601 CÓ múi giờ, hoặc ở tương lai quá
 *   {@see self::FUTURE_TOLERANCE_MINUTES} phút (lệch đồng hồ nhỏ giữa hai máy được bỏ qua);
 * - `errors` không phải số nguyên không âm; `files` không phải một mảng tuần tự;
 * - một dòng không phải object, hoặc `name` không phải chuỗi khác rỗng, `md5` không khớp
 *   `^[0-9a-f]{32}$` (đúng dạng `md5Checksum` của Google và của `rclone lsf --hash md5`), `size` không
 *   phải số nguyên không âm. Đó là đầu ra hỏng của chính script, không phải dữ liệu của kho.
 *
 * Tên KHÔNG đọc ngược được bằng {@see DriveObjectName::parse()} (tệp thăm dò `preflight~…` của kiểm
 * tra sẵn sàng, tên do người đặt tay trên Drive) thì KHÔNG làm hỏng biên nhận: dòng đó mang
 * `key = null`, `generation = null` và chỉ được đếm. Tên đến từ Drive, không từ script.
 *
 * `finished_at` không được đọc: nó chỉ để người đọc nhật ký; lượt nhập không dựa vào nó.
 */
final class OfficeReceipt
{
    public const FORMAT = 1;

    public const FUTURE_TOLERANCE_MINUTES = 5;

    private const MD5_PATTERN = '/^[0-9a-f]{32}$/D';

    private const ISO_8601_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D';

    /**
     * @param  list<array{name: string, key: ?string, generation: ?int, md5: string, size: int}>  $files
     */
    private function __construct(
        public readonly CarbonImmutable $startedAt,
        public readonly int $errors,
        public readonly array $files,
    ) {}

    /** @throws OfficeReceiptRejected */
    public static function parse(string $json, string $teamDrive, string $rootFolderId, CarbonInterface $now): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw OfficeReceiptRejected::because('not_json');
        }

        // Một mảng JSON (danh sách) qua được đây và vấp ngay luật `format` bên dưới.
        if (! is_array($data)) {
            throw OfficeReceiptRejected::because('not_json');
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            throw OfficeReceiptRejected::because('format');
        }

        $kho = is_array($data['kho'] ?? null) ? $data['kho'] : [];

        if (($kho['team_drive'] ?? null) !== $teamDrive) {
            throw OfficeReceiptRejected::because('team_drive');
        }

        if (($kho['root_folder_id'] ?? null) !== $rootFolderId) {
            throw OfficeReceiptRejected::because('root_folder');
        }

        $startedAt = self::time($data['started_at'] ?? null);

        if ($startedAt->greaterThan(CarbonImmutable::instance($now)->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            throw OfficeReceiptRejected::because('started_in_future', ['minutes' => self::FUTURE_TOLERANCE_MINUTES]);
        }

        $errors = $data['errors'] ?? null;

        if (! is_int($errors) || $errors < 0) {
            throw OfficeReceiptRejected::because('errors');
        }

        $files = $data['files'] ?? null;

        if (! is_array($files) || ! array_is_list($files)) {
            throw OfficeReceiptRejected::because('files');
        }

        return new self($startedAt, $errors, array_map(self::line(...), $files, array_keys($files)));
    }

    /** @return array{name: string, key: ?string, generation: ?int, md5: string, size: int} */
    private static function line(mixed $line, int $index): array
    {
        $name = is_array($line) ? ($line['name'] ?? null) : null;
        $md5 = is_array($line) ? ($line['md5'] ?? null) : null;
        $size = is_array($line) ? ($line['size'] ?? null) : null;

        if (! is_string($name) || $name === ''
            || ! is_string($md5) || preg_match(self::MD5_PATTERN, $md5) !== 1
            || ! is_int($size) || $size < 0) {
            throw OfficeReceiptRejected::because('line', ['line' => $index + 1]);
        }

        $parsed = DriveObjectName::parse($name);

        return [
            'name' => $name,
            'key' => $parsed['key'] ?? null,
            'generation' => $parsed['generation'] ?? null,
            'md5' => $md5,
            'size' => $size,
        ];
    }

    private static function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::ISO_8601_PATTERN, $value) !== 1) {
            throw OfficeReceiptRejected::because('started_at');
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw OfficeReceiptRejected::because('started_at');
        }
    }
}
