<?php

namespace App\Actions\Matter;

use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sửa vụ việc sau khi mở (SPEC §4.6, §5; M6.5 Task 5, findings `intake/intake-06`,
 * `spec-gap/spec-gap-06`). Trước Task 5, `MatterResource` chỉ có index/create/view — một lỗi gõ
 * ở tiêu đề, tóm tắt cho khách, hay số thụ lý toà cấp SAU khi mở vụ (thường vài tuần sau) không
 * sửa được ở đâu cả.
 *
 * # Bảy trường sửa được, và ba trường KHÔNG BAO GIỜ sửa được qua đây
 *
 * `title`, `summary_for_client`, `court_name`, `case_number`, `confidentiality` — đúng năm cột
 * SPEC §4.6 liệt kê cho màn hình này, cộng `description_internal` và `opened_at` (làn fm A3, xem mục
 * dưới). `client_id`, `matter_type_id` và `lead_lawyer_id` CỐ Ý không nằm trong danh sách trắng
 * `$editable` của `handle()`: vụ gắn nhầm khách/loại vụ việc thì admin "Huỷ hồ sơ mở nhầm"
 * ({@see CancelMatter}), không sửa; luật sư phụ trách chỉ đổi qua bàn giao
 * (`App\Actions\ReassignMatter`, M6.5 Task 4). Lọc theo đúng bảy khoá thay vì đẩy cả mảng `$data`
 * là cách duy nhất bảo đảm ba cột kia không bao giờ chạm được vào `fill()`, kể cả khi một request
 * bị chỉnh sửa tay gửi kèm chúng.
 *
 * # Ba trường chỉ cần `matter.update`, hai trường đòi thêm một cổng riêng
 *
 * `title`, `court_name`, `case_number` chỉ cần `MatterPolicy::update` (qua
 * `Gate::authorize('update', ...)`). Hai trường còn lại đòi thêm, và cổng thêm đó CHỈ được hỏi
 * khi giá trị THẬT SỰ đổi, để một form gửi lại nguyên giá trị cũ (Filament luôn gửi mọi ô của
 * form, kể cả ô người dùng không chạm tới) không chặn nhầm người đang sửa đúng những trường họ
 * được phép sửa:
 *
 *  - **`confidentiality`** (fix round 1, finding I2 — chốt lại R5): CHỈ luật sư phụ trách của
 *    CHÍNH vụ việc này hoặc admin (`MatterPolicy::updateConfidentiality`). Chuyển SANG
 *    `restricted` còn bị từ chối thêm một lần nữa nếu đội ngũ còn thành viên KHÁC lead và admin —
 *    người đó sẽ hết thấy được vụ việc ngay sau khi đổi (`Matter::isListableBy()`, nhánh
 *    restricted chỉ nhận lead và admin). Luật đội ngũ này KHÔNG nằm trong policy (một ability chỉ
 *    nhận `(User, Matter)`, không nhận giá trị MỚI đang định gán, nên không tự phân biệt được
 *    "giữ nguyên/chuyển ra" với "chuyển vào" `restricted`) — nó nằm ở đây, nơi giá trị mới đã có
 *    sẵn trong `$data`, và từ chối bằng một câu tiếng Việt nêu tên từng người cần chuyển đi trước
 *    (qua tab Đội ngũ, nơi đã chặn gỡ một người còn giữ việc dở dang — M6.5 Task 3).
 *  - **`summary_for_client`** (fix round 1, finding I1): đòi `MatterPolicy::updateSummaryForClient`
 *    — cùng quyền `stageLog.publish` như công bố một dòng tiến độ cho khách, vì đây cũng là một
 *    lời văn phòng ĐƯA RA cho khách đọc (SPEC §8.3 khối 1). Trợ lý có `matter.update` nhưng không
 *    có `stageLog.publish`, nên không đổi được trường này dù vẫn sửa được ba trường kia.
 *
 * Cả hai lần từ chối ném `ValidationException` gắn thẳng vào đúng tên trường
 * (`confidentiality`/`summary_for_client`) — không phải `AuthorizationException` chung chung —
 * để `EditMatter` không phải đoán ô nào gây lỗi (fix round 1, finding minor "EditMatter.php:119":
 * trang đó giờ chỉ cần dịch STATE PATH của một `ValidationException` đã có sẵn tên trường, không
 * còn phải tự suy đoán "mọi AuthorizationException đều là confidentiality").
 *
 * # Ghi chú nội bộ và ngày mở hồ sơ (làn fm A3, kiểm tra nghiệp vụ 2026-10-09)
 *
 * Hai trường thêm vào danh sách sửa được, chỉ cần `matter.update`: `description_internal` ("Ghi chú
 * nội bộ" nhập lúc mở vụ — trước đây không màn hình nào đọc lại hay sửa được) và `opened_at` (in vào
 * mục lục gói bàn giao, đếm vào "mở trong tháng"). Ghi chú rỗng là `null`, dài quá
 * {@see self::DESCRIPTION_INTERNAL_MAX} thì từ chối. Ngày mở phải là một ngày thật, không sau hôm
 * nay và không sau ngày kết thúc. `matter_details_updated` chỉ nêu TÊN trường — nội dung ghi chú
 * không vào nhật ký (`Matter::getActivitylogOptions()` cũng cố ý bỏ cột này); ngày mở cũ/mới đã có
 * trong dòng "updated" của activitylog.
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
     * Trần chủ động của "Ghi chú nội bộ" (`matters.description_internal`, cột `text` = 65.535 byte):
     * 16.000 ký tự × tối đa 4 byte utf8mb4 vẫn nằm trong cột, nên MariaDB strict không bao giờ cắt.
     * Form (tạo và sửa) dùng cùng hằng số này.
     */
    public const DESCRIPTION_INTERNAL_MAX = 16000;

    /**
     * @param  array{title?: string, summary_for_client?: ?string, court_name?: ?string, case_number?: ?string, confidentiality?: string, description_internal?: ?string, opened_at?: string|CarbonInterface}  $data
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
                $this->authorizeConfidentialityChange($actor, $locked, $data['confidentiality']);
            }

            $summaryChanging = array_key_exists('summary_for_client', $data)
                && $data['summary_for_client'] !== $locked->summary_for_client;

            if ($summaryChanging && Gate::forUser($actor)->denies('updateSummaryForClient', $locked)) {
                throw ValidationException::withMessages([
                    'summary_for_client' => [__('matters.edit_form.summary_for_client_denied')],
                ]);
            }

            $editable = array_intersect_key($data, array_flip([
                'title', 'summary_for_client', 'court_name', 'case_number', 'confidentiality',
                'description_internal', 'opened_at',
            ]));

            // Làn fm A3: hai trường mới — xem docblock lớp, mục "Ghi chú nội bộ và ngày mở hồ sơ".
            if (array_key_exists('description_internal', $editable)) {
                $editable['description_internal'] = $this->validatedInternalNote($editable['description_internal']);
            }

            if (array_key_exists('opened_at', $editable)) {
                $editable['opened_at'] = $this->validatedOpenedAt($locked, $editable['opened_at'])->toDateString();
            }

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

    /** Rỗng (hoặc toàn khoảng trắng) là "không có ghi chú" (`null`); dài quá trần thì từ chối trên đúng ô. */
    private function validatedInternalNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        if ($note === null || $note === '') {
            return null;
        }

        if (mb_strlen($note) > self::DESCRIPTION_INTERNAL_MAX) {
            throw ValidationException::withMessages([
                'description_internal' => [__('lifecycle.details.internal_note_too_long', ['max' => self::DESCRIPTION_INTERNAL_MAX])],
            ]);
        }

        return $note;
    }

    /**
     * Ngày mở hồ sơ: một ngày thật (`Y-m-d` từ ô chọn ngày, hoặc một đối tượng ngày), không sau hôm
     * nay theo múi giờ ứng dụng, và không sau ngày vụ kết thúc (`Matter::closedBefore()`).
     */
    private function validatedOpenedAt(Matter $matter, mixed $value): CarbonInterface
    {
        $invalid = fn (string $key): ValidationException => ValidationException::withMessages([
            'opened_at' => [__("lifecycle.details.{$key}")],
        ]);

        if ($value instanceof CarbonInterface) {
            $date = $value->copy()->startOfDay();
        } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            if ($date === false || $date->toDateString() !== $value) {
                throw $invalid('opened_at_invalid');
            }
        } else {
            throw $invalid('opened_at_invalid');
        }

        if ($date->toDateString() > today()->toDateString()) {
            throw $invalid('opened_at_future');
        }

        if ($matter->closedBefore($date)) {
            throw $invalid('opened_at_after_closed');
        }

        return $date;
    }

    /**
     * Hai điều kiện, hỏi theo đúng thứ tự người dùng cần đọc: trước hết "anh/chị có được đổi
     * trường này không" (ai không phải lead/admin dừng lại ở đây, không cần biết gì về đội ngũ),
     * rồi mới tới "đội ngũ có đang chặn HƯỚNG đổi cụ thể này không" — chỉ hỏi khi hướng đổi là VÀO
     * `restricted`, và chỉ tới người đã qua được câu hỏi đầu.
     */
    private function authorizeConfidentialityChange(User $actor, Matter $matter, string $newValue): void
    {
        if (Gate::forUser($actor)->denies('updateConfidentiality', $matter)) {
            throw ValidationException::withMessages([
                'confidentiality' => [__('matters.edit_form.confidentiality_denied')],
            ]);
        }

        if ($newValue !== Confidentiality::Restricted->value) {
            return;
        }

        $ineligible = $this->ineligibleTeamMembers($matter);

        if ($ineligible->isNotEmpty()) {
            throw ValidationException::withMessages([
                'confidentiality' => [__('matters.edit_form.confidentiality_blocked_by_team', [
                    'names' => $ineligible->pluck('name')->implode(', '),
                ])],
            ]);
        }
    }

    /**
     * Thành viên đội ngũ sẽ HẾT THẤY được vụ việc ngay sau khi chuyển sang `restricted`:
     * `Matter::isListableBy()` nhánh restricted chỉ nhận lead (`lead_lawyer_id`) và admin
     * (`hasRole`), nên MỌI thành viên khác — associate, assistant, observer, kể cả một manager —
     * đều rơi khỏi tầm nhìn ngay khi dòng này commit, trừ khi họ cũng là admin.
     *
     * Final review A-M3: không riêng thành viên đội ngũ — người đang GIỮ một mốc hạn chưa xong hay
     * một yêu cầu khách chưa đóng của vụ này cũng rơi khỏi tầm nhìn y như vậy, và trở thành một
     * người giữ việc không mở được việc mình giữ. Tính cả hai nhóm (trừ lead và admin).
     *
     * @return Collection<int, User>
     */
    private function ineligibleTeamMembers(Matter $matter): Collection
    {
        $members = $matter->team()
            ->wherePivot('role_in_matter', '!=', MatterRole::Lead->value)
            ->get();

        $holders = User::query()
            ->where(fn ($holds) => $holds
                ->whereIn('id', $matter->deadlines()->where('is_completed', false)->select('responsible_user_id'))
                ->orWhereIn('id', $matter->clientRequests()
                    ->where('status', '!=', ClientRequestStatus::Closed->value)
                    ->whereNotNull('assigned_to')
                    ->select('assigned_to')))
            ->get();

        return $members->concat($holders)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->reject(fn (User $user): bool => (int) $user->getKey() === (int) $matter->lead_lawyer_id)
            ->reject(fn (User $user): bool => $user->hasRole(Role::Admin->value))
            ->values();
    }
}
