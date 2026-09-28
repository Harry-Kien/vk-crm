<?php

namespace App\Actions\Matter;

use App\Enums\Confidentiality;
use App\Jobs\SendReassignmentDigest;
use App\Models\Matter;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Bàn giao HÀNG LOẠT nhiều vụ việc đang do MỘT luật sư phụ trách sang MỘT lead mới (SPEC §6.11;
 * M7 Task 2, màn hình `App\Filament\Admin\Pages\BulkReassign`). Phán quyết controller Task 2:
 * "một vòng lặp gọi `ReassignMatter::handle()` cho TỪNG vụ, mỗi vụ một transaction riêng, không
 * `update()` hàng loạt" — mỗi dòng `stage_logs` và mỗi mốc hạn phải đi qua chính xác luật của
 * `ReassignMatter`, không phải một câu UPDATE gộp bỏ qua các bước đó.
 *
 * # Mỗi vụ một transaction riêng, KHÔNG một transaction bọc cả lô
 *
 * `ReassignMatter::handle()` TỰ mở transaction của chính nó cho MỖI lần gọi — lớp này KHÔNG bọc
 * thêm một `DB::transaction()` nào quanh vòng lặp. Đây là điểm mấu chốt của "một vụ lỗi không
 * rollback vụ khác": nếu có một transaction NGOÀI bọc cả vòng lặp, một `ValidationException` ở
 * vụ thứ ba sẽ khiến Laravel rollback CẢ hai vụ đầu đã bàn giao thành công — đúng thứ brief cấm.
 * Để mỗi lời gọi `handle()` tự đóng transaction của nó (commit ngay khi xong) là điều kiện DUY
 * NHẤT cho phép vụ thứ ba thất bại mà không kéo theo hai vụ đầu.
 *
 * # Bốn họ lỗi, dịch thành MỘT dòng kết quả tiếng Việt — không phá vỡ vòng lặp
 *
 * `ValidationException` (lý do bị từ chối, ví dụ "đã là lead"), `AuthorizationException`
 * (`manageTeam` từ chối — vụ `restricted` một manager ép được vào payload), `DomainException`
 * (không dùng tới ở `ReassignMatter::handle()` hôm nay, nhưng bắt cho phòng xa) và
 * `ModelNotFoundException` đều bị bắt RIÊNG, KHÔNG để thoát ra khỏi vòng lặp — một exception thoát
 * ra sẽ dừng cả lô ở đúng vụ gây lỗi, bỏ hẳn những vụ đứng SAU trong danh sách (cùng bài học
 * `UsersTable`'s `DeleteBulkAction::using()`, fix round 3: "bắt rộng hơn... không phá vỡ toàn bộ
 * lượt xoá hàng loạt").
 *
 * **`AuthorizationException` không gắn mã/tiêu đề vụ việc vào kết quả — xem docblock
 * {@see BulkReassignMatterResult} cho lý do đầy đủ.** Ba họ lỗi còn lại đều ném ra SAU KHI
 * `manageTeam` đã cho qua (đó là câu ĐẦU TIÊN `ReassignMatter::handle()` hỏi), nên actor chắc chắn
 * đã hợp lệ để thấy vụ việc — an toàn để gắn mã/tiêu đề vào kết quả của chúng.
 *
 * # Vụ `restricted` LUÔN gỡ lead cũ, bất kể công tắc "giữ lại" của cả lô
 *
 * Màn hình chỉ có MỘT công tắc "giữ luật sư cũ trong đội ngũ" cho CẢ LÔ (khác nút bàn giao MỘT
 * vụ của `ViewMatter`, nơi công tắc bị ẨN HẲN trên riêng vụ `restricted`). Một lô có thể trộn vụ
 * thường và vụ `restricted` — bắt buộc phải tự ép `keepOldLeadAsAssociate = false` cho TỪNG vụ
 * `restricted`, bất kể `$keepOldLeadAsAssociate` cả lô đang bật gì, đúng luật nút một vụ đã có
 * (`ReassignMatter`'s docblock, mục "Lead cũ ở lại làm associate... vụ restricted xử khác"). Không
 * ép ở đây thì một lô có vụ `restricted` với công tắc bật sẽ ném `ValidationException`
 * ("old_lead_would_not_see_matter") cho ĐÚNG những vụ lẽ ra vẫn bàn giao được — biến một cú bàn
 * giao hợp lệ thành một dòng thất bại giả.
 *
 * # Một thư tổng hợp DUY NHẤT cho cả lô, chỉ liệt các vụ THÀNH CÔNG
 *
 * Mỗi lời gọi `ReassignMatter::handle()` chạy với `sendDigest: false` (hạ tầng Task 1) — Action
 * này tự gộp `$movedDeadlineIds`/`$movedRequestIds` của MỖI vụ THÀNH CÔNG (đọc từ
 * {@see ReassignMatterResult}, ẢNH CHỤP ngay dưới khoá của chính lần gọi đó — xem docblock của nó
 * cho lý do KHÔNG được re-query CSDL sau vòng lặp) theo `matter_id`, rồi dispatch ĐÚNG MỘT
 * {@see SendReassignmentDigest} sau khi vòng lặp xong, `->afterCommit()` (R2). Vụ thất bại không
 * góp mặt trong thư — người nhận chỉ nghe về những gì THẬT SỰ chuyển sang tay họ.
 *
 * Không vụ nào thành công thì không dispatch gì — cùng "không còn gì thì không gửi" của
 * `SendReassignmentDigest` (Task 1), áp dụng ở tầng gọi thay vì dispatch một job rồi để job đó tự
 * phát hiện payload rỗng.
 */
class ReassignMatters
{
    /**
     * @param  array<int, int>  $matterIds  Id các vụ việc đã chọn, ĐÚNG thứ tự caller truyền vào —
     *                                      mảng kết quả trả về giữ nguyên thứ tự này, một phần tử
     *                                      cho mỗi id (kể cả id không còn tồn tại).
     * @return array<int, BulkReassignMatterResult>
     */
    public function handle(array $matterIds, User $actor, User $newLead, string $reason, bool $keepOldLeadAsAssociate): array
    {
        $results = [];
        $digestMatters = [];

        foreach ($matterIds as $matterId) {
            $matter = Matter::query()->find($matterId);

            if ($matter === null) {
                $results[] = new BulkReassignMatterResult(
                    matterId: $matterId,
                    success: false,
                    message: __('reassign.bulk.results.not_found'),
                );

                continue;
            }

            try {
                // Vụ `restricted` luôn gỡ lead cũ — xem docblock lớp, mục tương ứng.
                $keepAssociate = $keepOldLeadAsAssociate && $matter->confidentiality !== Confidentiality::Restricted;

                $result = app(ReassignMatter::class)->handle(
                    matter: $matter,
                    actor: $actor,
                    newLead: $newLead,
                    reason: $reason,
                    keepOldLeadAsAssociate: $keepAssociate,
                    sendDigest: false,
                );

                $digestMatters[$matter->getKey()] = [
                    'deadline_ids' => $result->movedDeadlineIds,
                    'client_request_ids' => $result->movedRequestIds,
                    'reason' => $reason,
                ];

                $results[] = new BulkReassignMatterResult(
                    matterId: $matter->getKey(),
                    success: true,
                    message: __('reassign.bulk.results.success'),
                    matterCode: $matter->code,
                    matterTitle: $matter->title,
                    suggestIntroduction: (bool) $matter->is_published_to_portal,
                );
            } catch (AuthorizationException) {
                // Không gắn mã/tiêu đề — actor chưa qua manageTeam trên vụ này (xem docblock
                // BulkReassignMatterResult).
                $results[] = new BulkReassignMatterResult(
                    matterId: $matter->getKey(),
                    success: false,
                    message: __('reassign.bulk.results.unauthorized'),
                );
            } catch (ValidationException $exception) {
                $results[] = new BulkReassignMatterResult(
                    matterId: $matter->getKey(),
                    success: false,
                    message: collect($exception->errors())->flatten()->implode(' '),
                    matterCode: $matter->code,
                    matterTitle: $matter->title,
                );
            } catch (DomainException|ModelNotFoundException $exception) {
                $results[] = new BulkReassignMatterResult(
                    matterId: $matter->getKey(),
                    success: false,
                    message: $exception->getMessage(),
                    matterCode: $matter->code,
                    matterTitle: $matter->title,
                );
            }
        }

        if ($digestMatters !== []) {
            SendReassignmentDigest::dispatch($newLead->getKey(), $digestMatters)->afterCommit();
        }

        return $results;
    }
}
