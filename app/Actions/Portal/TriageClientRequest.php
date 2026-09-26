<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Hộp thư của văn phòng, phần KHÔNG phải viết chữ: **nhận, giao việc, đổi trạng thái** —
 * SPEC §7.2 tab "Yêu cầu từ khách".
 *
 * # Vì sao có Action thứ ba, khi kế hoạch M5 chỉ liệt kê hai
 *
 * Kế hoạch Task 6 liệt kê `OpenClientRequest` và `ReplyToClientRequest`. Nhưng SPEC §7.2 giao cho
 * văn phòng **bốn** động từ — "nhận, đổi trạng thái, gán người xử lý, trả lời" — và ba động từ
 * đầu là những lần GHI vào `client_requests`. CLAUDE.md nói thẳng: *nghiệp vụ nằm trong
 * `app/Actions/`; Filament resource/controller/job chỉ gọi Action*, và đó không phải một quy ước
 * trang trí — vòng rà soát M3 đã bắt `ViewMatter` gọi thẳng `$record->update()` và phải tách ra
 * thành `SetMatterPortalPublication` vì đúng lý do đang áp dụng ở đây: cổng quyền, kiểm tra
 * trạng thái và dòng nhật ký phải sống cùng một chỗ với lần ghi.
 *
 * Nên lệch so với kế hoạch là **có**, và nó được ghi ra chứ không làm lặng lẽ: một tệp Action nữa,
 * cùng namespace `Portal` với hai tệp kia. Namespace nói về TÍNH NĂNG (cuộc trao đổi với khách),
 * không về việc ai thao tác — `ReplyToClientRequest` cũng nhận cả hai phía.
 *
 * # Hai phương thức, không phải một `handle()` mang bốn tham số
 *
 * "Giao cho ai" và "đang ở đâu" là hai câu hỏi, và gộp chúng vào một chữ ký sẽ đẻ ra một tham số
 * sentinel để phân biệt "gán cho không ai" với "đừng đụng vào người đang giữ" — đúng loại tham số
 * mà nơi gọi truyền sai một lần là im lặng xoá mất người phụ trách. Hai phương thức, mỗi cái một
 * câu, dùng chung {@see self::open()} cho phần đọc lại và gác cổng.
 *
 * # "Giao việc" cũng là "nhận"
 *
 * SPEC §7.2 kể "nhận" như một động từ riêng, nhưng nó không phải một cột riêng: một yêu cầu được
 * nhận là một yêu cầu **đã có người đứng tên và không còn là `new`**. Nên {@see self::assign()}
 * tự đẩy `new → in_progress`, và trợ lý bấm "Giao việc" chọn chính mình là đã "nhận". Một nút
 * thứ hai chỉ để đổi một chữ sẽ là một nút người ta quên bấm, và khi đó cột trạng thái nói sai.
 *
 * Chiều ngược lại **không** tự động: gỡ người phụ trách (`null`) KHÔNG kéo trạng thái về `new`.
 * `new` nghĩa là "chưa ai trong văn phòng nhìn thấy", và một khi đã có người nhìn thì điều đó
 * không thành chưa xảy ra được nữa. Cùng một luật đó bịt nốt đường vòng: {@see self::setStatus()}
 * cũng từ chối đặt lại `new` cho một luồng đã rời `new`.
 *
 * # Người được giao việc phải MỞ ĐƯỢC hồ sơ, và phải CÒN ĐI LÀM
 *
 * {@see self::assign()} hỏi cả hai câu **trên người được giao**, không chỉ trên người đang giao —
 * xem {@see self::canHoldTheThread()}. Giao một yêu cầu cho người không mở được vụ việc là đẩy nó
 * vào một hàng đợi không ai nhìn thấy: nó biến mất khỏi "chưa ai nhận" mà không ai làm được gì
 * với nó. Với một vụ việc `restricted` (SPEC §4.6) đây còn là một cách rò rỉ tên hồ sơ qua một ô
 * chọn. Câu thứ hai — tài khoản còn hiệu lực — được thêm ở vòng rà soát 21/09/2026, vì
 * `MatterPolicy::update` không đọc `users.is_active` và một luật sư đã nghỉ việc đi lọt cổng thứ
 * nhất.
 *
 * # Không đọc `auth()`, không tin tham số
 *
 * Cùng kỷ luật với {@see OpenClientRequest} và {@see ReplyToClientRequest}.
 */
class TriageClientRequest
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /**
     * Giao một yêu cầu cho một người, hoặc gỡ người đang giữ ra (`$assignee === null`).
     *
     * `$matterId` được đọc ở ĐÂY, TRƯỚC KHI `DB::transaction()` mở — xem "Đọc `matter_id` TRƯỚC
     * transaction, không phải bên trong" ở docblock {@see self::open()} cho lý do bắt buộc
     * (fix round 3, finding I3 residual — snapshot REPEATABLE READ).
     *
     * **`last_activity_at` chỉ nhảy khi lần giao việc này CŨNG đổi trạng thái (fix round 1,
     * ruling).** "Giao việc" tự nó là một việc QUẢN TRỊ — chọn ai đứng tên — không phải một lượt
     * trao đổi, nên nó không tự động là "hoạt động" của hộp thư (xem docblock migration
     * `add_last_activity_at_to_client_requests_table`). Nhưng khi giao việc ĐẨY luồng ra khỏi
     * `new` (nhánh `new → in_progress` ngay dưới), đó là thời điểm "chưa ai xem" chuyển thành "đã
     * có người xem" — một sự kiện thật về cuộc trao đổi, không chỉ về sổ phân công — nên khi đó
     * (và chỉ khi đó) cột được đóng dấu lại. Gán lại một luồng ĐANG `in_progress` cho một người
     * khác (đổi tay, không đổi trạng thái) thì KHÔNG đóng dấu.
     */
    public function assign(ClientRequest $request, User $actor, ?User $assignee): ClientRequest
    {
        $matterId = $this->realMatterId($request);

        return DB::transaction(function () use ($request, $actor, $assignee, $matterId): ClientRequest {
            [$thread, $matter] = $this->open($request, $actor, $matterId);

            if ($assignee !== null && ! $this->canHoldTheThread($assignee, $matter)) {
                // `ValidationException` chứ không `AuthorizationException`: câu này nói về Ô CHỌN —
                // người đang giao có quyền, họ chỉ vừa chọn sai người — và nó phải hiện ngay dưới ô
                // đó. Khoá là tên trần `assigned_to`, trùng tên ô trong modal; `ReportsActionFailures`
                // dịch nó sang state path thật.
                throw ValidationException::withMessages([
                    'assigned_to' => [__('requests.validation.assignee_cannot_open')],
                ]);
            }

            $previous = $thread->assigned_to;
            $previousStatus = $thread->status;

            $thread->assigned_to = $assignee?->getKey();

            // "Giao việc" cũng là "nhận" — xem docblock lớp. Một chiều, không có chiều ngược lại.
            if ($assignee !== null && $thread->status === ClientRequestStatus::New) {
                $thread->status = ClientRequestStatus::InProgress;
            }

            // Chỉ đóng dấu hoạt động khi trạng thái THẬT SỰ đổi — xem docblock hàm ngay ở trên.
            if ($thread->status !== $previousStatus) {
                $thread->last_activity_at = now();
            }

            $thread->save();

            Audit::record('client_request_assigned', $thread, [
                'matter_id' => $thread->matter_id,
                'client_id' => $matter->client_id,
                'from' => $previous,
                'to' => $thread->assigned_to,
            ], causer: $actor);

            return $thread;
        });
    }

    /**
     * **"Người này có thật sự mở được hồ sơ không" — HAI câu hỏi, không một.**
     *
     * `MatterPolicy::update` trả lời câu thứ nhất (quyền), và nó **không đọc `users.is_active`**:
     * chỗ duy nhất trong dự án đọc cột đó là `User::canAccessPanel()`, và hàm ấy chỉ chạy cho
     * người đang đăng nhập, không bao giờ cho một người thứ ba được nhắc tên trong một ô chọn.
     * Nên trước bản sửa này một tài khoản đã bị vô hiệu hoá — hoặc đã xoá mềm — đi lọt cổng, và
     * vì "giao việc" cũng là "nhận" (xem docblock lớp), yêu cầu rời luôn `new` — cột "Người xử
     * lý" thôi nói "Chưa ai nhận" — và cổng khách bắt đầu nói "Văn phòng đang xem và chuẩn bị
     * trả lời anh/chị" về một luồng không ai mở được nữa. Đó là đúng cái hố mà cổng này sinh ra
     * để lấp, đào bằng một cột khác.
     *
     * {@see ChecksAccountActive} là **cùng một câu hỏi** mà Action đã hỏi về người đang thao tác;
     * ở đây nó được hỏi về người sắp phải làm việc. Một định nghĩa, hai lần gọi — không chép lại
     * điều kiện nào.
     */
    private function canHoldTheThread(User $assignee, Matter $matter): bool
    {
        return $this->accountIsActive($assignee) && Gate::forUser($assignee)->allows('update', $matter);
    }

    /**
     * Đặt trạng thái tay: `new → in_progress → answered → closed`, và ngược lại khi cần mở lại
     * một việc đã đóng sớm.
     *
     * **Không có ma trận chuyển trạng thái nào ở đây, trừ ĐÚNG MỘT bước, và cả hai vế là một
     * quyết định.** `Matter` có một ma trận đầy đủ (`allowed_next`, SPEC §4.5) vì giai đoạn tố
     * tụng là một quy trình pháp lý có thứ tự; một cuộc trao đổi qua lại thì không. Một trợ lý
     * bấm nhầm "Đã đóng" phải mở lại được ngay, và một luật sư trả lời qua điện thoại rồi đánh
     * dấu thẳng "Đã trả lời" là việc đúng, không phải một bước nhảy cóc.
     *
     * Bước bị chặn là **quay về `new`**. `new` không phải một bước trong quy trình mà là một lời
     * khẳng định về thế giới — *chưa ai trong văn phòng nhìn thấy cái này* — và một khi đã có
     * người nhìn thì điều đó không thành chưa xảy ra được nữa. Đây là cùng một luật mà
     * {@see self::assign()} đã giữ ở chiều của nó (gỡ người phụ trách ra KHÔNG kéo trạng thái về
     * `new`), và docblock lớp đã tuyên bố nó từ đầu; bản đầu của hàm này thì nhận cả bốn giá trị
     * từ một ô chọn bày ra cả bốn, nên mã và docblock nói hai chuyện khác nhau. Hệ quả thật nếu
     * để lọt: một hàng vừa mang nhãn "Mới" — tức "chưa ai nhìn thấy" — vừa có tên người phụ
     * trách ở cột bên cạnh, và cổng khách nói với khách rằng chưa ai xem cái họ gửi, sau khi đã
     * có người xem.
     *
     * Đặt `new` lên một luồng ĐANG `new` vẫn được — đó là một lần không-làm-gì (ô chọn được đổ
     * sẵn giá trị hiện tại), không phải một bước lùi.
     *
     * `answered_at` được ghi khi trạng thái ĐẾN `answered` và cột còn trống — trường hợp thật là
     * "văn phòng đã gọi điện trả lời rồi mới vào đánh dấu". Nếu cột đã có giá trị thì giữ nguyên,
     * và {@see ReplyToClientRequest} cũng vậy: cột đó là **lần đầu** văn phòng trả lời và nó
     * không bao giờ dịch đi (phán quyết 21/09/2026, lý lẽ đầy đủ ở docblock lớp của Action kia).
     * Rời khỏi `answered` **không** xoá cột: một sự kiện đã xảy ra thì không viết lại được cho
     * khớp một cái nhãn.
     *
     * `last_activity_at` luôn được đóng dấu lại: đổi trạng thái tay là một trong bốn đường hoạt
     * động mà hộp thư sắp theo (Task 18, REQ-2 — xem docblock migration
     * `add_last_activity_at_to_client_requests_table`), kể cả khi giá trị `$status` trùng với
     * trạng thái hiện tại (ô chọn được đổ sẵn giá trị cũ và người dùng bấm Lưu mà không đổi gì
     * vẫn là một lần nhân sự vừa động vào luồng này).
     *
     * **Mở lại một luồng đã đóng thì hỏi lại người đang đứng tên (vòng rà soát Task 3, mang sang
     * đây).** `assign()` chỉ hỏi `canHoldTheThread()` một LẦN, lúc giao việc. Một luồng `closed`
     * có thể đã bị bỏ quên nhiều ngày, và trong lúc đó người đang giữ (`assigned_to`) có thể đã
     * rời khỏi đội ngũ vụ việc, bị vô hiệu hoá, bị xoá mềm, hoặc không còn `MatterPolicy::update`
     * vì một lý do khác — không đường nào khác hỏi lại câu đó cho một luồng đã đóng, vì nó không
     * đi qua `assign()` nữa. Mở nó ra lại mà không hỏi lại là tái tạo đúng lỗ hổng mà REQ-3 nêu
     * tên: cột "Người xử lý" nói một cái tên, còn người đó không mở nổi hồ sơ để làm gì với nó.
     *
     * Nên khi `$previous === Closed` và `$status` mới KHÔNG phải `Closed` (tức đang MỞ LẠI), nếu
     * luồng còn người đứng tên thì hỏi lại đúng luật `assign()` dùng — {@see self::
     * canHoldTheThread()} — và gỡ người đó ra nếu không còn giữ được, thay vì âm thầm mở lại một
     * luồng "đang xử lý" mà không ai xử lý được.
     *
     * **Trả về một {@see SetClientRequestStatusResult}, không phải `ClientRequest` trần (fix
     * round 1, minor).** Bản trước chỉ trả luồng, và màn hình gọi hàm này
     * ({@see ClientRequestsRelationManager}) tự SUY ra việc gỡ người có xảy ra không bằng cách so
     * `assigned_to` của bản ghi TRƯỚC lời gọi với `assigned_to` của luồng SAU lời gọi — một phép
     * trừ hai ảnh chụp màn hình dựa trên tiền đề "không đường nào khác đụng `assigned_to` giữa
     * hai lần đọc". Tiền đề đó KHÔNG còn đúng tuyệt đối một khi có Action khác (ví dụ
     * `ReassignMatter`, bàn giao hàng loạt) cũng ghi cột này, và một khác biệt bị đọc sai thành
     * "vừa gỡ người" đẻ ra đúng câu bị cấm: thông báo lấy TÊN từ một chỗ không phải người vừa bị
     * gỡ (nếu bản ghi trước lời gọi không có `assignee` nạp sẵn, phần dự phòng từng ghép nhãn
     * "Chưa ai nhận" — nguyên văn placeholder của ô trống — vào chỗ một cái TÊN, ra một câu vô
     * nghĩa "Chưa ai nhận không còn mở được vụ việc này..."). Nay Action tự báo: nó biết chính xác
     * nó vừa gỡ AI (chính đối tượng `User` đã hỏi `canHoldTheThread()`, không phải một id đọc lại
     * hụt), và trả thẳng ra — màn hình chỉ đọc kết quả, không suy đoán, không cần một câu dự phòng
     * nào cho tên.
     *
     * `$matterId` đọc TRƯỚC `DB::transaction()`, cùng lý do ở {@see self::assign()}.
     */
    public function setStatus(ClientRequest $request, User $actor, ClientRequestStatus $status): SetClientRequestStatusResult
    {
        $matterId = $this->realMatterId($request);

        return DB::transaction(function () use ($request, $actor, $status, $matterId): SetClientRequestStatusResult {
            [$thread, $matter] = $this->open($request, $actor, $matterId);

            $previous = $thread->status;

            if ($status === ClientRequestStatus::New && $previous !== ClientRequestStatus::New) {
                // Cùng hình dạng với câu từ chối của `assign()`: một ValidationException gắn vào
                // đúng tên ô trong modal (`status`), vì người đọc đang nhìn thẳng vào ô đó và
                // việc cần làm là chọn lại một giá trị khác.
                throw ValidationException::withMessages([
                    'status' => [__('requests.validation.cannot_return_to_new')],
                ]);
            }

            $thread->status = $status;
            $thread->last_activity_at = now();

            if ($status === ClientRequestStatus::Answered && $thread->answered_at === null) {
                $thread->answered_at = now();
            }

            // Mở lại một luồng đã đóng — xem docblock ở trên cho lý do và cho giới hạn cố ý của
            // nhánh này (chỉ hỏi lại lúc MỞ LẠI, không hỏi lại ở mọi lần đổi trạng thái khác).
            //
            // Hai biến, không một: `$unassignedAssignee` (cho MÀN HÌNH, qua kết quả trả về — có
            // thể `null` nếu chính hàng `users` không còn tồn tại, một ca không thể xảy ra qua
            // ứng dụng vì FK + xoá mềm, nhưng kiểu vẫn khai `?User` để không giả định điều đó) và
            // `$unassignedPreviousAssigneeId` (cho NHẬT KÝ, luôn có giá trị khi có gỡ, vì nó đọc
            // TRƯỚC khi `$assignee` được tìm — một hàng nhật ký không được phép rỗng `from` chỉ vì
            // `User::find()` tình cờ trả về `null`).
            $unassignedAssignee = null;
            $unassignedPreviousAssigneeId = null;

            if ($previous === ClientRequestStatus::Closed
                && $status !== ClientRequestStatus::Closed
                && $thread->assigned_to !== null) {
                $previousAssigneeId = $thread->assigned_to;
                $assignee = User::withTrashed()->find($previousAssigneeId);

                if ($assignee === null || ! $this->canHoldTheThread($assignee, $matter)) {
                    $unassignedAssignee = $assignee;
                    $unassignedPreviousAssigneeId = $previousAssigneeId;
                    $thread->assigned_to = null;
                }
            }

            $thread->save();

            Audit::record('client_request_status_changed', $thread, [
                'matter_id' => $thread->matter_id,
                'client_id' => $matter->client_id,
                'from' => $previous->value,
                'to' => $status->value,
            ], causer: $actor);

            if ($unassignedPreviousAssigneeId !== null) {
                // Tên sự kiện GIỐNG {@see self::assign()}: một lần rà soát "ai từng giữ luồng
                // này" đọc được bằng một truy vấn trên cột `event`, không cần biết trước lần gỡ
                // nào đến từ giao việc tay và lần nào đến từ đây.
                Audit::record('client_request_assigned', $thread, [
                    'matter_id' => $thread->matter_id,
                    'client_id' => $matter->client_id,
                    'from' => $unassignedPreviousAssigneeId,
                    'to' => null,
                ], causer: $actor);
            }

            return new SetClientRequestStatusResult($thread, $unassignedAssignee);
        });
    }

    /**
     * `matter_id` THẬT của một `client_requests`, đọc thẳng từ CSDL — KHÔNG bao giờ đọc từ thuộc
     * tính trên đối tượng `$request` mà caller đưa vào (có thể bị sửa trong bộ nhớ: test "reads
     * the thread back instead of trusting the object it was handed" dựng đúng ca một `$request`
     * bị sửa `matter_id` trỏ sang một vụ việc actor CÓ quyền, trong khi dòng thật thuộc một vụ
     * việc actor KHÔNG có quyền).
     *
     * **Phải gọi TRƯỚC KHI `DB::transaction()` mở, không phải bên trong (fix round 3, finding
     * I3 residual).** Đây KHÔNG chỉ là một câu `SELECT` "để biết khoá dòng nào" như round 2 tưởng
     * — nó còn là một CÂU ĐỌC KHÔNG KHOÁ (`value()`, không `lockForUpdate()`). Trên MariaDB, mức
     * cô lập REPEATABLE READ (mặc định InnoDB) cố định READ VIEW của một transaction tại LẦN ĐỌC
     * NHẤT QUÁN (không khoá) ĐẦU TIÊN của nó — các lần đọc khoá (`FOR UPDATE`) không cố định gì,
     * chúng luôn đọc dữ liệu MỚI NHẤT đã commit. Ở bản round 2, câu `value('matter_id')` là câu
     * đọc ĐẦU TIÊN bên trong `DB::transaction()`, tức nó KHOÁ SỚM cả READ VIEW của transaction đó
     * lại — TRƯỚC KHI `lockForUpdate()` trên `matters` kịp đợi/giành khoá. Hệ quả: nếu một
     * `RemoveTeamMember` khác đang giữ khoá `matters`, `assign()` phải đợi; khi được cấp khoá và
     * chạy tiếp, câu `Gate::forUser($assignee)->allows('update', $matter)` (một EXISTS không khoá
     * trên `matter_user`) vẫn đọc theo READ VIEW CŨ — cũ hơn cả lúc `RemoveTeamMember` COMMIT —
     * nên nó vẫn "thấy" người vừa bị gỡ còn trong đội ngũ và cho gán. Người rà soát đã tái hiện
     * đúng ca này trên container MariaDB 11.8 của dự án: còn câu đọc sớm này, `still_member=1`
     * sau khi gỡ xong; bỏ nó ra khỏi transaction, `still_member=0`.
     *
     * Gọi hàm này TRƯỚC `DB::transaction()` khiến nó chạy trong một câu lệnh auto-commit RIÊNG,
     * không thuộc transaction của `assign()`/`setStatus()` — nên nó không cố định gì cho READ
     * VIEW của transaction đó. An toàn để đọc SỚM: `client_requests.matter_id` không bao giờ đổi
     * sau khi tạo (không Action nào trong app/ sửa cột này), nên một giá trị đọc trước khi khoá
     * vẫn đúng khi khoá thật sự chạy — {@see self::open()} còn tự đối chiếu lại giá trị này với
     * `$thread->matter_id` (đọc dưới khoá) một lần nữa, phòng trường hợp không thể xảy ra hôm nay
     * nhưng có thể xảy ra nếu một Action tương lai lại sửa cột này.
     */
    private function realMatterId(ClientRequest $request): ?int
    {
        $matterId = $this->scopelessly(ClientRequest::query())->whereKey($request->getKey())->value('matter_id');

        return $matterId !== null ? (int) $matterId : null;
    }

    /**
     * Đọc lại hàng thật, nạp sẵn vụ việc bằng một truy vấn đã gỡ scope, rồi gác cổng.
     *
     * **Chỉ gọi được từ bên trong một transaction, và câu ĐẦU TIÊN bên trong nó phải là khoá
     * `matters`** — cùng thành ngữ `AddMatterDeadline`/`OpensDeadline::openMatterForDeadline()`
     * (khoá vụ việc là dòng đầu tiên của thân closure `DB::transaction`) và
     * `RemoveTeamMember`/`AddTeamMember` (đã rà lại ở fix round 3: cả hai Action đó KHÔNG có câu
     * đọc trần nào trước khoá `matters` bên trong transaction của chúng — mọi thứ đứng trước,
     * như `Gate::authorize('manageTeam', ...)`, chạy TRƯỚC `DB::transaction()` mở). Không câu đọc
     * trần nào (không `lockForUpdate()`) được đứng trước khoá này bên trong transaction — xem
     * {@see self::realMatterId()} cho lý do đầy đủ (fix round 3, finding I3 residual: một câu đọc
     * trần đứng trước sẽ cố định READ VIEW REPEATABLE READ của transaction TRƯỚC khi khoá kịp
     * đợi/giành, khiến các câu đọc trần SAU khoá — ví dụ `Gate::allows('update', $matter)` — vẫn
     * thấy dữ liệu CŨ dù vừa đợi xong một transaction khác vừa commit).
     *
     * `$matterId` do {@see self::realMatterId()} tính SẴN, TRƯỚC transaction — xem docblock hàm
     * đó. Đối chiếu lại với `$thread->matter_id` (đọc dưới khoá) ngay dưới: không tin
     * `$matterId` mù quáng dù nó đến từ một hàm "đáng tin" hơn `$request->matter_id` — cột này
     * hôm nay bất biến nên hai giá trị luôn khớp, nhưng đối chiếu là một câu `if` gần như miễn phí
     * và là hàng rào cuối cùng nếu bất biến đó đổi.
     *
     * **Khoá `matters` TRƯỚC `client_requests`, thứ tự CỐ Ý (fix round 2, finding I3 residual).**
     * Cùng THỨ TỰ TOÀN CỤC mà `RemoveTeamMember`/`AddTeamMember`/`OpensDeadline::
     * openMatterForDeadline()` dùng (vụ việc trước, bảng con sau) — khoá theo hai chiều khác nhau
     * ở hai Action tranh chấp là công thức deadlock kinh điển, xem lý lẽ `OpenMatter::
     * lockClients()` đã ghi cho đúng vấn đề này giữa các dòng `clients`.
     *
     * `setRelation('matter', ...)` TRƯỚC khi `Gate` chạm vào đối tượng, cùng lý do đã đo ở M4:
     * một quan hệ nạp lười chạy dưới guard NÀO ĐANG MỞ, nên với một phiên portal đang mở trong
     * cùng trình duyệt (chuyện thường ngày lúc demo) `$request->matter` trả `null` và một nhân sự
     * đủ quyền bị từ chối oan.
     *
     * @return array{0: ClientRequest, 1: Matter}
     */
    private function open(ClientRequest $request, User $actor, ?int $matterId): array
    {
        $matter = $matterId !== null
            ? $this->scopelessly(Matter::query())->lockForUpdate()->find($matterId)
            : null;

        $thread = $this->scopelessly(ClientRequest::query())
            ->lockForUpdate()
            ->find($request->getKey()) ?? $this->refuse();

        if ((int) $thread->matter_id !== $matterId) {
            $this->refuse();
        }

        $thread->setRelation('matter', $matter);

        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        // Hồ sơ đã xoá mềm: `Matter::query()` loại nó, nên lần đọc ngay trên trả `null`.
        //
        // **Câu này KHÔNG đỏ được một mình, và nói thẳng ra thay vì để nó trông như một cổng.**
        // `ClientRequestPolicy::update` uỷ cho `ChecksMatterAccess::canUpdateMatter()`, và hàm
        // đó tự hỏi `$matter !== null` — nên xoá dòng này đi thì một hồ sơ đã xoá mềm vẫn bị từ
        // chối, bằng đúng câu ấy, một dòng bên dưới (đo bằng mutation probe ở vòng rà soát
        // 21/09/2026: probe SỐNG SÓT). Nó ở lại vì nó là thứ bảo đảm KIỂU cho
        // `@return array{1: Matter}` và cho `$matter->client_id` ở cả hai nơi gọi: không có nó,
        // tính đúng của hai lời gọi `Audit::record()` phụ thuộc vào một câu `null` nằm bên trong
        // một policy khác. Cùng cách ghi mà `ReportsActionFailures::failWithFieldErrors()` dùng
        // cho nhánh không đỏ được của nó.
        if ($matter === null) {
            $this->refuse();
        }

        // `ClientRequestPolicy::update` — tức `MatterPolicy::update`, không phải "thấy được vụ
        // việc". Kế toán không có `matter.update` nên không nhận, không giao và không đổi trạng
        // thái yêu cầu của khách (SPEC §5).
        if (Gate::forUser($actor)->inspect('update', $thread)->denied()) {
            $this->refuse();
        }

        return [$thread, $matter];
    }

    /**
     * **Mọi lý do, MỘT câu** — SPEC §10.10, và §10.10 không chừa ngoại lệ cho người trong văn
     * phòng: M3 đã áp đúng luật này cho cả panel nội bộ với chính ví dụ kế toán
     * (`AnswerDeniedPanelRequestsWithNotFound`). Yêu cầu không tồn tại, thuộc một vụ việc người
     * hỏi không mở được, vụ việc đã bị xoá mềm, tài khoản đã bị vô hiệu hoá: cùng một câu.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('requests.unavailable'));
    }
}
