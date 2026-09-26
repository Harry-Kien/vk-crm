<?php

namespace App\Actions\Matter;

use App\Actions\ReassignMatter;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sửa vụ việc sau khi mở (SPEC §4.6, §5; M6.5 Task 5, findings `intake/intake-06`,
 * `spec-gap/spec-gap-06`). Trước Task 5, `MatterResource` chỉ có index/create/view — một lỗi gõ
 * ở tiêu đề, tóm tắt cho khách, hay số thụ lý toà cấp SAU khi mở vụ (thường vài tuần sau) không
 * sửa được ở đâu cả.
 *
 * # Bốn trường sửa được, và ba trường KHÔNG BAO GIỜ sửa được qua đây
 *
 * `title`, `summary_for_client`, `court_name`, `case_number`, `confidentiality` — đúng năm cột
 * SPEC §4.6 liệt kê cho màn hình này. `client_id`, `matter_type_id` và `lead_lawyer_id` CỐ Ý
 * không nằm trong danh sách tham số của `handle()`: vụ gắn nhầm khách/loại vụ việc thì admin
 * "Huỷ hồ sơ mở nhầm" ({@see CancelMatter}), không sửa; luật sư phụ trách chỉ đổi qua bàn giao
 * ({@see ReassignMatter}, M7 Task 3). Nhận tường minh đúng năm khoá thay vì đẩy cả
 * mảng `$data` là cách duy nhất bảo đảm ba cột kia không bao giờ chạm được vào `fill()`, kể cả
 * khi một request bị chỉnh sửa tay gửi kèm chúng.
 *
 * # R5 — confidentiality đòi thêm một cổng
 *
 * Bốn trường còn lại chỉ cần `MatterPolicy::update` (qua `Gate::authorize('update', ...)`).
 * `confidentiality` cần thêm `MatterPolicy::updateConfidentiality` — "matter.update VÀ không
 * phải trợ lý" — và cổng đó CHỈ được hỏi khi giá trị THẬT SỰ đổi, để một form gửi lại nguyên giá
 * trị cũ (Filament luôn gửi mọi ô của form, kể cả ô người dùng không chạm tới) không chặn nhầm
 * một trợ lý đang sửa đúng những trường họ được phép sửa.
 *
 * # Khoá dòng TRƯỚC, không đọc gì trước khi khoá
 *
 * Câu lệnh ĐẦU TIÊN trong transaction là `lockForUpdate()` — dự án đã bị REPEATABLE READ của
 * MariaDB cắn một lần (xem lịch sử `TriageClientRequest::open()`): một lần đọc trần TRƯỚC khoá
 * cố định ảnh chụp (snapshot) của transaction ngay tại đó, nên nếu `Gate::authorize()` (một
 * lần đọc gián tiếp qua `MatterPolicy::view()`) chạy trước dòng khoá, hai request sửa cùng một
 * vụ gần như đồng thời có thể đọc cùng một bản ghi CŨ và ghi đè lên nhau một cách im lặng.
 * `Gate::authorize()` vì vậy chạy SAU khi đã khoá, trên chính bản ghi vừa khoá lại
 * (`$locked`), không trên `$matter` do caller truyền vào.
 *
 * # Audit — tên trường đã đổi, không phải giá trị thô (R14)
 *
 * `Matter` dùng `LogsActivity` (`getActivitylogOptions()`) nên MỌI lần `save()` với cột dirty đã
 * tự sinh một dòng "updated" trong `activity_log`, kèm cả giá trị cũ/mới của những cột được khai
 * báo `logOnly()`. Dòng `matter_details_updated` ở đây là một dòng THỨ HAI, có cấu trúc, chỉ nêu
 * TÊN các trường đã đổi (`changed_fields`) — không phải để thay thế dòng activitylog, mà để một
 * lần rà soát "ai đã sửa gì trên vụ việc này" đọc được ngay không phải diff hai dòng activitylog
 * liền nhau. Không ghi khi không có gì đổi: một dòng audit rỗng chỉ làm loãng nhật ký.
 */
class UpdateMatterDetails
{
    /**
     * @param  array{title?: string, summary_for_client?: ?string, court_name?: ?string, case_number?: ?string, confidentiality?: string}  $data
     */
    public function handle(Matter $matter, User $actor, array $data): Matter
    {
        return DB::transaction(function () use ($matter, $actor, $data): Matter {
            /** @var Matter $locked */
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('update', $locked);

            $confidentialityChanging = array_key_exists('confidentiality', $data)
                && $data['confidentiality'] !== $locked->confidentiality->value;

            if ($confidentialityChanging) {
                Gate::forUser($actor)->authorize('updateConfidentiality', $locked);
            }

            $editable = array_intersect_key($data, array_flip([
                'title', 'summary_for_client', 'court_name', 'case_number', 'confidentiality',
            ]));

            $locked->fill($editable);

            $changedFields = array_keys($locked->getDirty());

            if ($changedFields !== []) {
                $locked->blameOn($actor)->save();

                Audit::record('matter_details_updated', $locked, [
                    'changed_fields' => $changedFields,
                ], $actor);
            }

            return $locked;
        });
    }
}
