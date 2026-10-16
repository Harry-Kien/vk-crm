<?php

namespace App\Actions\Settings;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings\BusinessSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lưu "Cài đặt nghiệp vụ" (làn st) vào bảng `settings`, đọc lại qua {@see BusinessSettings}. Màn hình
 * gọi nó: `App\Filament\Admin\Pages\Settings\BusinessSettingsPage` — cùng khuôn `UpdateOfficeProfile` /
 * `UpdateClientPaymentSettings` trên bước ghi chung {@see WriteSettings}.
 *
 * # Sáu trường, khoá vắng mặt thì giữ nguyên
 *
 *  - ba công tắc ({@see BusinessSettings::TOGGLES}): bool, lưu ĐÚNG {@see BusinessSettings::ON} /
 *    {@see BusinessSettings::OFF};
 *  - ba con số ({@see BusinessSettings::NUMBERS}): số nguyên trong giới hạn của trường, cả hai đầu —
 *    trần của `upload_max_mb` là {@see BusinessSettings::uploadCeilingMegabytes()} lúc lưu. Ngoài giới
 *    hạn thì `ValidationException` trên đúng trường, cả lô không ghi gì.
 *
 * # Chỉ ghi điều THẬT SỰ đổi
 *
 * So GIÁ TRỊ ĐANG DÙNG (mặc định của `.env` khi chưa lưu) với giá trị mới: bấm "Lưu" trên form vừa mở
 * không ghi dòng nào, nên một thông số chưa ai đụng tới vẫn đi theo `.env` (đổi `.env` sau đó vẫn có
 * tác dụng). Đã đổi thì giá trị lưu THẮNG `.env` cho tới lần {@see self::reset()}.
 *
 * # Quyền, ghi và audit
 *
 * Chỉ người có `settings.manage` (chỉ admin, SPEC §5), hỏi `Gate::forUser($actor)` ngay đầu — không tin
 * trang đã gác. Rồi một transaction: {@see WriteSettings} (câu đầu tiên là lần đọc khoá các dòng) và —
 * CHỈ khi có gì đổi — một dòng `business_settings_updated`, causer là `$actor`: `changes` = trường →
 * `{old, new}` (giá trị ĐANG DÙNG trước và sau, kiểu int/bool — không có gì nhạy cảm trong sáu thông
 * số này); {@see self::reset()} thêm `reset = true` và `cleared` (các trường vừa xoá dòng lưu), và ghi
 * dòng đó MỖI KHI nó xoá ít nhất một dòng đã lưu, kể cả khi không giá trị đang dùng nào đổi (gộp integ-4,
 * M5: hộp xác nhận hứa "Lần đổi này được ghi vào nhật ký"). Không xoá gì thì không ghi gì. Không gửi thư nào.
 */
final class UpdateBusinessSettings
{
    public function __construct(private readonly WriteSettings $writeSettings) {}

    /**
     * @param  array<string, mixed>  $input  trường => giá trị (một phần hay đủ sáu trường)
     * @return list<string> tên các trường đã đổi
     */
    public function handle(User $actor, array $input): array
    {
        $this->authorize($actor);

        $input = $this->normalized(array_intersect_key($input, BusinessSettings::KEYS));

        $this->validate($input);

        $current = BusinessSettings::current();
        $changes = [];
        $values = [];

        // Theo thứ tự của `KEYS` (không theo thứ tự gửi lên), để nhật ký luôn đọc cùng một thứ tự.
        foreach (array_keys(array_intersect_key(BusinessSettings::KEYS, $input)) as $field) {
            $value = $input[$field];
            $old = $this->effective($current, $field);

            if ($value === $old) {
                continue;
            }

            $changes[$field] = ['old' => $old, 'new' => $value];
            $values[BusinessSettings::KEYS[$field]] = is_bool($value)
                ? ($value ? BusinessSettings::ON : BusinessSettings::OFF)
                : (string) $value;
        }

        return $this->write($actor, $values, $changes, reset: false);
    }

    /**
     * "Đưa tất cả về mặc định": xoá mọi giá trị đã lưu (ghi `null`), từ đó sáu thông số theo `.env` lại.
     * Nhật ký nêu ở `changes` những trường mà giá trị ĐANG DÙNG đổi theo, và ở `cleared` MỌI trường vừa xoá
     * dòng lưu — trường đã lưu đúng bằng mặc định chỉ có ở `cleared` (không có gì đổi với người dùng, nhưng
     * dòng lưu đã mất: nhật ký vẫn ghi, như hộp xác nhận hứa).
     *
     * @return list<string> tên các trường mà giá trị đang dùng đã đổi
     */
    public function reset(User $actor): array
    {
        $this->authorize($actor);

        $current = BusinessSettings::current();
        $changes = [];
        $values = [];
        $cleared = [];

        foreach (BusinessSettings::KEYS as $field => $key) {
            if (! $current->isStored($field)) {
                continue;
            }

            $values[$key] = null;
            $cleared[] = $field;
            $old = $this->effective($current, $field);
            $new = BusinessSettings::effectiveDefault($field);

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $this->write($actor, $values, $changes, reset: true, cleared: $cleared);
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, ?string>  $values  khoá đầy đủ → giá trị lưu
     * @param  array<string, array{old: int|bool, new: int|bool}>  $changes
     * @param  list<string>  $cleared  chỉ cho {@see self::reset()}: các trường vừa xoá dòng lưu
     * @return list<string>
     */
    private function write(User $actor, array $values, array $changes, bool $reset, array $cleared = []): array
    {
        if ($values === []) {
            return [];
        }

        DB::transaction(function () use ($actor, $values, $changes, $reset, $cleared): void {
            $this->writeSettings->handle($values, $actor);

            // Lưu thường: chỉ ghi khi có gì đổi. Đưa về mặc định: `$values` khác rỗng nghĩa là đã xoá ít nhất
            // một dòng lưu — luôn ghi (M5).
            if ($changes === [] && ! $reset) {
                return;
            }

            $properties = ['changes' => $changes];

            if ($reset) {
                $properties['reset'] = true;
                $properties['cleared'] = $cleared;
            }

            Audit::record('business_settings_updated', null, $properties, $actor);
        }, WriteSettings::ATTEMPTS);

        return array_keys($changes);
    }

    private function effective(BusinessSettings $settings, string $field): int|bool
    {
        return in_array($field, BusinessSettings::TOGGLES, true) ? $settings->isOn($field) : $settings->number($field);
    }

    /**
     * Công tắc về bool (`'1'`, `'true'`, `'on'`…); số nguyên dạng chuỗi về int. Giá trị không đổi được đi
     * tiếp nguyên trạng để luật kiểm tra từ chối nó.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalized(array $input): array
    {
        foreach ($input as $field => $value) {
            if (in_array($field, BusinessSettings::TOGGLES, true)) {
                $input[$field] = is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value);
            } elseif (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
                $input[$field] = (int) trim($value);
            }
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
        $rules = [];

        foreach (array_keys(BusinessSettings::KEYS) as $field) {
            $rules[$field] = in_array($field, BusinessSettings::TOGGLES, true)
                ? ['sometimes', 'boolean']
                : ['sometimes', 'required', 'integer', 'between:'.BusinessSettings::NUMBERS[$field][0].','.BusinessSettings::maxFor($field)];
        }

        Validator::make(
            $input,
            $rules,
            [],
            array_combine(
                array_keys(BusinessSettings::KEYS),
                array_map(fn (string $field): string => __("settings.business.fields.{$field}.label"), array_keys(BusinessSettings::KEYS)),
            ),
        )->validate();
    }
}
