<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Push\SendPushAlert;
use App\Enums\ClientRequestStatus;
use App\Enums\PushTopic;
use App\Events\ClientRequestAnswered;
use App\Exceptions\ClientRequestNotOpen;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\ClientRequestFollowUpAlert;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Viết thêm một dòng vào một cuộc trao đổi đang mở — SPEC §4.14 (`client_request_replies`),
 * §7.2 ("hộp thư của vụ việc"), §8.3 mục 7 ("xem lại lịch sử trao đổi").
 *
 * # MỘT Action cho cả hai phía, và vì sao không phải hai
 *
 * Bảng `client_request_replies` có `author_type`/`author_id` là một morph chính vì cả khách lẫn
 * nhân sự đều viết vào đó. Câu hỏi thật là: một Action nhận `User|ClientUser`, hay hai Action
 * cạnh nhau? Chọn **một**, vì ba thứ mà hai Action sẽ phải giữ cho giống nhau đều là những thứ
 * đã từng lệch trong dự án này:
 *
 *  1. **Bất biến được giữ chỉ có MỘT.** "Một dòng trả lời thuộc đúng một cuộc trao đổi, và cuộc
 *     trao đổi đó là cuộc trao đổi mà người viết được phép ghi vào." Hai phía là hai NHÁNH của
 *     một câu hỏi phân quyền, và câu hỏi ấy đã được phát biểu đúng một lần ở
 *     `ClientRequestReplyPolicy::create()`. Hai Action sẽ hỏi hai câu, và không có gì giữ cho
 *     chúng nói cùng một luật.
 *  2. **SPEC §10.10 đòi hai phía từ chối GIỐNG NHAU.** Một câu duy nhất, một lớp exception duy
 *     nhất ({@see self::refuse()}). Hai Action nghĩa là hai bản sao của câu đó, và bản sao thứ
 *     hai là bản sẽ trôi — đúng hình dạng lỗi mà `ChecklistItemNotReviewable::unavailable()`
 *     phải gom lại ở M4.
 *  3. **Cổng TRẠNG THÁI là một, và nó thuộc về cuộc trao đổi, không thuộc về người viết.** Một
 *     yêu cầu đã đóng thì không ai viết thêm — kể cả trưởng phòng. Xem {@see ClientRequestNotOpen}.
 *
 * Những chỗ hai phía THẬT SỰ khác nhau đều đứng một mình và có tên, và mỗi cái có test ở cả hai
 * nhánh: cổng quyền ({@see self::authorize()}), hệ quả lên trạng thái
 * ({@see self::advanceStatus()}), và tên sự kiện nhật ký (một biểu thức ba ngôi ngay tại lời gọi
 * `Audit::record()`, vì nó là một lần chọn chữ chứ không phải một nhánh xử lý). Không có nhánh
 * nào ẩn trong một câu điều kiện giữa thân hàm.
 *
 * # Trạng thái đi theo cuộc trao đổi, không phải theo một cái nút
 *
 * Bốn trạng thái của SPEC §4.14 chỉ có nghĩa nếu chúng nói đúng chuyện đang xảy ra:
 *
 *  - **Nhân sự trả lời ⇒ `answered`, và `answered_at` được đóng dấu LẦN ĐẦU.** Văn phòng vừa trả lời thì cuộc
 *    trao đổi ĐÃ được trả lời; bắt người trả lời bấm thêm một nút nữa để nói điều đó là cách chắc
 *    chắn nhất để hộp thư đầy những dòng `in_progress` đã xong từ lâu. Nhân sự vẫn đổi tay được
 *    (về `in_progress`, hoặc sang `closed`) bằng thao tác riêng ở hộp thư.
 *  - **Khách viết tiếp vào một yêu cầu `answered` ⇒ quay về `in_progress`.** Chữ "đã trả lời"
 *    khẳng định văn phòng đã trả lời *câu hỏi đang treo*; khách vừa hỏi một câu mới, nên lời
 *    khẳng định đó không còn đúng. Không quay về thì một câu hỏi mới nằm im dưới một cái nhãn
 *    nói rằng không còn gì phải làm.
 *  - **Khách viết tiếp vào `new` ⇒ vẫn `new`.** Chưa ai trong văn phòng xem, và việc khách viết
 *    thêm không làm điều đó thành có. `in_progress` thì giữ nguyên `in_progress`: đã có người
 *    nhận, và họ vẫn đang giữ.
 *
 * # `answered_at` là LẦN ĐẦU văn phòng trả lời, và nó không bao giờ dịch đi
 *
 * Phán quyết vòng rà soát 21/09/2026, sau khi hai docblock trong cùng một commit nói hai điều
 * trái nhau — bản đầu của {@see self::advanceStatus()} dập lại mốc ở mỗi câu trả lời và nói cột
 * đó nghĩa là "lần gần nhất", còn {@see TriageClientRequest::setStatus()} giữ giá trị cũ và nói
 * nó nghĩa là "lần đầu". Không test nào phân biệt được hai nghĩa, nên đây là một cột có hai định
 * nghĩa chứ không phải một quyết định.
 *
 * Nghĩa đã chọn là **lần đầu**, vì hai lý do: đó là giá trị mà một báo cáo thời hạn phản hồi
 * dùng được (bao lâu thì khách nhận được câu trả lời ĐẦU TIÊN — câu trả lời thứ ba không nói gì
 * về việc đó), và vì tên cột là số ít. Hệ quả ở cả ba đường vào: một câu trả lời thứ hai không
 * chạm vào mốc; khách viết tiếp **không xoá** mốc (lần trả lời kia đã thật sự xảy ra); và
 * {@see TriageClientRequest::setStatus()} cũng chỉ đóng dấu khi cột còn trống.
 *
 * Nếu một ngày văn phòng cần "lần gần nhất văn phòng trả lời", đó là một CỘT MỚI — cột này đã có
 * một nghĩa và một test ghim nó.
 *
 * # Không đọc `auth()`, không tin tham số
 *
 * Cùng kỷ luật với {@see OpenClientRequest} và `SubmitClientDocument`: `$actor` tường minh,
 * `$request` được đọc lại từ cơ sở dữ liệu bằng một truy vấn đã gỡ `ClientPortalScope`, và quan
 * hệ `matter` được nạp sẵn bằng một truy vấn cũng đã gỡ scope **trước khi** đối tượng được đưa
 * cho `Gate`. Lý do cho câu cuối đã đo được ở M4: một quan hệ nạp lười chạy dưới guard NÀO ĐANG
 * MỞ, nên với một phiên portal của khách hàng khác đang mở, `$request->matter` trả `null` và một
 * lần ghi hợp lệ bị từ chối oan. Chiều ngược lại vẫn an toàn — `ChecksPortalVisibility` hỏi lại
 * scope thật qua `ClientPortalScope::actingAs($actor)`.
 *
 * # Nhật ký, hai phía, hai tên sự kiện
 *
 * `client_request_replied_by_client` và `client_request_answered_by_staff`. Hai tên chứ không một
 * tên kèm một thuộc tính, vì một lần rà soát "văn phòng đã trả lời những gì" phải viết được bằng
 * một truy vấn trên cột `event` — cùng lý lẽ mà {@see Audit} đã phát biểu cho cặp
 * `document_published` / `document_submitted`. `$causer` luôn truyền tường minh: `Audit::record()`
 * ưu tiên guard `web`, nên một dòng trả lời của khách trên một máy đang mở cả hai panel sẽ bị ghi
 * tên một nhân sự nếu để helper tự suy.
 */
class ReplyToClientRequest
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** Cùng trần với nội dung yêu cầu, cùng lý do — xem {@see OpenClientRequest::CONTENT_MAX}. */
    public const CONTENT_MAX = 5000;

    /**
     * Vòng sửa cuối M12 (I7): khung GOM thông báo đẩy của câu hỏi tiếp — xem
     * {@see self::followUpRingsAgain()}.
     */
    public const FOLLOW_UP_PUSH_QUIET_MINUTES = 10;

    /**
     * **M6 Task 4 (`requests/REQ-2`): thông báo trong hệ thống khi KHÁCH viết thêm.** Sau khi
     * transaction commit — không bao giờ bên trong nó, xem docblock {@see self::
     * notifyHolderOfFollowUp()} cho lý do bắt buộc (luật kiến trúc cấm `->notify(` bên trong
     * `DB::transaction()` ở `app/Actions`) — một lần khách viết thêm vào MỘT LUỒNG BẤT KỲ (không
     * riêng ca `answered → in_progress` mà SPEC nêu làm ví dụ điển hình) báo cho người đang giữ
     * luồng đó, hoặc luật sư phụ trách nếu chưa ai giữ. Quyết định của implementer: brief chỉ nêu
     * đích danh ca `answered → in_progress` (văn phòng tưởng đã xong), nhưng thu hẹp điều kiện lại
     * đúng ca đó sẽ để lọt đúng lỗ hổng ấy ở hai trạng thái còn lại (`new`, `in_progress`) — một
     * câu hỏi tiếp mà chưa ai xử lý cũng cần được biết, không riêng câu hỏi tiếp vào một việc
     * tưởng đã xong. Bọc `try/catch` để một notification hỏng không biến câu trả lời ĐÃ LƯU THÀNH
     * CÔNG của khách thành một lỗi 500 — cùng tinh thần R2 ("luật sư bấm nút không bao giờ thấy
     * lỗi máy chủ chỉ vì máy chủ thư chết", áp dụng cho notification chứ không riêng thư).
     */
    public function handle(ClientRequest $request, User|ClientUser $actor, string $content): ClientRequestReply
    {
        // **Cả lần đọc lẫn lần ghi trong MỘT transaction, và hàng được khoá.** Bản đầu đọc cuộc
        // trao đổi ở ngoài `DB::transaction()`, nên giữa lúc cổng trạng thái nói "đang mở" và
        // lúc dòng trả lời được ghi, một đồng nghiệp bấm "Đã đóng" ở tab bên cạnh vẫn chen vào
        // được: `advanceStatus()` sau đó ghi đè `closed` bằng `answered` và mở lại đúng cuộc
        // trao đổi mà cái nút kia vừa đóng — im lặng, và bằng chính cái exception tồn tại để
        // chặn việc đó. Cùng thành ngữ `RegroupDocument` và `PublishDocument` dùng: đọc lại kèm
        // `lockForUpdate()` bên trong transaction sẽ ghi.
        //
        // Bộ test chạy SQLite, nơi `lockForUpdate()` được biên dịch thành không gì cả — nên
        // không test nào ĐỎ được vì thiếu nó, y như `PublishDocument` đã ghi. Thứ test giữ được
        // là kết quả của một lần chạy tuần tự; phần khoá là một lập luận về MariaDB.
        $reply = DB::transaction(function () use ($request, $actor, $content): ClientRequestReply {
            $thread = $this->scopelessly(ClientRequest::query())
                ->lockForUpdate()
                ->find($request->getKey()) ?? $this->refuse();

            // Quan hệ `matter` nạp sẵn, không scope, TRƯỚC khi `Gate` chạm vào đối tượng — xem
            // docblock lớp. `Matter::query()` loại hồ sơ đã xoá mềm, nên một hồ sơ đã xoá cho
            // `null` ở đây và cổng quyền ngay dưới từ chối.
            $thread->setRelation('matter', $this->scopelessly(Matter::query())->find($thread->matter_id));

            // SPEC §10.9 cho khách, và cùng lập luận cho nhân sự: một tài khoản đã bị vô hiệu
            // hoá hoặc xoá mềm không ghi thêm được gì. Xem {@see ChecksAccountActive}.
            if (! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            $this->authorize($actor, $thread);

            // Cổng TRẠNG THÁI chạy SAU cổng quyền, và thứ tự đó là một luật về rò rỉ thông tin:
            // câu "cuộc trao đổi này đã kết thúc" nói ra một sự thật về một bản ghi, nên nó chỉ
            // được nói với người đã được xác nhận là đọc được bản ghi ấy. Đảo thứ tự lại là dựng
            // một máy dò sự tồn tại cho người ngoài (SPEC §10.10). Hai câu chứ không một, vì hai
            // người đọc ngồi ở hai màn hình khác nhau — xem {@see ClientRequestNotOpen}.
            if (! ClientRequestNotOpen::accepts($thread->status)) {
                throw $actor instanceof ClientUser
                    ? ClientRequestNotOpen::closed()
                    : ClientRequestNotOpen::closedForStaff();
            }

            $content = $this->validated($content);

            $reply = ClientRequestReply::query()->create([
                'request_id' => $thread->getKey(),
                'author_type' => $actor->getMorphClass(),
                'author_id' => $actor->getKey(),
                'content' => $content,
            ]);

            $this->advanceStatus($thread, $actor, $reply);

            Audit::record(
                $actor instanceof ClientUser
                    ? 'client_request_replied_by_client'
                    : 'client_request_answered_by_staff',
                $reply,
                [
                    'client_request_id' => $thread->getKey(),
                    'matter_id' => $thread->matter_id,
                    // Chép thẳng thay vì để người đọc suy qua `matter`: `matters.client_id` là
                    // một cột sửa được, nên một hồ sơ chuyển sang khách hàng khác sẽ viết lại
                    // lịch sử của mọi lần trao đổi đã xảy ra.
                    'client_id' => $thread->matter?->client_id,
                ],
                causer: $actor,
            );

            return $reply;
        });

        // REQ-2 — NGOÀI transaction, chỉ khi KHÁCH vừa viết. Xem docblock {@see self::handle()}
        // ngay trên cho phạm vi (mọi trạng thái, không riêng `answered → in_progress`) và
        // {@see self::notifyHolderOfFollowUp()} cho lý do bắt buộc phải đứng ngoài đây.
        if ($actor instanceof ClientUser) {
            $this->notifyHolderOfFollowUp($reply);
        }

        return $reply;
    }

    /**
     * **Hai phía, hai luật — và cả hai đi qua ĐÚNG MỘT ability.**
     *
     * `ClientRequestReplyPolicy::create()` là nơi luật được phát biểu; ở đây chỉ có lời gọi, và
     * lời gọi ấy **luôn kèm cuộc trao đổi**. Nhánh không-ngữ-cảnh của ability đó chỉ trả lời câu
     * hỏi giao diện ("loại tài khoản này nói chung có viết trả lời được không") và với một
     * `ClientUser` nó là `true` vô điều kiện — hỏi trống ở đây sẽ là một cái cổng luôn mở, đúng
     * hình dạng lỗ hổng `DocumentPolicy::create()` mà M4 phải vá.
     *
     * `inspect()` chứ không `authorize()`: `Gate::authorize()` ném "This action is unauthorized.",
     * một câu tiếng Anh viết cho lập trình viên, và SPEC §8 cấm đúng kiểu câu đó trên màn hình
     * khách hàng. Câu thay thế đi qua {@see self::refuse()}, giống hệt cho cả hai phía.
     */
    private function authorize(User|ClientUser $actor, ClientRequest $thread): void
    {
        if (Gate::forUser($actor)->inspect('create', [ClientRequestReply::class, $thread])->denied()) {
            $this->refuse();
        }
    }

    /**
     * Hệ quả của một dòng trả lời lên trạng thái cuộc trao đổi. Lý lẽ đầy đủ ở docblock lớp;
     * ở đây là bảng thật, đứng một mình để nó đọc được như một luật chứ không như một chuỗi `if`.
     *
     * `saveQuietly()` **không** được dùng: `ClientRequest` không bật `LogsActivity`, nên một lần
     * `save()` thường không sinh dòng nhật ký nào — dấu vết của việc này là dòng `Audit` mà
     * {@see self::handle()} ghi ngay sau đó, và nó nói đúng chuyện đã xảy ra ("ai vừa viết gì")
     * thay vì "cột `status` đổi từ X sang Y".
     *
     * **Luôn `save()`, kể cả khi `status` không đổi (Task 18, REQ-2).** Bản trước hàm này không
     * ghi gì khi khách viết tiếp vào một luồng `new` hoặc `in_progress` — đúng cho CỘT
     * `status`, nhưng `last_activity_at` phải nhảy ở CẢ BỐN đường hoạt động, và "khách hỏi tiếp"
     * là một trong bốn dù trạng thái có đổi hay không. Không có dòng `save()` này, một luồng
     * `in_progress` nhận thêm câu hỏi vẫn nằm nguyên chỗ cũ trong hộp thư sắp theo hoạt động gần
     * nhất — đúng cái lỗ mà `ClientRequestsRelationManager` tồn tại để lấp.
     *
     * **M6 Task 4 (`requests/REQ-4`): bắn `ClientRequestAnswered` khi trạng thái THẬT SỰ chuyển
     * vào `answered`.** `$previousStatus` được chụp TRƯỚC khi cột bị ghi đè — một câu trả lời thứ
     * hai vào một luồng ĐÃ `answered` không bắn lại sự kiện (đúng chữ SPEC "đổi luồng SANG
     * answered", và `answered_at` cũng không dịch đi ở nhánh này — hai điều cùng nói một sự
     * thật: lần trả lời ĐẦU TIÊN mới là sự kiện). Dispatch BÊN TRONG transaction là đúng, không
     * phải một ngoại lệ của luật kiến trúc: sự kiện `ShouldDispatchAfterCommit` (không phải
     * `Mail::`/`->notify(` trực tiếp) — Laravel tự hoãn nó tới lúc commit, cùng cách
     * `SubmitClientDocument`/`PublishDocument` đã làm.
     */
    private function advanceStatus(ClientRequest $thread, User|ClientUser $actor, ClientRequestReply $reply): void
    {
        $thread->last_activity_at = now();

        // Văn phòng vừa trả lời. `??=` chứ không `=`: cột `answered_at` là LẦN ĐẦU văn phòng trả
        // lời và nó không bao giờ dịch đi — xem docblock lớp. Câu trả lời thứ hai đặt lại trạng
        // thái `answered` (khách có thể đã kéo nó về `in_progress` bằng một câu hỏi tiếp) nhưng
        // không chạm vào mốc đó.
        if ($actor instanceof User) {
            $previousStatus = $thread->status;

            $thread->status = ClientRequestStatus::Answered;
            $thread->answered_at ??= now();
            $thread->save();

            if ($previousStatus !== ClientRequestStatus::Answered) {
                ClientRequestAnswered::dispatch($reply);
            }

            return;
        }

        // Khách vừa hỏi tiếp vào một việc văn phòng tưởng đã xong. `answered_at` KHÔNG bị xoá:
        // văn phòng đã trả lời thật, vào lúc đó. `new` và `in_progress` không đổi — khách viết
        // thêm không làm cho ai đó trong văn phòng đã xem, và cũng không gỡ việc khỏi tay người
        // đang giữ — nhưng cả hai vẫn `save()` được ở trên vì `last_activity_at` luôn phải ghi.
        if ($thread->status === ClientRequestStatus::Answered) {
            $thread->status = ClientRequestStatus::InProgress;
        }

        $thread->save();
    }

    /**
     * `requests/REQ-2`: khách vừa viết thêm — báo cho người ĐANG GIỮ luồng (`assigned_to`), hoặc
     * luật sư phụ trách vụ việc nếu chưa ai giữ. `$preferred = [assigned_to ?? luật sư phụ
     * trách]` (phán quyết controller, task-4-brief.md) — MỘT ứng viên, khác danh sách ba phần tử
     * dùng cho `staff.new_client_request`/`staff.new_client_document`: một câu hỏi tiếp không cần
     * báo lại CẢ đội ngũ trợ lý đã được báo lúc luồng mới mở, chỉ người đang thật sự xử lý nó.
     *
     * **KHÔNG có mặt bên trong transaction của {@see self::handle()}.** Đọc lại `$thread`/
     * `$matter` TƯƠI, sau khi commit: `ResolveStaffRecipients` cần `Gate::view()` chạy trên dữ
     * liệu ĐÃ commit (đội ngũ vụ việc có thể vừa đổi ở một transaction khác), và quan trọng hơn,
     * `->notify(` là lời gọi bị `tests/Feature/ArchitectureTest.php` cấm chạy bên trong
     * `DB::transaction()` ở `app/Actions` — gọi nó bên trong sẽ là một luật kiến trúc bị phá.
     *
     * `try/catch(Throwable)`: xem docblock {@see self::handle()}. Notification chỉ là một câu
     * INSERT (kênh `database`, không `ShouldQueue` — không chạm mạng), nhưng nếu nó hỏng vì một
     * lý do bất kỳ (ví dụ một Notification channel khác được bật thêm trong tương lai), khách vừa
     * gửi câu hỏi thành công không được phép thấy trang lỗi.
     *
     * **M12 — thông báo đẩy** (kế hoạch M12 R10: hàng `staff.new_client_request`, "kể cả khách hỏi
     * tiếp"). Câu hỏi tiếp KHÔNG có thư, nên push đi cùng thông báo trong hệ thống này: ĐÚNG
     * collection `$recipients` ở trên (một định nghĩa người nhận — R10), sau vòng `->notify(`, cùng
     * khối `try`; chủ đề yêu cầu mới, bản ghi là LUỒNG (`tag` theo luồng: câu hỏi tiếp thay tin cũ của
     * cùng luồng trên màn hình khoá). Gọi sau commit như vòng thông báo (`SendPushAlert` không ở trong
     * transaction nào). Một `->notify(` ném thì vòng dừng và không ai nhận push — cùng số phận với
     * những người còn lại của vòng thông báo. Mỗi câu hỏi tiếp là một sự kiện, chạy đúng một lần trong
     * request của khách. `SendPushAlert` không ném vì lỗi lúc chạy.
     *
     * **Gom push theo luồng (vòng sửa cuối M12, I7).** Từ Task 9 vòng sửa 1 mọi push cùng `tag` đều
     * rung (`renotify`), nên N câu hỏi tiếp liên tiếp của một tài khoản khách — hay của một tài khoản
     * bị chiếm, kể cả nửa đêm — là N lần máy luật sư rung. Thông báo trong hệ thống vẫn đi MỖI lần;
     * push chỉ đi khi {@see self::followUpRingsAgain()} nói đây là đầu một đợt mới.
     */
    private function notifyHolderOfFollowUp(ClientRequestReply $reply): void
    {
        try {
            $thread = $this->scopelessly(ClientRequest::query())->find($reply->request_id);

            if ($thread === null) {
                return;
            }

            $matter = $this->scopelessly(Matter::query())->find($thread->matter_id);

            if ($matter === null) {
                return;
            }

            $holder = $thread->assignee ?? $matter->leadLawyer;
            $recipients = app(ResolveStaffRecipients::class)->handle($matter, [$holder]);

            foreach ($recipients as $recipient) {
                $recipient->notify(new ClientRequestFollowUpAlert($reply));
            }

            // M12: push cho ĐÚNG những người vừa nhận thông báo này, trừ khi câu này còn nằm trong một
            // đợt câu hỏi tiếp đã rung — xem docblock hàm.
            if ($this->followUpRingsAgain($thread, $reply)) {
                app(SendPushAlert::class)->handle($recipients, PushTopic::StaffNewClientRequest, $thread);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Câu hỏi tiếp `$reply` có mở một ĐỢT mới (đẩy, máy rung) không — vòng sửa cuối M12, I7. Nhìn LỜI
     * LIỀN TRƯỚC trong luồng: dòng trả lời có id nhỏ hơn gần nhất; chưa có dòng nào thì là chính lời mở
     * luồng của khách (`client_requests.created_at` — lúc mở luồng đã có thư và push
     * `staff.new_client_request`).
     *  - Lời của VĂN PHÒNG → đẩy: khách đang trả lời văn phòng, đó là việc mới cho người giữ luồng.
     *  - Lời của KHÁCH cách đây chưa tới {@see self::FOLLOW_UP_PUSH_QUIET_MINUTES} phút → không đẩy: vẫn
     *    là đợt đang dồn; người giữ luồng đã được rung ở đầu đợt và chuông trong app vẫn đếm từng câu.
     *  - Lời của khách cũ hơn thế → đẩy.
     * Khung trượt theo lời liền trước, không theo lần đẩy trước: khách viết đều đặn mỗi vài phút cả buổi
     * là MỘT đợt (một lần rung), tới khi văn phòng trả lời hay khách ngừng đủ 10 phút. Đọc từ chính các
     * dòng của luồng — không bộ nhớ đệm, không trạng thái mới.
     */
    private function followUpRingsAgain(ClientRequest $thread, ClientRequestReply $reply): bool
    {
        $previous = $this->scopelessly(ClientRequestReply::query())
            ->where('request_id', $thread->getKey())
            ->where('id', '<', $reply->getKey())
            ->orderByDesc('id')
            ->first();

        if ($previous !== null && $previous->author_type !== (new ClientUser)->getMorphClass()) {
            return true;
        }

        $clientSpokeAt = $previous?->created_at ?? $thread->created_at;

        return $clientSpokeAt === null
            || $clientSpokeAt->lte(now()->subMinutes(self::FOLLOW_UP_PUSH_QUIET_MINUTES));
    }

    /**
     * Xác thực ở Action, không chỉ ở ô nhập — cùng lý lẽ với
     * {@see OpenClientRequest::validated()}. Khoá là tên TRẦN `content`, trùng tên thuộc tính
     * Livewire của trang cổng và tên ô trong modal trả lời của hộp thư nội bộ.
     */
    private function validated(string $content): string
    {
        $values = ['content' => trim($content)];

        Validator::make(
            $values,
            ['content' => ['required', 'string', 'max:'.self::CONTENT_MAX]],
            [
                'content.required' => __('requests.validation.content_required'),
                'content.max' => __('requests.validation.content_max', ['max' => self::CONTENT_MAX]),
            ],
        )->validate();

        return $values['content'];
    }

    /**
     * **Bốn tình huống, MỘT câu, và câu đó GIỐNG NHAU ở cả hai phía** — SPEC §10.10. Cuộc trao
     * đổi không tồn tại, thuộc một hồ sơ người hỏi không được thấy, hồ sơ đã bị xoá mềm hoặc gỡ
     * khỏi cổng, và tài khoản đã bị vô hiệu hoá: cả bốn đi ra từ đúng dòng `throw` này.
     *
     * Việc hai phía dùng chung câu này là một trong ba lý do Action này không bị tách làm đôi —
     * xem docblock lớp.
     *
     * `AuthorizationException`: trang cổng đổi nó thành **404** bằng `abort(404)` (middleware
     * 404 của panel không phủ request cập nhật Livewire), còn hộp thư nội bộ đổi nó thành một
     * thông báo tiếng Việt qua `ReportsActionFailures`.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('requests.unavailable'));
    }
}
