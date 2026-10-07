<?php

namespace App\Actions\Storage;

use App\Actions\Settings\WriteSettings;
use App\Exceptions\OfficeReceiptRejected;
use App\Exceptions\RcloneCommandFailed;
use App\Models\Setting;
use App\Models\SystemHealth;
use App\Support\Backup\RcloneProcess;
use App\Support\Storage\OfficeReceipt;
use App\Support\Storage\OfficeReceiptImport;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Backup\Events\BackupHasFailed;

/**
 * CRM nhập biên nhận bản thứ hai của máy văn phòng (M14 Task 7, kế hoạch R10). Mục lịch
 * `storage.office-receipts` (07:00) và lệnh tay `vkcrm:storage:office-receipts` cùng gọi lớp này.
 *
 * Đây là nơi DUY NHẤT ghi `drive_objects.office_copied_at` (test cấu trúc `OfficeCopyStructureTest`),
 * và cột đó là điều kiện để lượt dọn vùng đệm (`PurgeStagedDocumentCopies`, làn m14 Task 3) xoá bản
 * trên máy chủ web: một dòng đánh dấu nhầm là một tệp chỉ còn bản trong Google. Vì vậy:
 *
 * 1. Chưa cấu hình (`vkcrm.storage.office.receipts_path`, hoặc Shared Drive/thư mục gốc của kho trống)
 *    → không làm gì, không tiến trình nào, không ném lỗi. Không có Shared Drive để ràng vào thì không
 *    biên nhận nào kiểm được.
 * 2. Cả lượt chạy dưới `Cache::lock('storage-office-receipts', 600)`: mục lịch và lệnh tay không chạy
 *    chồng. Không lấy được khoá → {@see OfficeReceiptImport::busy()}, không tiến trình nào.
 * 3. `rclone lsjson <receipts_path>` (hạn `vkcrm.storage.office.rclone_timeout`, 120 giây); chỉ lấy tệp
 *    tên khớp {@see self::FILE_PATTERN} (`receipt-<UTC>.json`, đúng tên `office-pull.sh` đặt) và lớn
 *    hơn cursor `settings.storage.office_receipt_cursor`, theo thứ tự tên — tên mang giờ UTC cố định
 *    độ dài nên thứ tự tên là thứ tự thời gian.
 *    Cursor không bao giờ được vượt giờ máy chủ + {@see OfficeReceipt::FUTURE_TOLERANCE_MINUTES} phút
 *    (vòng sửa 1 của Task 7): máy văn phòng ghi tệp vào `receipted.txt` ngay khi gửi được biên nhận, nên
 *    mọi biên nhận mà CRM bỏ qua vì tên <= cursor là các tệp ấy không bao giờ có biên nhận lại.
 *    - Tên mang giờ quá mốc đó, hoặc một ngày giờ không có thật → CHƯA đọc, không đếm là từ chối, cursor
 *      không đi qua; câu `office_copy.import.deferred` (cách gỡ: Phụ lục D, mục D.9) vào lỗi mỗi lượt
 *      cho tới khi tệp được xoá hay giờ thật vượt tên nó. Đồng hồ máy văn phòng chạy nhanh vì thế không
 *      chặn các biên nhận đúng giờ đến sau, tên nhỏ hơn nó.
 *    - Cursor đã lưu ở quá mốc đó (đồng hồ máy chủ web từng chạy nhanh) → đặt lại về rỗng và đọc lại
 *      mọi biên nhận trong thư mục; an toàn vì lần đánh dấu chỉ ghi dòng `office_copied_at IS NULL`.
 *      Câu `office_copy.import.cursor_reset` vào lỗi của lượt đó.
 * 4. Từng tệp: lớn hơn `office.receipt_max_bytes` (theo `lsjson`, rồi đo lại nội dung đọc về) → từ
 *    chối, không đọc; còn lại `rclone cat` rồi {@see OfficeReceipt::parse()}. Tệp bị từ chối: không
 *    dòng nào được đánh dấu, lý do vào `last_office_receipt_error` (kèm cách gỡ: đổi tên
 *    `receipted.txt` trên máy văn phòng), log `warning`, và cursor VẪN đi qua nó (một tệp hỏng không
 *    làm kẹt mọi biên nhận sau) — tên nó đã đến hạn ở điều 3, nên cursor vẫn không vượt mốc giờ.
 * 5. Từng dòng của biên nhận hợp lệ, trong MỘT transaction cùng với lần ghi cursor:
 *    `UPDATE drive_objects SET office_copied_at = now() WHERE drive_id = <kho đang cấu hình> AND
 *    object_key = <khoá đọc ngược> AND generation = <thế hệ đọc ngược> AND md5 = ? AND size = ? AND
 *    office_copied_at IS NULL`. Dòng đã rời chỉ mục sống (`object_key` NULL: thùng rác, bị thay) và dòng
 *    của Shared Drive khác không bao giờ khớp. `size` là thêm vào câu của kế hoạch: cỡ có trong biên
 *    nhận, và một tệp cùng md5 khác cỡ không phải cùng tệp. Mốc cũ không bao giờ bị ghi đè.
 *    Dòng không đánh dấu được thì được đếm: tên lạ, không khớp dòng sống nào, đã có biên nhận, hoặc
 *    khớp tên + thế hệ mà khác md5/cỡ — loại cuối là tín hiệu tệp bị đổi trên kho, nên thành một câu
 *    lỗi.
 * 6. `errors > 0` trong biên nhận → vẫn đánh dấu các dòng khớp (cryptcheck đã xác nhận chúng), và ghi
 *    câu "máy văn phòng báo N lỗi; có thể có tệp bị đổi trên kho".
 * 7. Cuối lượt: có biên nhận hợp lệ → `last_office_receipt_at = now()`; có câu lỗi (kể cả câu biên nhận
 *    đang chờ và câu đặt lại cursor của điều 3) → `last_office_receipt_error` = các câu đó, thay câu cũ;
 *    không lỗi mà có biên nhận hợp lệ → xoá lỗi cũ. Không tệp mới và không câu lỗi → không chạm dòng
 *    sức khoẻ (biên nhận cũ dần, dòng `document_office_copy` tự VÀNG sau `office.max_age_hours`).
 * 8. Lỗi `rclone` → `BackupHasFailed(…, 'rclone:office-receipts', BACKUP_NAME)`, cùng đường thư lỗi sao
 *    lưu của M8a, rồi dừng: cursor không đi qua tệp chưa đọc được, lượt sau đọc lại.
 *
 * Không I/O mạng nào trong transaction: `rclone cat` chạy trước khi mở transaction của tệp đó.
 *
 * Biên nhận là lời của máy văn phòng: một máy văn phòng bị chiếm có thể gửi biên nhận giả và làm vùng
 * đệm bị dọn sớm (kế hoạch, "Những chỗ … sẽ cắn"). Lớp này không bao giờ làm CRM xoá gì; kho vẫn còn
 * bản, và `cryptcheck` hằng tháng cùng `vkcrm:storage:verify` là lưới.
 */
final class ImportOfficeReceipts
{
    public const CURSOR_KEY = 'storage.office_receipt_cursor';

    public const LOCK_KEY = 'storage-office-receipts';

    public const LOCK_SECONDS = 600;

    public const FAILURE_SOURCE = 'rclone:office-receipts';

    public const FILE_PATTERN = '/^receipt-\d{8}T\d{6}Z\.json$/D';

    /** Trần độ dài của cột lỗi sức khoẻ mà lớp này ghi (cột `text`; thư cảnh báo chép nguyên văn). */
    private const ERROR_MAX_LENGTH = 4000;

    public function __construct(private readonly WriteSettings $settings) {}

    public function handle(): OfficeReceiptImport
    {
        $path = config('vkcrm.storage.office.receipts_path');
        $driveId = config('vkcrm.storage.google_drive.shared_drive_id');
        $rootFolderId = config('vkcrm.storage.google_drive.root_folder_id');

        if (blank($path) || blank($driveId) || blank($rootFolderId)) {
            return OfficeReceiptImport::notConfigured();
        }

        $result = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)
            ->get(fn () => $this->import(rtrim((string) $path, '/'), (string) $driveId, (string) $rootFolderId));

        return $result instanceof OfficeReceiptImport ? $result : OfficeReceiptImport::busy();
    }

    private function import(string $path, string $driveId, string $rootFolderId): OfficeReceiptImport
    {
        $timeout = (int) config('vkcrm.storage.office.rclone_timeout', 120);
        $maxBytes = (int) config('vkcrm.storage.office.receipt_max_bytes');

        try {
            $entries = RcloneProcess::listJson($path, $timeout);
        } catch (RcloneCommandFailed $exception) {
            $this->reportFailure($exception);

            return new OfficeReceiptImport(rcloneFailed: true);
        }

        $horizon = now()->addMinutes(OfficeReceipt::FUTURE_TOLERANCE_MINUTES);
        $errors = [];

        $cursor = (string) Setting::query()->where('key', self::CURSOR_KEY)->value('value');

        if ($cursor !== '' && ! self::nameIsDue($cursor, $horizon)) {
            $errors[] = __('office_copy.import.cursor_reset', ['file' => $cursor, 'minutes' => OfficeReceipt::FUTURE_TOLERANCE_MINUTES]);
            Log::warning(__('office_copy.import.log.cursor_reset'), ['cursor' => $cursor]);
            $cursor = '';
        }

        $pending = array_filter(
            $entries,
            fn (array $entry) => preg_match(self::FILE_PATTERN, $entry['name']) === 1 && strcmp($entry['name'], $cursor) > 0,
        );
        $deferred = array_values(array_filter($pending, fn (array $entry) => ! self::nameIsDue($entry['name'], $horizon)));
        $pending = array_values(array_filter($pending, fn (array $entry) => self::nameIsDue($entry['name'], $horizon)));
        usort($pending, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        usort($deferred, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        if ($deferred !== []) {
            $errors[] = __('office_copy.import.deferred', [
                'count' => count($deferred),
                'minutes' => OfficeReceipt::FUTURE_TOLERANCE_MINUTES,
                'file' => $deferred[0]['name'],
            ]);
            Log::warning(__('office_copy.import.log.deferred'), ['files' => array_column($deferred, 'name')]);
        }

        $counts = ['imported' => 0, 'rejected' => 0, 'marked' => 0, 'already' => 0, 'unmatched' => 0, 'mismatched' => 0, 'unknown' => 0];
        $rcloneFailed = false;

        foreach ($pending as $entry) {
            $name = $entry['name'];

            try {
                if ($entry['size'] > $maxBytes) {
                    throw OfficeReceiptRejected::because('too_large', ['max' => $maxBytes]);
                }

                try {
                    $content = RcloneProcess::cat($path.'/'.$name, $timeout);
                } catch (RcloneCommandFailed $exception) {
                    $this->reportFailure($exception);
                    $rcloneFailed = true;

                    break;
                }

                if (strlen($content) > $maxBytes) {
                    throw OfficeReceiptRejected::because('too_large', ['max' => $maxBytes]);
                }

                $receipt = OfficeReceipt::parse($content, $driveId, $rootFolderId, now());
            } catch (OfficeReceiptRejected $exception) {
                $counts['rejected']++;
                $errors[] = __('office_copy.import.rejected', ['file' => $name, 'reason' => $exception->getMessage()]);
                Log::warning(__('office_copy.import.log.rejected'), ['file' => $name, 'reason' => $exception->getMessage()]);
                $this->settings->handle([self::CURSOR_KEY => $name], null);

                continue;
            }

            $tally = DB::transaction(function () use ($receipt, $driveId, $name): array {
                $tally = ['marked' => 0, 'already' => 0, 'unmatched' => 0, 'mismatched' => 0, 'unknown' => 0];
                $now = now();

                foreach ($receipt->files as $line) {
                    $tally[$this->mark($line, $driveId, $now)]++;
                }

                $this->settings->handle([self::CURSOR_KEY => $name], null);

                return $tally;
            });

            $counts['imported']++;

            foreach ($tally as $outcome => $count) {
                $counts[$outcome] += $count;
            }

            if ($receipt->errors > 0) {
                $errors[] = __('office_copy.import.office_errors', ['file' => $name, 'count' => $receipt->errors]);
            }

            if ($tally['mismatched'] > 0) {
                $errors[] = __('office_copy.import.mismatched', ['file' => $name, 'count' => $tally['mismatched']]);
            }

            Log::info(__('office_copy.import.log.imported'), ['file' => $name, 'office_errors' => $receipt->errors, ...$tally]);
        }

        $this->recordHealth($counts['imported'], $errors);

        return new OfficeReceiptImport(
            rcloneFailed: $rcloneFailed,
            imported: $counts['imported'],
            rejected: $counts['rejected'],
            marked: $counts['marked'],
            alreadyMarked: $counts['already'],
            unmatched: $counts['unmatched'],
            mismatched: $counts['mismatched'],
            unknownNames: $counts['unknown'],
            deferred: count($deferred),
            errors: $errors,
        );
    }

    /**
     * Giờ UTC trong tên `receipt-<UTC>.json` không quá `$horizon` (giờ máy chủ + dung sai). Tên không
     * mang một ngày giờ có thật (`20261000T…`, `…T256100Z`: PHP tự tràn sang ngày khác, nên đọc rồi in
     * lại không ra đúng chuỗi cũ) thì KHÔNG đến hạn: giờ của nó không kiểm được, nên nó không được đọc
     * và cursor không đi qua nó. Chuỗi không đúng khuôn tên (chỉ có thể là cursor bị sửa tay) cũng vậy.
     */
    private static function nameIsDue(string $name, CarbonInterface $horizon): bool
    {
        if (preg_match('/^receipt-(\d{8}T\d{6})Z\.json$/D', $name, $match) !== 1) {
            return false;
        }

        // 14 chữ số luôn đọc được bằng khuôn này (tràn chứ không hỏng), nên không có nhánh `false`.
        /** @var DateTimeImmutable $time */
        $time = DateTimeImmutable::createFromFormat('!Ymd\THis', $match[1], new DateTimeZone('UTC'));

        return $time->format('Ymd\THis') === $match[1] && $time <= $horizon;
    }

    /**
     * @param  array{name: string, key: ?string, generation: ?int, md5: string, size: int}  $line
     * @return 'marked'|'already'|'unmatched'|'mismatched'|'unknown'
     */
    private function mark(array $line, string $driveId, CarbonInterface $now): string
    {
        if ($line['key'] === null || $line['generation'] === null) {
            return 'unknown';
        }

        $live = fn () => DB::table('drive_objects')
            ->where('drive_id', $driveId)
            ->where('object_key', $line['key'])
            ->where('generation', $line['generation']);

        $marked = $live()
            ->where('md5', $line['md5'])
            ->where('size', $line['size'])
            ->whereNull('office_copied_at')
            ->update(['office_copied_at' => $now]);

        if ($marked > 0) {
            return 'marked';
        }

        $row = $live()->first(['md5', 'size']);

        if ($row === null) {
            return 'unmatched';
        }

        return $row->md5 === $line['md5'] && (int) $row->size === $line['size'] ? 'already' : 'mismatched';
    }

    /** @param  list<string>  $errors */
    private function recordHealth(int $imported, array $errors): void
    {
        $attributes = [];

        if ($imported > 0) {
            $attributes['last_office_receipt_at'] = now();
        }

        if ($errors !== []) {
            $attributes['last_office_receipt_error'] = Str::limit(implode(' ', $errors), self::ERROR_MAX_LENGTH);
        } elseif ($imported > 0) {
            $attributes['last_office_receipt_error'] = null;
        }

        SystemHealth::current()->update($attributes);
    }

    private function reportFailure(RcloneCommandFailed $exception): void
    {
        event(new BackupHasFailed($exception, self::FAILURE_SOURCE, (string) config('backup.backup.name')));
    }
}
