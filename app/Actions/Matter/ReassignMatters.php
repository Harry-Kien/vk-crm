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
use Throwable;

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
 * # Năm họ lỗi, dịch thành MỘT dòng kết quả tiếng Việt — không phá vỡ vòng lặp
 *
 * `ValidationException` (lý do bị từ chối, ví dụ "đã là lead", hoặc "đã trôi lead"/"đã đóng" —
 * fix round 1, finding 1), `AuthorizationException` (`manageTeam` từ chối — vụ `restricted` một
 * manager ép được vào payload), `DomainException` (không dùng tới ở `ReassignMatter::handle()` hôm
 * nay, nhưng bắt cho phòng xa), `ModelNotFoundException`, và — fix round 1, finding 3 — MỌI
 * `Throwable` khác (một `QueryException` từ lock-wait timeout/deadlock, một kết nối rớt giữa
 * chừng) đều bị bắt RIÊNG, KHÔNG để thoát ra khỏi vòng lặp — một exception thoát ra sẽ dừng cả lô ở
 * đúng vụ gây lỗi, bỏ hẳn những vụ đứng SAU trong danh sách (cùng bài học `UsersTable`'s
 * `DeleteBulkAction::using()`, fix round 3: "bắt rộng hơn... không phá vỡ toàn bộ lượt xoá hàng
 * loạt"). `catch (Throwable)` LUÔN đứng SAU CÙNG (PHP bắt theo đúng thứ tự khai báo, khớp đầu
 * tiên thắng) — bốn `catch` cụ thể hơn ở trên vẫn giữ nguyên thông điệp tiếng Việt đúng lý do của
 * chúng; chỉ những gì KHÔNG nằm trong bốn họ đó mới rơi xuống nhánh `Throwable`, được `report()`
 * (cùng lỗi vẫn lên Sentry/log như một request bình thường bị 500) rồi dịch thành một dòng thất
 * bại chung chung — không đoán được lý do thật để nói tiếng Việt cụ thể hơn.
 *
 * **`AuthorizationException` và nhánh "không tìm ra `$matter`" dùng CHUNG một câu trung lập
 * (`reassign.bulk.results.unavailable`) — fix round 2, finding I2.** Trước bản sửa này hai nhánh
 * này có hai câu khác nhau ("không còn tồn tại" / "không có quyền"), và `BulkReassign::form()`'s
 * luật `in:` khi đó cũng chỉ chấp nhận id của những vụ THẬT SỰ còn tồn tại — cộng lại, một id của
 * vụ `restricted` (tồn tại thật) QUA được `in:` và hiện một dòng kết quả, còn một id chưa từng
 * thuộc vụ nào bị CHÍNH `in:` chặn thành lỗi form, KHÔNG BAO GIỜ chạm tới đây — hai hình dạng phản
 * hồi khác nhau đủ để đếm ra chính xác bao nhiêu vụ `restricted` đang tồn tại (brief Task 2 cấm lộ
 * cả SỐ LƯỢNG, không chỉ mã/tiêu đề). `BulkReassign::form()`'s `in:` giờ chấp nhận CẢ dải id chưa
 * từng cấp phát (xem docblock ở đó) NÊN cả hai nhánh này giờ đều tới được đây — gộp chung câu trả
 * lời đóng nốt phần còn lại của oracle đó.
 *
 * **`AuthorizationException` không gắn mã/tiêu đề vụ việc vào kết quả — xem docblock
 * {@see BulkReassignMatterResult} cho lý do đầy đủ.** `ValidationException`, `DomainException` và
 * `ModelNotFoundException` đều ném ra SAU KHI `manageTeam` đã cho qua (đó là câu ĐẦU TIÊN
 * `ReassignMatter::handle()` hỏi), nên actor chắc chắn đã hợp lệ để thấy vụ việc — an toàn để gắn
 * mã/tiêu đề vào kết quả của chúng. Nhánh `Throwable` thì KHÔNG gắn mã/tiêu đề (fix round 2): một
 * lỗi lạ (kết nối rớt, lock-wait) có thể nổ TRƯỚC câu hỏi `manageTeam` đó — ví dụ ngay lúc
 * `ReassignMatter::handle()` mở transaction hay lấy khoá — nên không có gì bảo đảm actor được xem
 * vụ; dòng kết quả chỉ mang `matterId` (chính id actor vừa gửi lên) và câu chung chung.
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
 *
 * # Dispatch nằm trong `finally` bọc CẢ vòng lặp (fix round 1, finding 3)
 *
 * Bản trước dispatch digest SAU vòng lặp — chỉ đúng chừng nào không có exception nào thoát khỏi
 * `foreach`. `catch (Throwable)` ở mục trên đã đóng đường thoát đó cho mọi thứ nổ bên trong `try`
 * của từng vụ (kể cả câu `Matter::query()->find($matterId)`, cũng nằm trong `try` đó); `finally`
 * bọc CẢ vòng lặp là lưới thứ hai cho phần còn lại — một lỗi ném ra bởi chính một khối `catch`
 * (ví dụ `report()` hay `__()` hỏng), hoặc bởi một sửa đổi sau này đặt mã ra ngoài `try` của vụ.
 * Nếu dispatch nằm SAU vòng lặp (một câu lệnh riêng, không trong `finally`), một exception thoát
 * ra như vậy sẽ nhảy thẳng qua nó — các vụ ĐÃ commit trước đó không bao giờ được báo cho lead
 * mới, dù dữ liệu của chúng đã đổi chủ thật. "Không rơi mất mốc hạn nào" áp dụng cho MỌI vụ đã
 * thật sự commit. Lưu ý trung thực: `finally` KHÔNG có test riêng phân biệt được với
 * `catch (Throwable)` (test `ThrowsUnlistedExceptionOnSecondCall` đỏ khi bỏ CẢ HAI, vẫn xanh khi
 * chỉ bỏ `finally`) — nó là lưới phòng xa, không phải một điều kiện đã được đo.
 */
class ReassignMatters
{
    /**
     * @param  array<int, int>  $matterIds  Id các vụ việc đã chọn, ĐÚNG thứ tự caller truyền vào —
     *                                      mảng kết quả trả về giữ nguyên thứ tự này, một phần tử
     *                                      cho mỗi id (kể cả id không còn tồn tại).
     * @param  int  $expectedLeadId  Fix round 1, finding 1 — "luật sư đang phụ trách" đã chọn ở
     *                               đầu màn hình hàng loạt (`BulkReassign`). Truyền thẳng xuống
     *                               {@see ReassignMatter::handle()} làm `$expectedLeadId` cho MỖI
     *                               vụ — xem docblock của nó, mục "$expectedLeadId", cho lý do đầy
     *                               đủ. Không có khái niệm "hàng loạt không có lead đang chọn":
     *                               màn hình `BulkReassign` bắt buộc chọn trường này trước khi
     *                               liệt kê vụ việc để tick, nên tham số này KHÔNG có mặc định —
     *                               quên truyền nó phải là một lỗi biên dịch, không phải một khe
     *                               hở âm thầm quay lại hành vi trước fix round 1.
     * @return array<int, BulkReassignMatterResult>
     */
    public function handle(array $matterIds, User $actor, User $newLead, string $reason, bool $keepOldLeadAsAssociate, int $expectedLeadId): array
    {
        $results = [];
        $digestMatters = [];

        try {
            foreach ($matterIds as $matterId) {
                $matter = null;

                try {
                    $matter = Matter::query()->find($matterId);

                    if ($matter === null) {
                        // Fix round 2, finding I2 — CÙNG câu với nhánh AuthorizationException bên
                        // dưới (xem docblock lớp) — không còn 'not_found' riêng, để một id không
                        // còn tồn tại và một id của một vụ restricted actor không manageTeam được
                        // không thể phân biệt qua thông điệp trả về.
                        $results[] = new BulkReassignMatterResult(
                            matterId: $matterId,
                            success: false,
                            message: __('reassign.bulk.results.unavailable'),
                        );

                        continue;
                    }

                    // Vụ `restricted` luôn gỡ lead cũ — xem docblock lớp, mục tương ứng.
                    $keepAssociate = $keepOldLeadAsAssociate && $matter->confidentiality !== Confidentiality::Restricted;

                    $result = app(ReassignMatter::class)->handle(
                        matter: $matter,
                        actor: $actor,
                        newLead: $newLead,
                        reason: $reason,
                        keepOldLeadAsAssociate: $keepAssociate,
                        sendDigest: false,
                        expectedLeadId: $expectedLeadId,
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
                    // BulkReassignMatterResult). Fix round 2, finding I2 — CÙNG câu với nhánh
                    // "không tìm thấy" ở trên, không còn 'unauthorized' riêng (xem docblock lớp).
                    $results[] = new BulkReassignMatterResult(
                        matterId: $matterId,
                        success: false,
                        message: __('reassign.bulk.results.unavailable'),
                    );
                } catch (ValidationException $exception) {
                    $results[] = new BulkReassignMatterResult(
                        matterId: $matterId,
                        success: false,
                        message: collect($exception->errors())->flatten()->implode(' '),
                        matterCode: $matter?->code,
                        matterTitle: $matter?->title,
                    );
                } catch (DomainException|ModelNotFoundException $exception) {
                    $results[] = new BulkReassignMatterResult(
                        matterId: $matterId,
                        success: false,
                        message: $exception->getMessage(),
                        matterCode: $matter?->code,
                        matterTitle: $matter?->title,
                    );
                } catch (Throwable $exception) {
                    // Fix round 1, finding 3 — cùng lưới an toàn của UsersTable's
                    // DeleteBulkAction::using(): report() giữ log/Sentry như một lỗi thật, KHÔNG
                    // ném tiếp (mới là điểm mấu chốt — ném tiếp sẽ lại thoát khỏi vòng lặp, đúng
                    // thứ finding này sửa). Fix round 2: KHÔNG gắn mã/tiêu đề — lỗi có thể nổ
                    // trước cả câu hỏi manageTeam (xem docblock lớp).
                    report($exception);

                    $results[] = new BulkReassignMatterResult(
                        matterId: $matterId,
                        success: false,
                        message: __('reassign.bulk.results.unexpected_error'),
                    );
                }
            }
        } finally {
            // Xem docblock lớp, mục "Dispatch nằm trong finally" — chạy dù foreach có thoát bằng
            // một exception không được catch() nào ở trên bắt kịp hay không.
            if ($digestMatters !== []) {
                SendReassignmentDigest::dispatch($newLead->getKey(), $digestMatters)->afterCommit();
            }
        }

        return $results;
    }
}
