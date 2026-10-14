<?php

namespace App\Support\Billing;

use App\Actions\Settings\UpdateClientPaymentSettings;
use App\Models\Setting;

/**
 * Cấu hình "Nhắc lịch thanh toán cho khách" (làn nt Task 1; chủ văn phòng 2026-10-10, đảo quyết định
 * 2026-10-04 "không gửi gì về tiền cho khách"): MỘT công tắc toàn hệ thống, **mặc định TẮT**, cùng
 * thông tin chuyển khoản của văn phòng và số ngày báo trước hạn — lưu trong bảng `settings` (khoá
 * `client_payments.*`), ghi qua {@see UpdateClientPaymentSettings} trên trang "Nhắc thanh toán cho
 * khách" (`App\Filament\Admin\Pages\ClientPaymentSettingsPage`).
 *
 * # Công tắc
 *
 * Chỉ bật khi dòng `client_payments.reminders_enabled` mang ĐÚNG chuỗi {@see self::ON}; vắng mặt,
 * `null`, `'0'`, `'true'`, `' 1'` — mọi thứ khác — là tắt (cùng luật với `McpSwitches`: giá trị
 * hỏng phải đóng, không mở). Khi tắt, hệ thống chạy đúng như trước làn nt: cổng khách không có khối
 * "Lộ trình thanh toán", không thư nào về tiền gửi khách. Khi bật (Task 1): khối đó trên trang hồ sơ
 * của cổng, và thư xác nhận đã nhận tiền (`App\Jobs\SendPaymentReceivedMail`).
 *
 * # Đọc
 *
 * {@see self::enabled()} đọc MỘT dòng; {@see self::current()} đọc cả sáu khoá trong một truy vấn. Không
 * nhớ gì qua hai lần hỏi: tắt công tắc có hiệu lực ngay ở request (hay job) kế tiếp. Lớp này chỉ ĐỌC.
 */
final class ClientPaymentSettings
{
    public const ENABLED = 'client_payments.reminders_enabled';

    public const DAYS_BEFORE_DUE = 'client_payments.days_before_due';

    public const BANK_BIN = 'client_payments.bank_bin';

    public const ACCOUNT_NUMBER = 'client_payments.account_number';

    public const ACCOUNT_HOLDER = 'client_payments.account_holder';

    public const BANK_BRANCH = 'client_payments.bank_branch';

    /** Tên trường của form/Action → khoá trong bảng `settings`. */
    public const KEYS = [
        'enabled' => self::ENABLED,
        'days_before_due' => self::DAYS_BEFORE_DUE,
        'bank_bin' => self::BANK_BIN,
        'account_number' => self::ACCOUNT_NUMBER,
        'account_holder' => self::ACCOUNT_HOLDER,
        'bank_branch' => self::BANK_BRANCH,
    ];

    /** Giới hạn độ dài của ba ô chữ — form (`maxLength()`) và Action (`max:`) dùng chung. */
    public const TEXT_LIMITS = [
        'account_number' => VietQr::MAX_ACCOUNT_LENGTH,
        'account_holder' => 100,
        'bank_branch' => 100,
    ];

    /** Giá trị DUY NHẤT nghĩa là "bật". */
    public const ON = '1';

    /** Giá trị "tắt" mà màn hình lưu (`WriteSettings` coi chuỗi rỗng là "chưa đặt"). */
    public const OFF = '0';

    public const DEFAULT_DAYS_BEFORE_DUE = 3;

    public const MIN_DAYS_BEFORE_DUE = 1;

    public const MAX_DAYS_BEFORE_DUE = 30;

    /** @param  array<string, ?string>  $stored  khoá đầy đủ → giá trị đã lưu */
    private function __construct(private readonly array $stored) {}

    public static function enabled(): bool
    {
        return Setting::query()->where('key', self::ENABLED)->value('value') === self::ON;
    }

    public static function current(): self
    {
        /** @var array<string, ?string> $stored */
        $stored = Setting::query()->whereIn('key', array_values(self::KEYS))->pluck('value', 'key')->all();

        return new self($stored);
    }

    public function isEnabled(): bool
    {
        return ($this->stored[self::ENABLED] ?? null) === self::ON;
    }

    /** Số ngày báo trước hạn; giá trị lưu vắng mặt hay hỏng thì {@see self::DEFAULT_DAYS_BEFORE_DUE}. */
    public function daysBeforeDue(): int
    {
        $value = $this->stored[self::DAYS_BEFORE_DUE] ?? null;

        if (! is_string($value) || preg_match('/^\d{1,2}$/', $value) !== 1) {
            return self::DEFAULT_DAYS_BEFORE_DUE;
        }

        $days = (int) $value;

        return $days >= self::MIN_DAYS_BEFORE_DUE && $days <= self::MAX_DAYS_BEFORE_DUE ? $days : self::DEFAULT_DAYS_BEFORE_DUE;
    }

    /** Giá trị ĐÃ LƯU của một trường chữ (để điền form), `null` khi chưa đặt. */
    public function stored(string $field): ?string
    {
        $value = $this->stored[self::KEYS[$field]] ?? null;

        return filled($value) ? $value : null;
    }

    /**
     * Tài khoản nhận chuyển khoản, ĐỦ để in lên cổng và dựng mã QR — hoặc `null` khi thiếu một trong
     * ba thứ bắt buộc (ngân hàng thuộc {@see VietQrBanks}, số tài khoản đúng dạng, chủ tài khoản). Chi
     * nhánh tuỳ chọn. Action không cho bật công tắc khi thiếu, nên `null` lúc công tắc bật chỉ xảy ra
     * với dữ liệu sửa tay; nơi đọc khi đó bỏ phần chuyển khoản và mã QR, không in nửa vời.
     *
     * @return array{bank_bin: string, bank_name: string, account_number: string, account_holder: string, bank_branch: ?string}|null
     */
    public function transferAccount(): ?array
    {
        $bin = $this->stored('bank_bin');
        $number = $this->stored('account_number');
        $holder = $this->stored('account_holder');
        $name = VietQrBanks::name($bin);

        if ($bin === null || $name === null || $holder === null || $number === null
            || preg_match('/^[A-Z0-9]{1,'.VietQr::MAX_ACCOUNT_LENGTH.'}$/', $number) !== 1) {
            return null;
        }

        return [
            'bank_bin' => $bin,
            'bank_name' => $name,
            'account_number' => $number,
            'account_holder' => $holder,
            'bank_branch' => $this->stored('bank_branch'),
        ];
    }
}
