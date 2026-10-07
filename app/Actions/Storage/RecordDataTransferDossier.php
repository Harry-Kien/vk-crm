<?php

namespace App\Actions\Storage;

use App\Actions\Settings\WriteSettings;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Storage\TransferDossier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ghi các mốc của hồ sơ chuyển dữ liệu cá nhân ra nước ngoài (kế hoạch M14, R13) vào bảng `settings`,
 * khoá {@see TransferDossier::KEYS}. Màn hình gọi nó: `App\Filament\Admin\Pages\DocumentStorePage`.
 * Đọc lại: {@see TransferDossier}.
 *
 * Năm ô: ngày lập/nộp hồ sơ, số hoặc mã hồ sơ (≤ {@see TransferDossier::REFERENCE_MAX} ký tự), ngày chấp
 * nhận DPA, ngày ý kiến luật sư cho chuyển trước khi nộp hồ sơ, căn cứ của ý kiến đó (≤
 * {@see TransferDossier::BASIS_MAX} ký tự). Có ngày hồ sơ HOẶC ngày ý kiến thì cổng production của kho
 * mở (`vkcrm:storage:enable`, dòng `data_transfer_dossier`); có ngày hồ sơ thì đồng hồ 60 ngày dừng.
 *
 * # Quyền
 *
 * `settings.manage` (chỉ admin, R14: không quyền mới), hỏi `Gate::forUser($actor)` ngay đầu — không tin
 * trang đã gác. Thiếu quyền: {@see AuthorizationException}, không ghi gì.
 *
 * # Đầu vào
 *
 * Chỉ năm khoá của {@see TransferDossier::KEYS}; khoá khác bị bỏ qua; khoá VẮNG MẶT giữ nguyên giá trị
 * đã lưu. Chuỗi được cắt khoảng trắng hai đầu; chuỗi rỗng = xoá giá trị đã lưu. Kiểm ở đây dù form đã
 * kiểm (form không phải đường gọi duy nhất), cả lô hỏng thì không ghi gì:
 *  - ba ngày: đúng dạng `Y-m-d`, là ngày có thật và KHÔNG sau hôm nay (`before_or_equal:today`, "hôm
 *    nay" theo múi giờ của ứng dụng). Các ô ghi việc ĐÃ xảy ra: một ngày hồ sơ hay ngày ý kiến "dự
 *    kiến" sẽ mở cổng production ngay và dừng đồng hồ 60 ngày khi hồ sơ chưa tồn tại;
 *  - mã hồ sơ, căn cứ: chuỗi, tối đa 100 và 200 ký tự (`mb_strlen`, đúng `maxLength()` của form; cột
 *    `settings.value` là `text`);
 *  - có ngày ý kiến luật sư thì phải có căn cứ: một "ý kiến" không nói văn bản nào là một ý kiến không
 *    ai kiểm lại được, mà nó mở cổng chuyển dữ liệu.
 *
 * # Ghi và audit
 *
 * Một transaction: {@see WriteSettings} (khoá dòng, trả khoá đã đổi), rồi — CHỈ khi có ô đổi —
 * `Audit::record('data_transfer_dossier_recorded')` với `changed_fields` = TÊN các ô, không giá trị.
 * Không gửi thư nào.
 */
final class RecordDataTransferDossier
{
    private const DATE_FIELDS = ['transfer_dossier_on', 'dpa_accepted_on', 'transfer_before_dossier_on'];

    public function __construct(private readonly WriteSettings $writeSettings) {}

    /**
     * @param  array<string, mixed>  $input  ô => giá trị (một phần hay đủ năm ô)
     * @return list<string> tên các ô đã đổi, theo thứ tự của {@see TransferDossier::KEYS}
     */
    public function handle(User $actor, array $input): array
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        $values = [];

        foreach (array_keys(TransferDossier::KEYS) as $field) {
            if (array_key_exists($field, $input)) {
                $value = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                $values[$field] = $value === '' ? null : $value;
            }
        }

        $this->validate($values);

        $settings = [];

        foreach ($values as $field => $value) {
            $settings[TransferDossier::KEYS[$field]] = $value;
        }

        return DB::transaction(function () use ($settings, $actor): array {
            $changedKeys = $this->writeSettings->handle($settings, $actor);
            $changedFields = array_values(array_map(
                fn (string $key): string => array_search($key, TransferDossier::KEYS, true),
                $changedKeys,
            ));

            if ($changedFields !== []) {
                Audit::record('data_transfer_dossier_recorded', null, ['changed_fields' => $changedFields], $actor);
            }

            return $changedFields;
        }, WriteSettings::ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function validate(array $values): void
    {
        $rules = [
            'transfer_dossier_reference' => ['nullable', 'string', 'max:'.TransferDossier::REFERENCE_MAX],
            'transfer_before_dossier_basis' => ['nullable', 'string', 'max:'.TransferDossier::BASIS_MAX],
        ];

        $messages = [];

        foreach (self::DATE_FIELDS as $field) {
            $rules[$field] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
            $messages["{$field}.before_or_equal"] = __('document_store.page.validation.not_in_future');
        }

        // Chỉ đòi căn cứ khi lần ghi này CÓ đụng tới ngày ý kiến (khoá vắng mặt = giữ nguyên, không xét).
        if (filled($values['transfer_before_dossier_on'] ?? null)) {
            $rules['transfer_before_dossier_basis'][] = 'required';
        }

        Validator::make(
            $values,
            $rules,
            $messages,
            collect(TransferDossier::KEYS)->mapWithKeys(fn (string $key, string $field): array => [
                $field => __("document_store.page.fields.{$field}"),
            ])->all(),
        )->validate();
    }
}
