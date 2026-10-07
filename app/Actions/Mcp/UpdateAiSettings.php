<?php

namespace App\Actions\Mcp;

use App\Actions\Settings\WriteSettings;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpSwitches;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Lưu cấu hình TOÀN HỆ THỐNG của máy chủ MCP (M11 R2, R12 mục 3) vào bảng `settings`, đọc lại qua
 * {@see McpSwitches}. Màn hình gọi nó: trang "Kết nối AI" (`App\Filament\Admin\Pages\AiConnections`,
 * Task 15) — cùng khuôn với `UpdateOfficeProfile` trên bước ghi chung {@see WriteSettings}.
 *
 * # Ba trường, khoá vắng mặt thì giữ nguyên
 *
 *  - `enabled` (bool) → `mcp.enabled`: tắt thì mọi request `/mcp` nhận 401 ở request kế tiếp;
 *  - `write_enabled` (bool) → `mcp.write_enabled`: tắt thì không ai ghi được qua AI (R13);
 *  - `transfer_assessment_filed_on` (`Y-m-d` hoặc `null`) → ngày đã nộp hồ sơ đánh giá tác động
 *    chuyển dữ liệu xuyên biên giới (R12 mục 3). Chỉ tắt dải cảnh báo của trang, KHÔNG mở hay đóng
 *    gì khác. Không nhận ngày trong tương lai (ngày "đã nộp"); `null` hay chuỗi rỗng xoá ngày đã ghi,
 *    dải cảnh báo hiện lại.
 *
 * Công tắc lưu ĐÚNG chuỗi {@see McpSwitches::ON} / {@see McpSwitches::OFF} — người đọc chỉ coi
 * `'1'` là bật.
 *
 * # Quyền
 *
 * Chỉ người có `settings.manage` (chỉ admin, SPEC §5), hỏi `Gate::forUser($actor)` ngay đầu — không
 * tin trang đã gác. Thiếu quyền: `AuthorizationException`, không ghi gì.
 *
 * # Ghi và audit
 *
 * Kiểm tra hỏng thì `ValidationException` gắn đúng tên trường, cả lô không ghi gì. Rồi một
 * transaction: {@see WriteSettings} (câu đầu tiên là lần đọc khoá các dòng, trả khoá đã đổi), và —
 * CHỈ khi có gì đổi — một dòng `ai_settings_updated`, causer là `$actor` tường minh, `changed` = tên
 * trường → giá trị MỚI (cờ bật/tắt, ngày). Không giá trị nào là dữ liệu cá nhân. R8: "ghi cả … bật/tắt".
 * Không gửi thư, không thông báo.
 */
final class UpdateAiSettings
{
    /** Tên trường → khoá trong bảng `settings`. */
    public const FIELDS = [
        'enabled' => McpSwitches::ENABLED,
        'write_enabled' => McpSwitches::WRITE_ENABLED,
        'transfer_assessment_filed_on' => McpSwitches::TRANSFER_ASSESSMENT_FILED_ON,
    ];

    public function __construct(private readonly WriteSettings $writeSettings) {}

    /**
     * @param  array<string, mixed>  $input  trường => giá trị (một phần hay đủ ba trường)
     * @return list<string> tên các trường đã đổi
     */
    public function handle(User $actor, array $input): array
    {
        if (! Gate::forUser($actor)->allows(Permission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        $input = array_intersect_key($input, self::FIELDS);

        if (array_key_exists('transfer_assessment_filed_on', $input) && blank($input['transfer_assessment_filed_on'])) {
            $input['transfer_assessment_filed_on'] = null;
        }

        Validator::make($input, [
            'enabled' => ['sometimes', 'boolean'],
            'write_enabled' => ['sometimes', 'boolean'],
            'transfer_assessment_filed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'transfer_assessment_filed_on.date_format' => __('ai_connections.validation.filed_on_format'),
            'transfer_assessment_filed_on.before_or_equal' => __('ai_connections.validation.filed_on_future'),
        ])->validate();

        $values = [];
        $newValues = [];

        foreach ($input as $field => $value) {
            if ($field === 'transfer_assessment_filed_on') {
                $values[self::FIELDS[$field]] = $value;
                $newValues[$field] = $value;

                continue;
            }

            $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $values[self::FIELDS[$field]] = $on ? McpSwitches::ON : McpSwitches::OFF;
            $newValues[$field] = $on;
        }

        return DB::transaction(function () use ($values, $newValues, $actor): array {
            $changedKeys = $this->writeSettings->handle($values, $actor);

            $changed = array_values(array_filter(
                array_keys(self::FIELDS),
                fn (string $field): bool => in_array(self::FIELDS[$field], $changedKeys, true),
            ));

            if ($changed !== []) {
                Audit::record('ai_settings_updated', null, [
                    'changed' => array_intersect_key($newValues, array_flip($changed)),
                ], $actor);
            }

            return $changed;
        }, WriteSettings::ATTEMPTS);
    }
}
