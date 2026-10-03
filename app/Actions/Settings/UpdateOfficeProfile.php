<?php

namespace App\Actions\Settings;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Normalizer;
use App\Support\OfficeProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lưu chín thông tin văn phòng (M7 Task 10) vào bảng `settings`, khoá `office.<trường>` — đọc lại
 * qua {@see OfficeProfile}. Màn hình gọi nó: `App\Filament\Admin\Pages\OfficeProfilePage`.
 *
 * # Quyền
 *
 * Chỉ người có `settings.manage` (chỉ admin, SPEC §5), hỏi `Gate::forUser($actor)` ngay đầu — không
 * tin trang đã gác. Thiếu quyền: `AuthorizationException`, không ghi gì.
 *
 * # Đầu vào
 *
 * Chỉ chín khoá của {@see OfficeProfile::FIELDS}; khoá khác bị bỏ qua (màu, logo, font không sửa
 * được trong app). Khoá VẮNG MẶT thì giữ nguyên giá trị đang lưu — Action dùng lại được cho một lần
 * sửa từng phần; màn hình luôn gửi đủ chín ô. Chuỗi được cắt khoảng trắng hai đầu; chuỗi rỗng =
 * xoá giá trị đã lưu = dùng lại cấu hình ({@see OfficeProfile}, mục thứ tự).
 *
 * Kiểm LẦN NỮA ở đây dù form đã kiểm (form không phải đường gọi duy nhất), và cả lô hỏng thì không
 * ghi gì:
 *  - mọi trường: chuỗi, dài tối đa {@see OfficeProfile::FIELDS} ký tự (đúng `maxLength()` của form);
 *  - `tax_code`: bỏ mọi khoảng trắng; 13 chữ số liền được viết lại thành `0123456789-001`; rồi phải
 *    đúng 10 chữ số hoặc 10 chữ số, gạch, 3 chữ số;
 *  - `hotline`: đầu số dịch vụ `1900`/`1800` (sau khi bỏ khoảng trắng, chấm, gạch, ngoặc) được
 *    nhận ra TRƯỚC khi chuẩn hoá: phải đủ 8 hoặc 10 chữ số và được lưu nguyên các chữ số đó
 *    (`1900 6557` → `19006557`) — `Normalizer::phone()` đọc nó như một số thuê bao mất số 0 và
 *    viết lại thành một số không tồn tại (`019006557`). Mọi số khác qua {@see Normalizer::phone()}
 *    (bỏ khoảng trắng, dấu chấm, `+84`/`0084`, đoán lại số 0 bị Excel ăn) — kết quả phải là `84` +
 *    phần quốc gia 9…10 chữ số không bắt đầu bằng 0 hay 1 (từ 2017–2018 không số thuê bao Việt Nam
 *    nào có phần quốc gia 8 chữ số hay bắt đầu bằng 1), rồi được LƯU theo cách viết trong nước
 *    (`0` + phần quốc gia), cùng dạng với giá trị mặc định `0832270898`. Cả hai dạng đều chỉ có
 *    chữ số: số này in nguyên văn trên chân thư cho khách đọc và nằm trong `tel:`;
 *  - `zalo`, `website`: URL `http`/`https` — giá trị đi thẳng vào `<a href>`, nên `javascript:` hay
 *    một chữ không có giao thức đều bị từ chối;
 *  - `reply_to`: địa chỉ thư hợp lệ — một Reply-To hỏng làm Symfony ném lỗi ở MỌI thư.
 *
 * Lỗi ném `ValidationException` gắn đúng tên trường (`tax_code`, `hotline`, …); màn hình đổi sang
 * đường dẫn trạng thái của form.
 *
 * # Ghi và audit
 *
 * Một transaction: {@see WriteSettings} (khoá dòng, trả tên khoá đã đổi), rồi — CHỈ khi có trường
 * đổi — `Audit::record('office_profile_updated')` với `changed_fields` = TÊN các trường (không
 * tiền tố `office.`), không giá trị cũ/mới. Không chủ thể (`subject`): bản ghi là cả văn phòng,
 * không phải một model nào. Không gửi thư nào. Transaction này là transaction ngoài cùng, nên nó
 * mang số lần thử lại khi deadlock của {@see WriteSettings::ATTEMPTS} (xem docblock lớp đó).
 *
 * Thư đã xếp hàng không bị "cập nhật" bởi Action này: chúng đọc {@see OfficeProfile} lúc render,
 * nên tự mang giá trị mới (xem docblock của service đó).
 */
class UpdateOfficeProfile
{
    public function __construct(private readonly WriteSettings $writeSettings) {}

    /**
     * @param  array<string, mixed>  $input  trường => giá trị (một phần hay đủ chín trường)
     * @return list<string> tên các trường đã đổi, theo thứ tự của {@see OfficeProfile::FIELDS}
     */
    public function handle(User $actor, array $input): array
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        // Chỉ chín khoá, theo thứ tự của FIELDS; khoá vắng mặt thì không đụng tới.
        $values = [];

        foreach (array_keys(OfficeProfile::FIELDS) as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }

        $values = $this->normalized($values);

        $this->validate($values);

        if (array_key_exists('hotline', $values)) {
            $values['hotline'] = $this->storedHotline($values['hotline']);
        }

        $settings = [];

        foreach ($values as $field => $value) {
            $settings[OfficeProfile::settingKey($field)] = $value;
        }

        return DB::transaction(function () use ($settings, $actor): array {
            $changedFields = array_map(
                fn (string $key): string => substr($key, strlen(OfficeProfile::KEY_PREFIX)),
                $this->writeSettings->handle($settings, $actor),
            );

            if ($changedFields !== []) {
                Audit::record('office_profile_updated', null, [
                    'changed_fields' => $changedFields,
                ], $actor);
            }

            return $changedFields;
        }, WriteSettings::ATTEMPTS);
    }

    /**
     * Cắt khoảng trắng; mã số thuế bỏ mọi khoảng trắng bên trong và viết lại 13 chữ số liền thành
     * dạng có gạch. Giá trị không phải chuỗi đi tiếp nguyên trạng để luật `string` từ chối nó.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalized(array $values): array
    {
        foreach ($values as $field => $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($field === 'tax_code') {
                $value = (string) preg_replace('/\s+/u', '', $value);

                if (preg_match('/^\d{13}$/', $value) === 1) {
                    $value = substr($value, 0, 10).'-'.substr($value, 10);
                }
            }

            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function validate(array $values): void
    {
        $rules = [];

        foreach (OfficeProfile::FIELDS as $field => $limit) {
            $rules[$field] = ['nullable', 'string', 'max:'.$limit];
        }

        $rules['tax_code'][] = 'regex:/^\d{10}(-\d{3})?$/';
        $rules['zalo'][] = 'url:http,https';
        $rules['website'][] = 'url:http,https';
        $rules['reply_to'][] = 'email';

        Validator::make(
            $values,
            $rules,
            ['tax_code.regex' => __('office.validation.tax_code')],
            array_combine(
                array_keys(OfficeProfile::FIELDS),
                array_map(fn (string $field): string => __("office.fields.{$field}.label"), array_keys(OfficeProfile::FIELDS)),
            ),
        )->validate();

        if (filled($values['hotline'] ?? null) && $this->storedHotline($values['hotline']) === null) {
            throw ValidationException::withMessages(['hotline' => [__('office.validation.hotline')]]);
        }
    }

    /**
     * Giá trị hotline sẽ lưu, `null` khi đầu vào trống hoặc không phải một hotline gọi được:
     *  - đầu số dịch vụ: bỏ khoảng trắng, chấm, gạch, ngoặc; bắt đầu bằng `1900`/`1800` thì phải
     *    đủ 8 hoặc 10 chữ số và được trả nguyên các chữ số — KHÔNG đi tiếp sang `Normalizer::phone()`,
     *    kể cả khi sai độ dài (để không bị đoán thành một số thuê bao mất số 0);
     *  - số thuê bao: `Normalizer::phone()` → `84` + 9…10 chữ số bắt đầu bằng 2…9 → cách viết trong
     *    nước (`0` + phần quốc gia).
     */
    private function storedHotline(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $compact = (string) preg_replace('/[\s.\-()]+/u', '', $value);

        if (preg_match('/^1[89]00/', $compact) === 1) {
            return preg_match('/^1[89]00(\d{4}|\d{6})$/', $compact) === 1 ? $compact : null;
        }

        $normalized = Normalizer::phone($value);

        if ($normalized === null || preg_match('/^84([2-9]\d{8,9})$/', $normalized, $match) !== 1) {
            return null;
        }

        return '0'.$match[1];
    }
}
