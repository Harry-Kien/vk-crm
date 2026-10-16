<?php

namespace App\Support\Settings;

use App\Actions\Settings\UpdateBusinessSettings;
use App\Models\Setting;

/**
 * "Cài đặt nghiệp vụ" (làn st, chủ văn phòng 2026-10-15: "cài đặt các chức năng như các hệ thống CRM
 * xịn khác, như kiểu bật mở công tắc") — sáu thông số mà trước làn này chỉ đổi được trong `.env` hoặc
 * không đổi được chút nào, nay chỉnh được trên trang "Cài đặt nghiệp vụ"
 * (`App\Filament\Admin\Pages\Settings\BusinessSettingsPage`), lưu trong bảng `settings` (khoá
 * `business.*`), ghi qua {@see UpdateBusinessSettings}. Lớp này chỉ ĐỌC, và là NƠI DUY NHẤT trong `app/`
 * đọc ba khoá cấu hình tương ứng (`vkcrm.client_access_days`, `vkcrm.upload_max_mb`,
 * `vkcrm.intake_response_hours`) — `BusinessSettingsWiringTest` quét mã để giữ điều đó.
 *
 * # Sáu thông số và chỗ chúng được nối vào
 *
 *  - `client_access_days` — số ngày khách còn xem hồ sơ trên cổng sau khi vụ kết thúc:
 *    `App\Actions\Matter\SyncMatterArchive` (tính `client_access_until` lúc đồng bộ dòng lưu trữ).
 *  - `upload_max_mb` — dung lượng tối đa một tệp tải lên: `App\Support\Files\FileGuard`, cùng câu chữ
 *    "tối đa N MB" của ô tải tệp nội bộ (`DocumentsRelationManager::maxUploadMegabytes()`) và của cổng
 *    (`App\Filament\Portal\Pages\SubmitDocument`). Không vượt {@see self::uploadCeilingMegabytes()}.
 *  - `intake_response_hours` — số giờ làm việc để phản hồi lần đầu một người liên hệ:
 *    `App\Support\Intake\FirstResponseClock::fromConfig()` (tác vụ nhắc, job thư, widget).
 *  - `stale_matter_mail` (công tắc) — thư "hồ sơ quá 21 ngày chưa cập nhật cho khách" cho luật sư phụ
 *    trách và cấp trên: `App\Actions\Schedule\CheckStaleMatters` (không xếp job) và
 *    `App\Jobs\SendStaleMatterMail` (hỏi lại lúc gửi). Chuông 14 ngày trong hệ thống KHÔNG theo công tắc.
 *  - `missing_documents_mail` (công tắc) — thư nhắc khách nộp giấy tờ còn thiếu (thứ Hai/Tư/Sáu):
 *    `App\Actions\Schedule\RemindMissingDocuments` và `App\Jobs\SendMissingDocumentsMail` (hỏi lại lúc
 *    gửi). Chuông 14 ngày cho luật sư KHÔNG theo công tắc.
 *  - `client_requests` (công tắc) — khách mở yêu cầu MỚI trên cổng: `App\Actions\Portal\OpenClientRequest`
 *    từ chối, trang "Yêu cầu" của cổng ẩn ô gửi. Cuộc trao đổi đã có vẫn viết tiếp được ở cả hai phía.
 *
 * # Chưa lưu gì = đúng hành vi hôm nay
 *
 * Mỗi thông số rơi về đúng thứ mã đọc trước làn này khi chưa có dòng nào: ba con số đọc cấu hình
 * (`.env`) theo ĐÚNG cách nơi đọc cũ đã đọc (kể cả bước lùi 20 MB của tệp tải lên), ba công tắc là BẬT.
 * Một giá trị lưu hỏng (không phải số, ngoài giới hạn, công tắc khác {@see self::ON}/{@see self::OFF})
 * cũng rơi về mặc định đó — không bao giờ thành "tắt hẳn" hay "0 ngày" vì một dòng sửa tay; riêng dung
 * lượng tệp — lưu hay chưa lưu — không bao giờ vượt trần hiện tại của máy chủ: số lớn hơn thì dùng đúng trần
 * đó ({@see self::number()}, {@see self::effectiveDefault()}; gộp integ-4, M2 của rà soát làn st). Giá trị lưu
 * trên giao diện THẮNG `.env`; xoá nó ("Đưa về mặc định") là theo `.env` lại.
 *
 * # Đọc
 *
 * Hàm tĩnh đọc MỘT dòng mỗi lần hỏi, không nhớ qua hai lần: đổi trên giao diện có hiệu lực ngay ở request
 * (hay job) kế tiếp, cùng luật với `ClientPaymentSettings`. {@see self::current()} đọc cả sáu dòng trong
 * một truy vấn cho trang cài đặt.
 */
final class BusinessSettings
{
    public const CLIENT_ACCESS_DAYS = 'business.client_access_days';

    public const UPLOAD_MAX_MB = 'business.upload_max_mb';

    public const INTAKE_RESPONSE_HOURS = 'business.intake_response_hours';

    public const STALE_MATTER_MAIL = 'business.stale_matter_mail';

    public const MISSING_DOCUMENTS_MAIL = 'business.missing_documents_mail';

    public const CLIENT_REQUESTS = 'business.client_requests';

    /** Tên trường của form/Action → khoá trong bảng `settings`. */
    public const KEYS = [
        'client_access_days' => self::CLIENT_ACCESS_DAYS,
        'stale_matter_mail' => self::STALE_MATTER_MAIL,
        'intake_response_hours' => self::INTAKE_RESPONSE_HOURS,
        'client_requests' => self::CLIENT_REQUESTS,
        'upload_max_mb' => self::UPLOAD_MAX_MB,
        'missing_documents_mail' => self::MISSING_DOCUMENTS_MAIL,
    ];

    /** Ba công tắc — mặc định BẬT (hôm nay chúng luôn chạy). */
    public const TOGGLES = ['stale_matter_mail', 'client_requests', 'missing_documents_mail'];

    /**
     * Giới hạn của ba con số, gồm cả hai đầu. Trần của `upload_max_mb` không phải hằng số: nó là
     * {@see self::uploadCeilingMegabytes()} — `null` ở đây.
     *
     * @var array<string, array{0: int, 1: ?int}>
     */
    public const NUMBERS = [
        'client_access_days' => [1, 730],
        'intake_response_hours' => [1, 40],
        'upload_max_mb' => [1, null],
    ];

    /** Giá trị DUY NHẤT nghĩa là "bật" của một công tắc. */
    public const ON = '1';

    /** Giá trị "tắt" mà màn hình lưu (`WriteSettings` coi chuỗi rỗng là "chưa đặt"). */
    public const OFF = '0';

    /** Bước lùi khi `UPLOAD_MAX_MB` thiếu hay không phải số dương — cùng con số ba nơi đọc cũ đã dùng. */
    public const FALLBACK_UPLOAD_MAX_MB = 20;

    /** @param  array<string, ?string>  $stored  khoá đầy đủ → giá trị đã lưu */
    private function __construct(private readonly array $stored) {}

    public static function current(): self
    {
        /** @var array<string, ?string> $stored */
        $stored = Setting::query()->whereIn('key', array_values(self::KEYS))->pluck('value', 'key')->all();

        return new self($stored);
    }

    // -------------------------------------------------------------------------------------
    // Hàm tĩnh — nơi đọc nghiệp vụ gọi, mỗi lần hỏi một dòng
    // -------------------------------------------------------------------------------------

    public static function clientAccessDays(): int
    {
        return self::only('client_access_days')->number('client_access_days');
    }

    public static function uploadMaxMegabytes(): int
    {
        return self::only('upload_max_mb')->number('upload_max_mb');
    }

    public static function intakeResponseHours(): int
    {
        return self::only('intake_response_hours')->number('intake_response_hours');
    }

    public static function staleMatterMailEnabled(): bool
    {
        return self::only('stale_matter_mail')->isOn('stale_matter_mail');
    }

    public static function missingDocumentsMailEnabled(): bool
    {
        return self::only('missing_documents_mail')->isOn('missing_documents_mail');
    }

    public static function clientRequestsEnabled(): bool
    {
        return self::only('client_requests')->isOn('client_requests');
    }

    /**
     * Mặc định của một trường khi chưa lưu gì — đúng hành vi trước làn st (docblock lớp). Ba con số đọc
     * cấu hình đúng như nơi đọc cũ: `(int) config(...)` trần cho số ngày và số giờ (`config/vkcrm.php` đã
     * tự lùi `INTAKE_RESPONSE_HOURS` về 4), bước lùi 20 cho dung lượng tệp.
     */
    public static function defaultFor(string $field): int|bool
    {
        return match ($field) {
            'client_access_days' => (int) config('vkcrm.client_access_days'),
            'intake_response_hours' => (int) config('vkcrm.intake_response_hours'),
            'upload_max_mb' => self::configuredUploadMegabytes(),
            'stale_matter_mail', 'client_requests', 'missing_documents_mail' => true,
        };
    }

    /**
     * Mặc định ĐANG DÙNG khi chưa lưu gì: {@see self::defaultFor()}, riêng dung lượng tệp chặn ở
     * {@see self::uploadCeilingMegabytes()} — `UPLOAD_MAX_MB` lớn hơn giới hạn PHP thì tệp lớn hơn trần
     * không bao giờ tới được `FileGuard`, nên con số trên form, trên thẻ và trong câu "tối đa N MB" là trần
     * (gộp integ-4, M2): form không bao giờ mở với giá trị trên `maxValue` của chính nó.
     */
    public static function effectiveDefault(string $field): int|bool
    {
        $default = self::defaultFor($field);

        return $field === 'upload_max_mb' ? min((int) $default, self::uploadCeilingMegabytes()) : $default;
    }

    /**
     * Trần THẬT của một tệp tải lên trên máy chủ này, tính bằng MB (làm tròn xuống): số nhỏ nhất trong
     * ba giới hạn mà một tệp phải qua trước khi tới `FileGuard` —
     *
     *  - luật `max:` của tệp tạm Livewire (`config('livewire.temporary_file_upload.rules')`, dựng từ
     *    `UPLOAD_MAX_MB` lúc nạp cấu hình — `config/livewire.php`): tệp lớn hơn bị Livewire từ chối
     *    ngay ở ô chọn tệp, nên giao diện chỉ HẠ được trần, không nâng quá `.env`;
     *  - `upload_max_filesize` và `post_max_size` của PHP (`0` của `post_max_size` = không giới hạn).
     *
     * Không bao giờ dưới 1.
     */
    public static function uploadCeilingMegabytes(): int
    {
        $limits = array_filter([
            self::livewireRuleMegabytes(),
            self::phpLimitMegabytes(),
        ], fn (?int $limit): bool => $limit !== null);

        return max(1, $limits === [] ? self::configuredUploadMegabytes() : min($limits));
    }

    /** Giới hạn của PHP (`upload_max_filesize`, `post_max_size`), MB làm tròn xuống; `null` = không giới hạn. */
    public static function phpLimitMegabytes(): ?int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn (?int $bytes): bool => $bytes !== null);

        return $limits === [] ? null : intdiv(min($limits), 1024 * 1024);
    }

    // -------------------------------------------------------------------------------------
    // Thể hiện — trang cài đặt đọc cả sáu
    // -------------------------------------------------------------------------------------

    /** Giá trị ĐANG DÙNG của một con số: đã lưu và hợp lệ, hoặc {@see self::effectiveDefault()}. */
    public function number(string $field): int
    {
        $stored = $this->stored[self::KEYS[$field]] ?? null;

        if (! is_string($stored) || preg_match('/^\d{1,4}$/', $stored) !== 1) {
            return (int) self::effectiveDefault($field);
        }

        $value = (int) $stored;
        [$min, $max] = [self::NUMBERS[$field][0], self::maxFor($field)];

        if ($field === 'upload_max_mb' && $value > $max) {
            // Trần của máy chủ hạ xuống sau lần lưu (đổi `.env`/php.ini): giữ trần, không vượt nó.
            return $max;
        }

        return $value >= $min && $value <= $max ? $value : (int) self::effectiveDefault($field);
    }

    /** Công tắc ĐANG bật? Chưa lưu hay lưu hỏng = mặc định BẬT; chỉ đúng {@see self::OFF} là tắt. */
    public function isOn(string $field): bool
    {
        return ($this->stored[self::KEYS[$field]] ?? null) !== self::OFF;
    }

    /** Trường này đã được đặt trên giao diện chưa (có giá trị lưu, kể cả giá trị hỏng). */
    public function isStored(string $field): bool
    {
        return filled($this->stored[self::KEYS[$field]] ?? null);
    }

    /** Giới hạn trên của một con số — hằng số, hoặc trần thật của máy chủ cho dung lượng tệp. */
    public static function maxFor(string $field): int
    {
        return self::NUMBERS[$field][1] ?? self::uploadCeilingMegabytes();
    }

    // -------------------------------------------------------------------------------------

    private static function only(string $field): self
    {
        $key = self::KEYS[$field];

        return new self([$key => Setting::query()->where('key', $key)->value('value')]);
    }

    /** `UPLOAD_MAX_MB` qua `config('vkcrm.upload_max_mb')`, cùng bước lùi ba nơi đọc cũ đã viết. */
    private static function configuredUploadMegabytes(): int
    {
        $configured = config('vkcrm.upload_max_mb');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::FALLBACK_UPLOAD_MAX_MB;
    }

    private static function livewireRuleMegabytes(): ?int
    {
        foreach ((array) config('livewire.temporary_file_upload.rules', []) as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match) === 1) {
                return intdiv((int) $match[1], 1024);
            }
        }

        return null;
    }

    /** `"20M"`, `"2G"`, `"512K"`, `"1048576"` → byte; `"0"`/`"-1"`/rỗng → `null` (không giới hạn). */
    private static function iniBytes(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $match) !== 1 || (int) $match[1] <= 0) {
            return null;
        }

        return (int) $match[1] * match (strtoupper($match[2])) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
    }
}
