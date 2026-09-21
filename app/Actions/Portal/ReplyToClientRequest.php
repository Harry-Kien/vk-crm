<?php

namespace App\Actions\Portal;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\ClientRequestStatus;
use App\Exceptions\ClientRequestNotOpen;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

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
 *  - **Nhân sự trả lời ⇒ `answered`, và `answered_at = now()`.** Văn phòng vừa trả lời thì cuộc
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
 * `answered_at` **không bị xoá** khi khách viết tiếp: nó là dấu thời gian văn phòng đã trả lời
 * lần gần nhất, một sự kiện đã xảy ra. Xoá nó đi là viết lại lịch sử để cho khớp với một cái
 * nhãn.
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

    public function handle(ClientRequest $request, User|ClientUser $actor, string $content): ClientRequestReply
    {
        $thread = $this->scopelessly(ClientRequest::query())->find($request->getKey()) ?? $this->refuse();

        // Quan hệ `matter` nạp sẵn, không scope, TRƯỚC khi `Gate` chạm vào đối tượng — xem
        // docblock lớp. `Matter::query()` loại hồ sơ đã xoá mềm, nên một hồ sơ đã xoá cho `null`
        // ở đây và cổng quyền ngay dưới từ chối.
        $thread->setRelation('matter', $this->scopelessly(Matter::query())->find($thread->matter_id));

        // SPEC §10.9 cho khách, và cùng lập luận cho nhân sự: một tài khoản đã bị vô hiệu hoá
        // hoặc xoá mềm không ghi thêm được gì. Xem {@see ChecksAccountActive}.
        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        $this->authorize($actor, $thread);

        // Cổng TRẠNG THÁI chạy SAU cổng quyền, và thứ tự đó là một luật về rò rỉ thông tin: câu
        // "cuộc trao đổi này đã kết thúc" nói ra một sự thật về một bản ghi, nên nó chỉ được nói
        // với người đã được xác nhận là đọc được bản ghi ấy. Đảo thứ tự lại là dựng một máy dò
        // sự tồn tại cho người ngoài (SPEC §10.10).
        if (! ClientRequestNotOpen::accepts($thread->status)) {
            throw ClientRequestNotOpen::closed();
        }

        $content = $this->validated($content);

        return DB::transaction(function () use ($thread, $actor, $content): ClientRequestReply {
            $reply = ClientRequestReply::query()->create([
                'request_id' => $thread->getKey(),
                'author_type' => $actor->getMorphClass(),
                'author_id' => $actor->getKey(),
                'content' => $content,
            ]);

            $this->advanceStatus($thread, $actor);

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
     */
    private function advanceStatus(ClientRequest $thread, User|ClientUser $actor): void
    {
        // Văn phòng vừa trả lời. `answered_at` được ghi ở đây và CHỈ ở đây — kể cả khi trạng
        // thái đã là `answered`, vì cột đó nói "lần gần nhất văn phòng trả lời", và một câu trả
        // lời thứ hai là một lần gần nhất mới.
        if ($actor instanceof User) {
            $thread->status = ClientRequestStatus::Answered;
            $thread->answered_at = now();
            $thread->save();

            return;
        }

        // Khách vừa hỏi tiếp vào một việc văn phòng tưởng đã xong. `answered_at` KHÔNG bị xoá:
        // văn phòng đã trả lời thật, vào lúc đó.
        if ($thread->status === ClientRequestStatus::Answered) {
            $thread->status = ClientRequestStatus::InProgress;
            $thread->save();
        }

        // `new` và `in_progress` không đổi, và không có lần `save()` nào: khách viết thêm không
        // làm cho ai đó trong văn phòng đã xem, và cũng không gỡ việc khỏi tay người đang giữ.
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
