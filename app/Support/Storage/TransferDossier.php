<?php

namespace App\Support\Storage;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Hồ sơ chuyển dữ liệu cá nhân ra nước ngoài và đồng hồ 60 ngày của nó (kế hoạch M14, R13) — đọc từ
 * bảng `settings`. Nơi DUY NHẤT biết tên khoá và luật ngày 45/60, cho ba người đọc: dòng
 * `data_transfer_dossier` của kiểm tra sẵn sàng, kiểm tra sức khoẻ kho (thư `transfer_dossier_due`) và
 * trang "Kho tài liệu".
 *
 * Ghi: năm ô của trang qua `App\Actions\Storage\RecordDataTransferDossier`; `storage.first_transfer_at`
 * do lượt đẩy `Pushed` đầu tiên trên production ghi, một lần (R2 bước 6, làn m14 Task 3).
 *
 * # Đồng hồ
 *
 * Ngày của đồng hồ là NGÀY LỊCH theo múi giờ ứng dụng kể từ lần chuyển đầu tiên (ngày đó là ngày 0).
 * Đồng hồ chạy khi có lần chuyển đầu tiên mà chưa có ngày hồ sơ:
 *  - từ ngày {@see self::WARN_FROM_DAY}: sắp tới hạn (VÀNG, thư mỗi ngày một lần);
 *  - quá ngày {@see self::DUE_DAYS}: quá hạn (ĐỎ).
 * Có ngày hồ sơ thì đồng hồ dừng, bất kể bao nhiêu ngày đã qua. Ý kiến luật sư cho chuyển trước KHÔNG
 * dừng đồng hồ: nó cho phép bắt đầu chuyển trước khi nộp, không bỏ hạn nộp.
 *
 * Giá trị không đọc được (ai đó sửa tay bảng `settings`) được coi như chưa có: với ngày hồ sơ và ý
 * kiến, đó là chiều an toàn (cổng production vẫn chặn); với lần chuyển đầu tiên, đồng hồ không chạy —
 * nhưng chỉ lượt đẩy ghi khoá đó, theo đúng dạng ISO-8601.
 */
final class TransferDossier
{
    /** Ô của trang "Kho tài liệu" → khoá `settings`. Thứ tự là thứ tự của form. */
    public const KEYS = [
        'transfer_dossier_on' => 'storage.transfer_dossier_on',
        'transfer_dossier_reference' => 'storage.transfer_dossier_reference',
        'dpa_accepted_on' => 'storage.dpa_accepted_on',
        'transfer_before_dossier_on' => 'storage.transfer_before_dossier_on',
        'transfer_before_dossier_basis' => 'storage.transfer_before_dossier_basis',
    ];

    public const FIRST_TRANSFER_AT_KEY = 'storage.first_transfer_at';

    /** Độ dài tối đa (`mb_strlen`) của mã hồ sơ và căn cứ ý kiến luật sư — đúng `maxLength()` của form. */
    public const REFERENCE_MAX = 100;

    public const BASIS_MAX = 200;

    public const WARN_FROM_DAY = 45;

    public const DUE_DAYS = 60;

    /** @param  array<string, ?string>  $values  khoá `settings` → giá trị */
    private function __construct(private readonly array $values) {}

    public static function current(): self
    {
        return new self(Setting::query()
            ->whereIn('key', [...array_values(self::KEYS), self::FIRST_TRANSFER_AT_KEY])
            ->pluck('value', 'key')
            ->all());
    }

    /** Cổng pháp lý và đồng hồ chỉ áp ở production: ngoài đó không có dữ liệu cá nhân thật (R13). */
    public static function appliesHere(): bool
    {
        return trim((string) config('app.env')) === 'production';
    }

    /** Giá trị đã lưu của một ô trang (`transfer_dossier_on`, …), `null` khi chưa đặt. */
    public function stored(string $field): ?string
    {
        $value = $this->values[self::KEYS[$field]] ?? null;

        return filled($value) ? $value : null;
    }

    public function dossierOn(): ?CarbonImmutable
    {
        return self::date($this->stored('transfer_dossier_on'));
    }

    public function reference(): ?string
    {
        return $this->stored('transfer_dossier_reference');
    }

    public function dpaAcceptedOn(): ?CarbonImmutable
    {
        return self::date($this->stored('dpa_accepted_on'));
    }

    /** Ngày ý kiến của luật sư cho phép chuyển trước khi nộp hồ sơ. */
    public function opinionOn(): ?CarbonImmutable
    {
        return self::date($this->stored('transfer_before_dossier_on'));
    }

    public function opinionBasis(): ?string
    {
        return $this->stored('transfer_before_dossier_basis');
    }

    public function firstTransferAt(): ?CarbonImmutable
    {
        $value = $this->values[self::FIRST_TRANSFER_AT_KEY] ?? null;

        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** Có ngày hồ sơ, HOẶC có ý kiến luật sư cho chuyển trước: điều kiện mở cổng production (R13). */
    public function allowsTransfer(): bool
    {
        return $this->dossierOn() !== null || $this->opinionOn() !== null;
    }

    /** Đồng hồ 60 ngày đang chạy: đã có lần chuyển đầu tiên, chưa có ngày hồ sơ. */
    public function clockRunning(): bool
    {
        return $this->firstTransferAt() !== null && $this->dossierOn() === null;
    }

    /** Ngày thứ mấy của đồng hồ (ngày chuyển đầu tiên là ngày 0), `null` khi đồng hồ không chạy. */
    public function day(): ?int
    {
        if (! $this->clockRunning()) {
            return null;
        }

        $first = $this->firstTransferAt()->startOfDay();
        $today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();

        return (int) round($first->diffInDays($today));
    }

    /** Số ngày còn lại tới hạn nộp (âm khi đã quá hạn), `null` khi đồng hồ không chạy. */
    public function daysLeft(): ?int
    {
        $day = $this->day();

        return $day === null ? null : self::DUE_DAYS - $day;
    }

    public function isDueSoon(): bool
    {
        return ($this->day() ?? -1) >= self::WARN_FROM_DAY;
    }

    public function isOverdue(): bool
    {
        return ($this->day() ?? -1) > self::DUE_DAYS;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // `2026-02-30` được Carbon "tràn" thành 2026-03-02: so lại để từ chối ngày không có thật.
        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
