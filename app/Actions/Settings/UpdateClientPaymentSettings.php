<?php

namespace App\Actions\Settings;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing\ClientPaymentSettings;
use App\Support\Billing\VietQrBanks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lưu cấu hình "Nhắc lịch thanh toán cho khách" (làn nt Task 1) vào bảng `settings`, đọc lại qua
 * {@see ClientPaymentSettings}. Màn hình gọi nó: `App\Filament\Admin\Pages\ClientPaymentSettingsPage`
 * — cùng khuôn `UpdateOfficeProfile`/`UpdateAiSettings` trên bước ghi chung {@see WriteSettings}.
 *
 * # Sáu trường, khoá vắng mặt thì giữ nguyên
 *
 *  - `enabled` (bool) → công tắc, lưu ĐÚNG {@see ClientPaymentSettings::ON}/{@see ClientPaymentSettings::OFF};
 *  - `days_before_due` (số nguyên {@see ClientPaymentSettings::MIN_DAYS_BEFORE_DUE}…{@see ClientPaymentSettings::MAX_DAYS_BEFORE_DUE});
 *  - `bank_bin`: một mã của {@see VietQrBanks::NAMES};
 *  - `account_number`: bỏ khoảng trắng, dấu chấm, gạch ngang, viết hoa; rồi phải là 6…19 chữ/số không
 *    dấu (giới hạn của VietQR);
 *  - `account_holder`, `bank_branch`: cắt khoảng trắng hai đầu, tối đa
 *    {@see ClientPaymentSettings::TEXT_LIMITS} ký tự (đúng `maxLength()` của form). Chi nhánh tuỳ chọn.
 *
 * Chuỗi rỗng = xoá giá trị đã lưu.
 *
 * **Bật thì phải đủ thông tin chuyển khoản.** Khi công tắc SAU lần lưu này là bật (gửi lên `true`, hoặc
 * không gửi mà đang bật), ngân hàng, số tài khoản, chủ tài khoản SAU lần lưu này (giá trị gửi lên, hoặc
 * giá trị đang lưu khi không gửi) phải đủ — thiếu thì `ValidationException` trên đúng ô thiếu, cả lô
 * không ghi gì. Lý do: khi bật, cổng khách in thông tin chuyển khoản và mã QR; một công tắc bật trên
 * một tài khoản trống là một khối thanh toán không trả tiền vào đâu được.
 *
 * # Không bật trên tài khoản của dữ liệu mẫu
 *
 * Cùng lúc ấy (công tắc SAU lần lưu này là bật), số tài khoản SAU lần lưu không được là
 * {@see ClientPaymentSettings::DEMO_ACCOUNT_NUMBER} và chủ tài khoản không được là
 * {@see ClientPaymentSettings::DEMO_ACCOUNT_HOLDER} (không phân biệt hoa thường) — vi phạm thì
 * `ValidationException` trên đúng ô đó, câu `client_payments.settings.validation.demo_account`, cả lô
 * không ghi gì. Lý do (vòng sửa 1 của rà soát cuối làn nt): `DemoDataSeeder` chạy được trên máy chủ
 * thật bằng `--class`; tài khoản giả đúng dạng nên qua luật "đủ thông tin", và bật công tắc trên nó là
 * khách thật nhận mã QR, số tài khoản không phải của văn phòng. **Ngoại lệ duy nhất: `APP_ENV=local`**
 * (máy dev) — bản xem thử ở đó phải bật được ngay trên dữ liệu mẫu để thấy thư trong Mailpit (brief
 * Task 2 §6), và máy dev không có khách thật.
 *
 * # Chỉ ghi điều THẬT SỰ đổi
 *
 * So GIÁ TRỊ ĐANG DÙNG (không phải dòng thô) với giá trị mới: công tắc chưa lưu = tắt, số ngày chưa
 * lưu = {@see ClientPaymentSettings::DEFAULT_DAYS_BEFORE_DUE}. Nhờ thế bấm "Lưu" trên form vừa mở (tắt,
 * 3 ngày) không ghi dòng nào và không ghi nhật ký.
 *
 * # Quyền, ghi và audit
 *
 * Chỉ người có `settings.manage` (chỉ admin, SPEC §5), hỏi `Gate::forUser($actor)` ngay đầu — không tin
 * trang đã gác. Rồi một transaction: {@see WriteSettings} (câu đầu tiên là lần đọc khoá các dòng) và —
 * CHỈ khi có gì đổi — một dòng `client_payment_settings_updated`, causer là `$actor` (ai) và thời điểm
 * của dòng (lúc nào): `changed_fields` = TÊN các trường đổi; `enabled` = giá trị MỚI của công tắc, chỉ
 * khi công tắc đổi. Không chép số tài khoản hay tên chủ tài khoản vào nhật ký. Không gửi thư nào.
 */
final class UpdateClientPaymentSettings
{
    /** Số tài khoản tối thiểu 6 ký tự: ngắn hơn không phải một tài khoản ngân hàng trong nước. */
    public const MIN_ACCOUNT_LENGTH = 6;

    public function __construct(private readonly WriteSettings $writeSettings) {}

    /**
     * @param  array<string, mixed>  $input  trường => giá trị (một phần hay đủ sáu trường)
     * @return list<string> tên các trường đã đổi
     */
    public function handle(User $actor, array $input): array
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        $input = $this->normalized(array_intersect_key($input, ClientPaymentSettings::KEYS));

        $this->validate($input);

        $current = ClientPaymentSettings::current();

        $wanted = [];

        foreach ($input as $field => $value) {
            $wanted[$field] = match ($field) {
                'enabled' => $value ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF,
                'days_before_due' => (string) (int) $value,
                default => $value,
            };
        }

        $effective = [
            'enabled' => $current->isEnabled() ? ClientPaymentSettings::ON : ClientPaymentSettings::OFF,
            'days_before_due' => (string) $current->daysBeforeDue(),
            'bank_bin' => $current->stored('bank_bin'),
            'account_number' => $current->stored('account_number'),
            'account_holder' => $current->stored('account_holder'),
            'bank_branch' => $current->stored('bank_branch'),
        ];

        $this->requireBankDetailsWhenEnabled([...$effective, ...$wanted]);

        $changes = array_filter(
            $wanted,
            fn (?string $value, string $field): bool => $value !== $effective[$field],
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            return [];
        }

        $values = [];

        foreach ($changes as $field => $value) {
            $values[ClientPaymentSettings::KEYS[$field]] = $value;
        }

        return DB::transaction(function () use ($values, $actor): array {
            $changedKeys = $this->writeSettings->handle($values, $actor);

            $changed = array_values(array_filter(
                array_keys(ClientPaymentSettings::KEYS),
                fn (string $field): bool => in_array(ClientPaymentSettings::KEYS[$field], $changedKeys, true),
            ));

            if ($changed !== []) {
                $properties = ['changed_fields' => $changed];

                if (in_array('enabled', $changed, true)) {
                    $properties['enabled'] = $values[ClientPaymentSettings::ENABLED] === ClientPaymentSettings::ON;
                }

                Audit::record('client_payment_settings_updated', null, $properties, $actor);
            }

            return $changed;
        }, WriteSettings::ATTEMPTS);
    }

    /**
     * Cắt khoảng trắng, chuỗi rỗng thành `null`; số tài khoản bỏ khoảng trắng, chấm, gạch và viết hoa.
     * Giá trị không phải chuỗi đi tiếp nguyên trạng để luật kiểm tra từ chối nó.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalized(array $input): array
    {
        foreach (['bank_bin', 'account_number', 'account_holder', 'bank_branch'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($field === 'account_number') {
                $value = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $value));
            }

            $input[$field] = $value === '' ? null : $value;
        }

        if (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $input['enabled'] = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $input['enabled'];
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function validate(array $input): void
    {
        Validator::make($input, [
            'enabled' => ['sometimes', 'boolean'],
            'days_before_due' => ['sometimes', 'required', 'integer', 'between:'.ClientPaymentSettings::MIN_DAYS_BEFORE_DUE.','.ClientPaymentSettings::MAX_DAYS_BEFORE_DUE],
            'bank_bin' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_map('strval', array_keys(VietQrBanks::NAMES)))],
            'account_number' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{'.self::MIN_ACCOUNT_LENGTH.','.ClientPaymentSettings::TEXT_LIMITS['account_number'].'}$/'],
            'account_holder' => ['sometimes', 'nullable', 'string', 'max:'.ClientPaymentSettings::TEXT_LIMITS['account_holder']],
            'bank_branch' => ['sometimes', 'nullable', 'string', 'max:'.ClientPaymentSettings::TEXT_LIMITS['bank_branch']],
        ], [
            'bank_bin.in' => __('client_payments.settings.validation.bank_bin'),
            'account_number.regex' => __('client_payments.settings.validation.account_number', [
                'min' => self::MIN_ACCOUNT_LENGTH,
                'max' => ClientPaymentSettings::TEXT_LIMITS['account_number'],
            ]),
        ], array_combine(
            array_keys(ClientPaymentSettings::KEYS),
            array_map(fn (string $field): string => __("client_payments.settings.fields.{$field}.label"), array_keys(ClientPaymentSettings::KEYS)),
        ))->validate();
    }

    /**
     * Hai luật của docblock lớp khi công tắc sau lần lưu là bật: đủ ba ô chuyển khoản, rồi (trừ
     * `APP_ENV=local`) không phải tài khoản của dữ liệu mẫu.
     *
     * @param  array<string, ?string>  $after  giá trị sau lần lưu này (đang dùng, đè bởi giá trị gửi lên)
     *
     * @throws ValidationException
     */
    private function requireBankDetailsWhenEnabled(array $after): void
    {
        if ($after['enabled'] !== ClientPaymentSettings::ON) {
            return;
        }

        $missing = array_values(array_filter(
            ['bank_bin', 'account_number', 'account_holder'],
            fn (string $field): bool => blank($after[$field]),
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages(array_fill_keys($missing, [__('client_payments.settings.validation.required_when_enabled')]));
        }

        if (app()->environment('local')) {
            return;
        }

        // `account_number` đã chuẩn hoá (viết hoa, bỏ khoảng trắng/chấm/gạch); chủ tài khoản chỉ cắt hai đầu.
        $demo = array_keys(array_filter([
            'account_number' => $after['account_number'] === ClientPaymentSettings::DEMO_ACCOUNT_NUMBER,
            'account_holder' => mb_strtoupper((string) $after['account_holder']) === ClientPaymentSettings::DEMO_ACCOUNT_HOLDER,
        ]));

        if ($demo !== []) {
            throw ValidationException::withMessages(array_fill_keys($demo, [__('client_payments.settings.validation.demo_account')]));
        }
    }
}
