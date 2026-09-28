<?php

namespace App\Actions\Billing\Concerns;

use App\Enums\InstalmentTrigger;
use App\Models\Matter;
use App\Support\Billing\Money;
use App\Support\Billing\SplitByPercent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Đọc và kiểm dữ liệu vào của các Action hợp đồng — một chỗ, để `DraftContract` (soạn lịch thu)
 * và `AmendContract` (thêm đợt bằng phụ lục) không có hai phiên bản của cùng một luật về một đợt,
 * và mọi lý do ≥ 20 ký tự đếm theo cùng một cách.
 *
 * Mọi lỗi ở đây là `ValidationException` gắn đúng tên trường (kể cả `instalments.2.amount`), để
 * màn hình Filament hiện nó cạnh đúng ô nhập thay vì thành một trang 500.
 */
trait ValidatesBillingInput
{
    /**
     * Độ dài tối thiểu của lý do nội bộ: phụ lục và huỷ hợp đồng hôm nay; miễn đợt và huỷ khoản thu
     * (M9 Task 5) nên dùng lại đúng hằng và `validatedReason()` này thay vì viết lần thứ hai.
     */
    public const MIN_REASON_LENGTH = 20;

    /** `instalments.name` là `string(150)`. */
    public const MAX_INSTALMENT_NAME_LENGTH = 150;

    /** `instalments.due_days_after_trigger` là `unsignedSmallInteger`. */
    public const MAX_DUE_DAYS = 65_535;

    /** Một số tiền đồng: số nguyên, từ 1 tới {@see Money::MAX}. */
    protected function validatedAmount(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1) {
            throw ValidationException::withMessages([$field => [__('billing.validation.amount_required')]]);
        }

        if ($value > Money::MAX) {
            throw ValidationException::withMessages([
                $field => [__('billing.validation.money_too_large', ['max' => Money::format(Money::MAX)])],
            ]);
        }

        return $value;
    }

    /**
     * `null` (không có dòng thuế) hoặc số nguyên 0–100. `0` là hoá đơn thuế suất 0%, khác `null`.
     * Dùng chung bởi `DraftContract` và `UpdateDraftContract` (M9 Task 7) — một hợp đồng nháp
     * được soạn hay được sửa đều đọc thuế suất theo đúng một luật, không phải hai bản chép tay.
     */
    protected function validatedVatRate(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > 100) {
            throw ValidationException::withMessages(['vat_rate_percent' => [__('billing.validation.vat_rate_out_of_range')]]);
        }

        return $value;
    }

    /**
     * Lý do nội bộ: ≥ {@see self::MIN_REASON_LENGTH} ký tự đếm bằng `mb_strlen` — một chuỗi tiếng
     * Việt có dấu dài hơn số ký tự của nó khi đếm bằng byte, và `strlen` sẽ nhận nhầm một lý do quá
     * ngắn. Khoảng trắng hai đầu không được tính.
     */
    protected function validatedReason(?string $reason, string $field = 'reason'): string
    {
        $reason = trim($reason ?? '');

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                $field => [__('billing.validation.reason_too_short', ['min' => self::MIN_REASON_LENGTH])],
            ]);
        }

        return $reason;
    }

    /**
     * Một ngày từ ô nhập: `DateTimeInterface`, hoặc chuỗi đúng dạng `Y-m-d`. Chuỗi hỏng (hay rỗng)
     * là lỗi xác thực trên `$field`, không phải `InvalidFormatException` thành trang 500 (bài học
     * I6 của `TransitionMatterStage`), và không bao giờ âm thầm thành "hôm nay" như
     * `Carbon::parse('')`.
     */
    protected function validatedDate(DateTimeInterface|string|null $value, string $field): CarbonInterface
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        $text = trim((string) $value);

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $text);
        } catch (InvalidFormatException) {
            $parsed = null;
        }

        // So ngược lại chuỗi gốc: `createFromFormat` cuộn một ngày không có thật sang tháng sau
        // (`2026-02-30` → `2026-03-02`) thay vì từ chối nó.
        if ($parsed === null || $parsed->format('Y-m-d') !== $text) {
            throw ValidationException::withMessages([$field => [__('billing.validation.date_invalid')]]);
        }

        return $parsed;
    }

    /**
     * Như {@see self::validatedDate()}, và không được ở tương lai — so theo NGÀY ở múi giờ ứng
     * dụng, cùng cách `TransitionMatterStage` bước 3: một ngày ký là một sự việc đã xảy ra.
     */
    protected function validatedPastDate(DateTimeInterface|string|null $value, string $field): CarbonInterface
    {
        $date = $this->validatedDate($value, $field);

        if ($date->gt(today())) {
            throw ValidationException::withMessages([$field => [__('billing.validation.date_future')]]);
        }

        return $date;
    }

    /**
     * Một dòng lịch thu, đã kiểm, thành các cột của `instalments` (trừ `contract_id`, `sequence`,
     * `status`). `$prefix` là tên trường của dòng đó trên form (`instalments.0`).
     *
     * Ba loại kích hoạt (M9 quyết định 2):
     * - `on_signing` — `due_date` LUÔN rỗng lúc soạn (chưa ai biết ngày ký); `ActivateContract`
     *   điền nó.
     * - `due_date` — bắt buộc một ngày hợp lệ.
     * - `stage` — `trigger_stage_key` là giai đoạn CÓ THẬT của đúng loại vụ việc này (qua
     *   `MatterType::stage()`), và KHÔNG phải giai đoạn đầu (`firstStage()`): vụ đã ở giai đoạn đầu
     *   từ lúc mở, nên một đợt gắn vào đó không bao giờ có một lần "chạm tới" để kích hoạt.
     *
     * `percent_basis` lưu NGUYÊN phần trăm người dùng đã gõ, chỉ để hiển thị và truy vết. `amount`
     * là con số có tính quyết định và KHÔNG BAO GIỜ được tính lại từ `percent_basis` — ở đây hay ở
     * bất kỳ đâu.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function instalmentAttributes(Matter $matter, array $row, string $prefix): array
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(["{$prefix}.name" => [__('billing.validation.instalment_name_required')]]);
        }

        if (mb_strlen($name) > self::MAX_INSTALMENT_NAME_LENGTH) {
            throw ValidationException::withMessages([
                "{$prefix}.name" => [__('billing.validation.instalment_name_too_long', ['max' => self::MAX_INSTALMENT_NAME_LENGTH])],
            ]);
        }

        $trigger = $row['trigger_type'] ?? null;
        $trigger = $trigger instanceof InstalmentTrigger ? $trigger : InstalmentTrigger::tryFrom((string) $trigger);

        if ($trigger === null) {
            throw ValidationException::withMessages(["{$prefix}.trigger_type" => [__('billing.validation.trigger_type_invalid')]]);
        }

        $dueDays = $row['due_days_after_trigger'] ?? 0;

        if (! is_int($dueDays) || $dueDays < 0 || $dueDays > self::MAX_DUE_DAYS) {
            throw ValidationException::withMessages([
                "{$prefix}.due_days_after_trigger" => [__('billing.validation.due_days_invalid', ['max' => self::MAX_DUE_DAYS])],
            ]);
        }

        return [
            'name' => $name,
            'amount' => $this->validatedAmount($row['amount'] ?? null, "{$prefix}.amount"),
            'percent_basis' => $this->validatedPercentBasis($row['percent_basis'] ?? null, "{$prefix}.percent_basis"),
            'trigger_type' => $trigger,
            'trigger_stage_key' => $trigger === InstalmentTrigger::Stage
                ? $this->validatedTriggerStage($matter, $row['trigger_stage_key'] ?? null, "{$prefix}.trigger_stage_key")
                : null,
            'due_days_after_trigger' => $trigger === InstalmentTrigger::DueDate ? 0 : $dueDays,
            'due_date' => $trigger === InstalmentTrigger::DueDate
                ? $this->validatedDueDate($row['due_date'] ?? null, "{$prefix}.due_date")
                : null,
            'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
        ];
    }

    private function validatedDueDate(mixed $value, string $field): string
    {
        if (! $value instanceof DateTimeInterface && ! is_string($value)) {
            throw ValidationException::withMessages([$field => [__('billing.validation.due_date_required')]]);
        }

        return $this->validatedDate($value, $field)->toDateString();
    }

    private function validatedTriggerStage(Matter $matter, mixed $key, string $field): string
    {
        $key = is_string($key) ? trim($key) : '';
        $type = $matter->matterType;

        if ($key === '' || $type->stage($key) === null) {
            throw ValidationException::withMessages([$field => [__('billing.validation.trigger_stage_unknown', ['stage' => $key])]]);
        }

        if ($type->firstStage()?->key === $key) {
            throw ValidationException::withMessages([
                $field => [__('billing.validation.trigger_stage_is_first', ['stage' => $type->stage($key)->label])],
            ]);
        }

        return $key;
    }

    /**
     * `decimal(5,2)`, > 0 và ≤ 100, tối đa hai chữ số thập phân — đọc bằng đúng cách
     * {@see SplitByPercent::basisPoints()} đọc một phần trăm, không qua số thực. Trả về chuỗi chuẩn
     * hoá (`"33.30"`). Trần 100% là của riêng chỗ này: một phần trăm đứng một mình không có tổng nào
     * giữ nó.
     */
    private function validatedPercentBasis(mixed $value, string $field): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $points = SplitByPercent::basisPoints($value, $field);

        if ($points > 10_000) {
            throw ValidationException::withMessages([$field => [__('billing.validation.percent_out_of_range')]]);
        }

        return sprintf('%d.%02d', intdiv($points, 100), $points % 100);
    }
}
