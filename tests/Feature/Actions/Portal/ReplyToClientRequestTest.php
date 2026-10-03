<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Exceptions\ClientRequestNotOpen;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\Staff\ClientRequestFollowUpAlert;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Một Action, hai phía — SPEC §4.14 (`client_request_replies`), §7.2, §8.3 mục 7.
 *
 * Mỗi luật ở đây được đo **ở cả hai nhánh**, vì đó là toàn bộ lý do Action không bị tách làm
 * đôi: cổng quyền, hệ quả lên trạng thái, câu từ chối và dòng nhật ký phải nói cùng một luật cho
 * cả khách lẫn nhân sự, hoặc nói hai luật khác nhau một cách có chủ ý và được ghi ra.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->action = app(ReplyToClientRequest::class);

    $this->client = Client::factory()->create();
    $this->clientUser = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);
    $this->sibling = ClientUser::factory()->activated()->create(['client_id' => $this->client->id]);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->for($this->client)->create([
        'is_published_to_portal' => true,
        'lead_lawyer_id' => $this->lawyer->id,
    ]);

    $this->request = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::New,
    ]);
});

/** Mọi dòng trả lời trong bảng, kể cả của khách khác — đếm thật thay vì đếm qua scope. */
function allReplies(): Builder
{
    return ClientRequestReply::query()->withoutGlobalScope(ClientPortalScope::class);
}

function freshRequest(ClientRequest $request): ClientRequest
{
    return ClientRequest::query()->withoutGlobalScope(ClientPortalScope::class)->findOrFail($request->getKey());
}

// =========================================================================================
// HAI PHÍA VIẾT ĐƯỢC, VÀ MỖI PHÍA ĐƯỢC GHI ĐÚNG TÊN
// =========================================================================================

it('lets the client add to their own thread and records them as the author', function () {
    $reply = $this->action->handle($this->request, $this->clientUser, 'Tôi hỏi thêm một ý nữa ạ.');

    expect(allReplies()->count())->toBe(1)
        ->and($reply->request_id)->toBe($this->request->id)
        ->and($reply->author_type)->toBe($this->clientUser->getMorphClass())
        ->and($reply->author_id)->toBe($this->clientUser->id)
        ->and($reply->content)->toBe('Tôi hỏi thêm một ý nữa ạ.');
});

it('lets the office answer and records the staff member as the author', function () {
    $reply = $this->action->handle($this->request, $this->lawyer, 'Ngày hoà giải là 12/10, văn phòng đã nhận giấy mời.');

    expect(allReplies()->count())->toBe(1)
        ->and($reply->author_type)->toBe($this->lawyer->getMorphClass())
        ->and($reply->author_id)->toBe($this->lawyer->id);
});

/**
 * Phán quyết 19/09/2026, cách đọc theo `Client`: hai tài khoản portal của cùng một khách hàng
 * viết được vào cuộc trao đổi của nhau. Ghim ở đây để một lần đổi cách đọc là một quyết định,
 * không phải một lần trượt.
 */
it('lets a second portal account of the same client write into the first accounts thread', function () {
    $reply = $this->action->handle($this->request, $this->sibling, 'Tôi là vợ anh ấy, xin hỏi thêm.');

    expect($reply->author_id)->toBe($this->sibling->id)
        ->and(allReplies()->count())->toBe(1);
});

// =========================================================================================
// TRẠNG THÁI — bốn từ của SPEC §4.14 phải nói đúng chuyện đang xảy ra
// =========================================================================================

it('marks the thread answered and stamps answered_at when the office replies', function () {
    $this->travelTo('2026-09-21 10:30:00');

    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');

    $thread = freshRequest($this->request);

    expect($thread->status)->toBe(ClientRequestStatus::Answered)
        ->and($thread->answered_at->toDateTimeString())->toBe('2026-09-21 10:30:00');
});

/**
 * Vế dương và vế âm của cùng một luật, trong cùng một test: chữ "đã trả lời" khẳng định văn
 * phòng đã trả lời CÂU ĐANG TREO, nên một câu hỏi mới của khách làm lời khẳng định đó sai — và
 * `answered_at` KHÔNG bị xoá, vì lần trả lời kia đã thật sự xảy ra.
 */
it('takes an answered thread back to in progress when the client asks again, without erasing answered_at', function () {
    $this->travelTo('2026-09-21 10:30:00');
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');

    $this->travelTo('2026-09-22 08:00:00');
    $this->action->handle(freshRequest($this->request), $this->clientUser, 'Tôi còn một ý chưa rõ.');

    $thread = freshRequest($this->request);

    expect($thread->status)->toBe(ClientRequestStatus::InProgress)
        ->and($thread->answered_at->toDateTimeString())->toBe('2026-09-21 10:30:00');
});

/**
 * **`answered_at` là LẦN ĐẦU văn phòng trả lời, và nó không bao giờ dịch đi** — phán quyết vòng
 * rà soát 21/09/2026. Trước đó hai docblock trong cùng một commit nói hai điều trái nhau
 * (`ReplyToClientRequest` dập lại mốc mỗi lần, `TriageClientRequest` bảo nó là lần đầu) và không
 * test nào phân biệt được. Cột tên số ít, và một báo cáo thời hạn phản hồi chỉ dùng được mốc
 * đầu tiên; "lần trả lời gần nhất" nếu cần sẽ là một cột khác, không phải cột này.
 */
it('keeps answered_at at the first office answer and never moves it on a second one', function () {
    $this->travelTo('2026-09-21 10:30:00');
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');

    $this->travelTo('2026-09-23 09:00:00');
    $this->action->handle(freshRequest($this->request), $this->lawyer, 'Văn phòng nói thêm một ý.');

    $thread = freshRequest($this->request);

    expect($thread->status)->toBe(ClientRequestStatus::Answered)
        ->and($thread->answered_at->toDateTimeString())->toBe('2026-09-21 10:30:00')
        ->and(allReplies()->count())->toBe(2);
});

/**
 * Và mốc đó cũng không dịch đi khi khách chen vào giữa hai câu trả lời: chuỗi thật là
 * trả lời → khách hỏi tiếp → trả lời lần hai.
 */
it('keeps answered_at at the first office answer even when the client writes in between', function () {
    $this->travelTo('2026-09-21 10:30:00');
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');

    $this->travelTo('2026-09-22 08:00:00');
    $this->action->handle(freshRequest($this->request), $this->clientUser, 'Tôi còn một ý chưa rõ.');

    $this->travelTo('2026-09-22 15:00:00');
    $this->action->handle(freshRequest($this->request), $this->lawyer, 'Văn phòng trả lời tiếp.');

    expect(freshRequest($this->request)->answered_at->toDateTimeString())->toBe('2026-09-21 10:30:00');
});

it('leaves new and in progress alone when the client writes again', function () {
    foreach ([ClientRequestStatus::New, ClientRequestStatus::InProgress] as $status) {
        $thread = ClientRequest::factory()->for($this->matter)->create([
            'client_user_id' => $this->clientUser->id,
            'status' => $status,
        ]);

        $this->action->handle($thread, $this->clientUser, 'Tôi viết thêm.');

        expect(freshRequest($thread)->status)->toBe($status)
            ->and(freshRequest($thread)->answered_at)->toBeNull();
    }
});

// =========================================================================================
// CỔNG TRẠNG THÁI — một cuộc trao đổi đã đóng không nhận thêm chữ nào, từ BẤT KỲ ai
// =========================================================================================

/**
 * **Cùng một cổng, hai câu — vì hai người đọc ngồi ở hai màn hình khác nhau.** Câu của khách mời
 * họ "gửi một yêu cầu mới ở ô trên cùng", và cái ô đó chỉ tồn tại trên cổng khách hàng; in nguyên
 * văn nó vào panel nội bộ (nơi `ReportsActionFailures` vẽ thông báo) là chỉ một luật sư tới một
 * chỗ không có. Đây cũng là chỗ duy nhất `lang/vi/requests.php` từng vi phạm lời tự giới thiệu
 * của chính nó ("hai nửa KHÔNG dùng chung một chuỗi nào").
 */
it('refuses a closed thread on both sides, and gives each side the sentence written for it', function () {
    $closed = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Closed,
    ]);

    foreach ([
        [$this->clientUser, __('requests.portal.closed_notice')],
        [$this->lawyer, __('requests.tab.closed_notice')],
    ] as [$actor, $expected]) {
        try {
            $this->action->handle($closed, $actor, 'Còn một ý nữa.');
            $this->fail('Đáng lẽ phải ném ClientRequestNotOpen');
        } catch (ClientRequestNotOpen $exception) {
            expect($exception->getMessage())->toBe($expected);
        }
    }

    // Và hai câu đó KHÁC nhau: nếu một ngày ai đó trỏ cả hai về cùng một khoá thì test này đỏ,
    // chứ không âm thầm xanh vì hai vế bằng nhau.
    expect(__('requests.tab.closed_notice'))->not->toBe(__('requests.portal.closed_notice'))
        ->and(__('requests.tab.closed_notice'))->not->toContain('ô trên cùng')
        ->and(allReplies()->count())->toBe(0);
});

/**
 * Thứ tự hai cổng là một luật về rò rỉ thông tin (SPEC §10.10): câu "cuộc trao đổi đã kết thúc"
 * nói ra một sự thật về một bản ghi, nên nó chỉ được nói với người ĐỌC ĐƯỢC bản ghi ấy. Một
 * khách hàng khác phải nhận câu chung, không phải câu về trạng thái.
 */
it('never tells an outsider that a thread is closed', function () {
    $closed = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
        'status' => ClientRequestStatus::Closed,
    ]);

    $outsider = ClientUser::factory()->activated()->create();

    expect(fn () => $this->action->handle($closed, $outsider, 'Cho tôi xem'))
        ->toThrow(AuthorizationException::class, __('requests.unavailable'));
});

// =========================================================================================
// CỔNG QUYỀN — hai nhánh, hai luật, MỘT ability
// =========================================================================================

/**
 * **Nghĩa vụ "luôn hỏi kèm ngữ cảnh", đo bằng chính lỗ hổng nó nói tới.** Nhánh không-ngữ-cảnh
 * của `ClientRequestReplyPolicy::create()` trả `true` cho mọi `ClientUser`; Action không hỏi
 * trống, nên yêu cầu của khách hàng khác vẫn bị từ chối.
 */
it('asks the create ability with the thread, because the empty branch lets every client through', function () {
    $foreign = ClientRequest::factory()->for(Matter::factory()->create(['is_published_to_portal' => true]))->create();

    expect(Gate::forUser($this->clientUser)->allows('create', ClientRequestReply::class))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequestReply::class, $foreign]))->toBeFalse()
        ->and(fn () => $this->action->handle($foreign, $this->clientUser, 'Cho tôi xem'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(0);
});

/**
 * **Ngữ cảnh SAI KIỂU bị TỪ CHỐI, không được coi là "không có ngữ cảnh".** Kiểu khai báo của
 * `$context` là `mixed` một cách cố ý — một khai báo hẹp biến một lời gọi sai thành `TypeError`,
 * tức lỗi 500, thay vì một lời từ chối. Cái giá của lựa chọn đó là policy PHẢI tự phân biệt, và
 * nếu nó không phân biệt thì một ngữ cảnh sai kiểu rơi xuống nhánh `null` và thành một lời ĐỒNG
 * Ý vô điều kiện cho mọi khách.
 *
 * Ba vế trong một test, vì chúng chỉ có nghĩa cạnh nhau: nhánh trống `true`, ngữ cảnh đúng kiểu
 * `true`, ngữ cảnh sai kiểu `false`.
 */
it('refuses a wrongly typed context instead of treating it as no context', function () {
    expect(Gate::forUser($this->clientUser)->allows('create', ClientRequestReply::class))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequestReply::class, $this->request]))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequestReply::class, $this->matter]))->toBeFalse()
        ->and(Gate::forUser($this->lawyer)->allows('create', [ClientRequestReply::class, $this->matter]))->toBeFalse();
});

/**
 * SPEC §5: kế toán không có `matter.update`, nên họ không trả lời khách được — trả lời là GHI
 * vào vụ việc. Vế dương đứng ngay cạnh: một luật sư trong đội ngũ thì viết được, nên test này
 * đo đúng quyền chứ không đo một màn hình hỏng.
 */
it('refuses a staff member without matter update, and lets a team lawyer through', function () {
    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => $this->action->handle($this->request, $accountant, 'Tôi trả lời thay'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(0);

    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời.');

    expect(allReplies()->count())->toBe(1);
});

/**
 * Vế còn lại của cùng một luật: một luật sư KHÔNG có tên trong đội ngũ của vụ việc cũng không
 * viết được — `ClientRequestPolicy::update()` uỷ cho `MatterPolicy::update`, nơi điều kiện đội
 * ngũ được phát biểu một lần.
 */
it('refuses a lawyer who is not on the matter team, and lets an assistant who is', function () {
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    expect(fn () => $this->action->handle($this->request, $outsider, 'Tôi trả lời hộ'))
        ->toThrow(AuthorizationException::class);

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);

    $this->action->handle($this->request, $assistant, 'Trợ lý trả lời.');

    expect(allReplies()->count())->toBe(1);
});

it('refuses a client of another client organisation', function () {
    $outsider = ClientUser::factory()->activated()->create();

    expect(fn () => $this->action->handle($this->request, $outsider, 'Cho tôi xem'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(0);
});

it('refuses a soft deleted thread on both sides', function () {
    $this->request->delete();

    foreach ([$this->clientUser, $this->lawyer] as $actor) {
        expect(fn () => $this->action->handle($this->request, $actor, 'Viết thêm'))
            ->toThrow(AuthorizationException::class);
    }

    expect(allReplies()->count())->toBe(0);
});

/**
 * **Cùng luật, hỏi thẳng ở TẦNG POLICY — và đó là chỗ duy nhất nó được đo.**
 *
 * Test ngay trên đi qua Action, và ở đó lần đọc lại (`ClientRequest::query()->find()`, còn
 * nguyên `SoftDeletingScope`) đã từ chối trước khi `Gate` được hỏi. Đo bằng mutation: xoá
 * `! $context->trashed()` khỏi `ClientRequestReplyPolicy::create()` thì test trên **vẫn xanh**.
 *
 * Nhưng policy phải đúng một mình, vì nó là thứ một màn hình hay một caller khác sẽ hỏi. Nhánh
 * NHÂN SỰ là chỗ nó cắn: nó uỷ cho `ClientRequestPolicy::update()`, và hàm đó **không** hỏi
 * `trashed()` — nó chỉ hỏi `MatterPolicy::update` về vụ việc CHA, thứ vẫn còn nguyên. Nên không
 * có câu ấy, `Gate` nói CÓ về một yêu cầu đã rút. Vế khách thì đã được `ClientRequestPolicy::view()`
 * giữ, và vế dương (yêu cầu còn hiệu lực) đứng cạnh để test này không xanh nhờ một cổng đóng hết.
 */
it('refuses the create ability on a retracted thread at the policy layer, on both sides', function () {
    $live = $this->request;
    $retracted = ClientRequest::factory()->for($this->matter)->create([
        'client_user_id' => $this->clientUser->id,
    ]);
    $retracted->delete();

    expect(Gate::forUser($this->lawyer)->allows('create', [ClientRequestReply::class, $live]))->toBeTrue()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequestReply::class, $live]))->toBeTrue()
        ->and(Gate::forUser($this->lawyer)->allows('create', [ClientRequestReply::class, $retracted]))->toBeFalse()
        ->and(Gate::forUser($this->clientUser)->allows('create', [ClientRequestReply::class, $retracted]))->toBeFalse();
});

it('refuses a matter that has been retracted from the portal, for the client but not for the office', function () {
    $this->matter->update(['is_published_to_portal' => false]);

    expect(fn () => $this->action->handle($this->request, $this->clientUser, 'Viết thêm'))
        ->toThrow(AuthorizationException::class);

    // Vế dương: gỡ một vụ việc khỏi cổng KHÔNG khoá văn phòng ra khỏi hộp thư của chính họ.
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng vẫn trả lời được.');

    expect(allReplies()->count())->toBe(1);
});

it('refuses a deactivated portal account and a deactivated staff account', function () {
    $this->clientUser->update(['is_active' => false]);
    $this->lawyer->update(['is_active' => false]);

    foreach ([$this->clientUser->fresh(), $this->lawyer->fresh()] as $actor) {
        expect(fn () => $this->action->handle($this->request, $actor, 'Viết thêm'))
            ->toThrow(AuthorizationException::class);
    }

    expect(allReplies()->count())->toBe(0);
});

/**
 * SPEC §10.10, ở cả hai phía: mọi lý do từ chối đi ra bằng cùng MỘT câu, và câu đó là câu của
 * phía bên kia. Hai phía dùng chung một câu là một trong ba lý do Action này không bị tách đôi.
 */
it('refuses every situation with one identical vietnamese sentence on both sides', function () {
    $foreign = ClientRequest::factory()->for(Matter::factory()->create(['is_published_to_portal' => true]))->create();
    $ghost = ClientRequest::factory()->for($this->matter)->make(['id' => 999999]);
    $outsider = User::factory()->withRole(Role::Lawyer)->create();

    $messages = collect([
        [$foreign, $this->clientUser],
        [$ghost, $this->clientUser],
        [$foreign, $outsider],
        [$ghost, $outsider],
    ])->map(function (array $case): string {
        [$thread, $actor] = $case;

        try {
            $this->action->handle($thread, $actor, 'Viết thêm');
        } catch (AuthorizationException $exception) {
            return $exception->getMessage();
        }

        return 'KHÔNG TỪ CHỐI';
    })->unique();

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBe(__('requests.unavailable'));
});

/**
 * **Phiên đăng nhập của NGƯỜI KHÁC không được đổi kết quả của Action — và phép đo chỉ ra đúng
 * chỗ nào nó còn đổi được.**
 *
 * `$thread->matter` là một quan hệ nạp lười, và một lần nạp lười chạy dưới guard NÀO ĐANG MỞ chứ
 * không dưới `$actor`. Với một phiên portal của khách hàng khác đang mở trong cùng request, quan
 * hệ đó trả `null`. Ba hệ quả có thể có, và chỉ một trong ba còn thật:
 *
 *  - **Cổng quyền: KHÔNG bị ảnh hưởng.** `ClientRequestPolicy::view()` nạp vụ việc qua
 *    `ReadsPortalParents::parentWithoutPortalScope()`, thứ tự gỡ scope ra. Đo được: xoá dòng
 *    `setRelation()` trong Action vẫn để hai vế đầu của test này xanh. Nói ra vì docblock của
 *    Action từng ngụ ý ngược lại.
 *  - **Giá trị GHI VÀO NHẬT KÝ: bị ảnh hưởng thật.** `Audit` lấy `client_id` từ
 *    `$thread->matter`, và không có `setRelation()` thì dòng nhật ký của một lần trả lời hợp lệ
 *    mang `client_id = null` — một bản ghi bằng chứng bị làm hỏng bởi việc ai đó khác đang mở
 *    một tab. Đó là vế thứ ba dưới đây, và nó là thứ giữ cho câu lệnh ấy có nghĩa.
 *  - Cùng thiết bị và cùng lý do mà `SubmitClientDocument` phải dùng ở M4.
 */
it('still lets the right client write while a foreign portal session is open, and logs the real client', function () {
    $intruder = ClientUser::factory()->activated()->create();

    $this->actingAs($intruder, 'client');

    $this->action->handle($this->request, $this->clientUser, 'Tôi viết trong lúc người khác đang đăng nhập.');

    expect(allReplies()->count())->toBe(1);

    // Vế âm trong cùng ngữ cảnh: chính người đang đăng nhập kia vẫn không viết được.
    expect(fn () => $this->action->handle($this->request, $intruder, 'Cho tôi xem'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(1);

    // Vế đo thật: dòng nhật ký mang `client_id` của khách hàng THẬT, không phải `null` vì một
    // phiên của người khác đang cắt mọi truy vấn.
    $activity = Activity::query()->where('event', 'client_request_replied_by_client')->latest('id')->firstOrFail();

    expect($activity->properties['client_id'])->toBe($this->client->id);
});

/** Tham số đi ra từ URL và không được tin: Action đọc lại hàng thật. */
it('reads the thread back instead of trusting the object it was handed', function () {
    $foreign = ClientRequest::factory()->for(Matter::factory()->create(['is_published_to_portal' => true]))->create();
    $foreign->matter_id = $this->matter->id;

    expect(fn () => $this->action->handle($foreign, $this->clientUser, 'Viết thêm'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(0);
});

// =========================================================================================
// XÁC THỰC
// =========================================================================================

it('refuses an empty reply on both sides with the same vietnamese sentence', function () {
    foreach ([$this->clientUser, $this->lawyer] as $actor) {
        try {
            $this->action->handle($this->request, $actor, "   \n  ");
            $this->fail('Đáng lẽ phải ném ValidationException');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('content')
                ->and($exception->errors()['content'][0])->toBe(__('requests.validation.content_required'));
        }
    }

    expect(allReplies()->count())->toBe(0);
});

// =========================================================================================
// NHẬT KÝ — SPEC §10.6, đo với phiên của phía kia ĐANG MỞ
// =========================================================================================

/**
 * **Vế phân biệt được, ở cả hai phía.** Một test chỉ khẳng định causer đúng loại vẫn xanh khi
 * `causer:` tường minh bị xoá, vì `Audit::record()` rơi về `auth()`. Cảnh phân biệt được hai
 * cách cài đặt là **phiên của phía kia đang mở cùng lúc**: helper ưu tiên guard `web`, nên một
 * dòng trả lời của khách sẽ bị ghi tên luật sư nếu Action không truyền causer — và ngược lại,
 * một câu trả lời của văn phòng trong một request có cả hai guard vẫn phải mang tên nhân sự.
 */
it('credits each side correctly with the other guard session open', function () {
    $this->actingAs($this->lawyer, 'web');
    $this->actingAs($this->clientUser, 'client');

    $this->action->handle($this->request, $this->clientUser, 'Khách viết.');
    $this->action->handle(freshRequest($this->request), $this->lawyer, 'Văn phòng trả lời.');

    $byClient = Activity::query()->where('event', 'client_request_replied_by_client')->latest('id')->firstOrFail();
    $byStaff = Activity::query()->where('event', 'client_request_answered_by_staff')->latest('id')->firstOrFail();

    expect($byClient->causer_type)->toBe($this->clientUser->getMorphClass())
        ->and($byClient->causer_id)->toBe($this->clientUser->id)
        ->and($byClient->properties['client_request_id'])->toBe($this->request->id)
        ->and($byClient->properties['matter_id'])->toBe($this->matter->id)
        ->and($byClient->properties['client_id'])->toBe($this->client->id)
        ->and($byStaff->causer_type)->toBe($this->lawyer->getMorphClass())
        ->and($byStaff->causer_id)->toBe($this->lawyer->id);
});

/**
 * Hai tên sự kiện chứ không một tên kèm một thuộc tính: một lần rà soát "văn phòng đã trả lời
 * những gì" phải viết được bằng một truy vấn trên cột `event`.
 */
it('separates the two sides into two event names', function () {
    $this->action->handle($this->request, $this->clientUser, 'Khách viết.');
    $this->action->handle(freshRequest($this->request), $this->lawyer, 'Văn phòng trả lời.');

    expect(Activity::query()->where('event', 'client_request_replied_by_client')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'client_request_answered_by_staff')->count())->toBe(1);
});

it('writes neither a reply nor a log line when it refuses', function () {
    $outsider = ClientUser::factory()->activated()->create();

    expect(fn () => $this->action->handle($this->request, $outsider, 'Viết thêm'))
        ->toThrow(AuthorizationException::class)
        ->and(allReplies()->count())->toBe(0)
        ->and(Activity::query()->whereIn('event', [
            'client_request_replied_by_client',
            'client_request_answered_by_staff',
        ])->count())->toBe(0);
});

// =========================================================================================
// M6 Task 4 (`requests/REQ-2`, đính chính 2026-09-27) — thông báo trong hệ thống khi KHÁCH
// viết thêm vào một luồng cũ. Quyết định của implementer: mọi trạng thái, không riêng ca ví dụ
// "answered → in_progress" — xem docblock `ReplyToClientRequest::handle()`.
// =========================================================================================

it('notifies the lead lawyer in-app when the client writes again into a fresh thread nobody has claimed', function () {
    $this->action->handle($this->request, $this->clientUser, 'Tôi hỏi thêm một ý nữa ạ.');

    $notice = $this->lawyer->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('requests.followup_notification.title'));
});

/** `$preferred = [assigned_to ?? luật sư phụ trách]` — người ĐANG GIỮ luồng, không phải lead. */
it('notifies the assignee holding the thread instead of the lead lawyer', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $this->matter->addTeamMember($assistant, MatterRole::Assistant);
    app(TriageClientRequest::class)->assign($this->request, $this->lawyer, $assistant);

    $this->action->handle(freshRequest($this->request), $this->clientUser, 'Tôi hỏi thêm.');

    expect($assistant->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->count())->toBe(1)
        ->and($this->lawyer->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->count())->toBe(0);
});

/**
 * Ca SPEC nêu làm ví dụ điển hình: khách viết tiếp vào một luồng ĐÃ `answered` (văn phòng tưởng
 * đã xong).
 */
it('notifies the lead lawyer when the client writes again into an answered thread', function () {
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời anh/chị như sau.');

    $this->action->handle(freshRequest($this->request), $this->clientUser, 'Tôi còn một ý chưa rõ.');

    expect($this->lawyer->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->count())->toBe(1);
});

/**
 * Cặp âm: một câu trả lời của NHÂN SỰ không sinh thông báo "khách viết thêm" này.
 *
 * Mutation probe: bỏ điều kiện `$actor instanceof ClientUser` khỏi `handle()` (gọi
 * `notifyHolderOfFollowUp()` vô điều kiện) — test này ĐỎ (nhân sự trả lời cũng sinh thông báo).
 */
it('does not send a follow-up alert when the office replies, only when the client does', function () {
    $this->action->handle($this->request, $this->lawyer, 'Văn phòng trả lời.');

    expect($this->lawyer->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->count())->toBe(0);
});

it('mentions the matter code in the follow-up notification body', function () {
    $this->action->handle($this->request, $this->clientUser, 'Tôi hỏi thêm.');

    $notice = $this->lawyer->fresh()->notifications()->where('type', ClientRequestFollowUpAlert::class)->first();

    expect($notice->data['body'] ?? '')->toContain($this->matter->code);
});

/**
 * R2 (áp dụng cho notification, không riêng thư): một notification hỏng không được biến câu trả
 * lời ĐÃ LƯU THÀNH CÔNG của khách thành một lỗi 500. Ép `ResolveStaffRecipients` (gọi bên trong
 * `notifyHolderOfFollowUp()`) ném lỗi thật, rồi khẳng định `handle()` vẫn trả về bình thường.
 *
 * Mutation probe: bỏ `try/catch` khỏi `notifyHolderOfFollowUp()` — test này ĐỎ (ngoại lệ thoát ra
 * khỏi `handle()`).
 */
it('never turns a successful client reply into an exception when the follow-up notification blows up', function () {
    app()->bind(ResolveStaffRecipients::class, fn () => new class extends ResolveStaffRecipients
    {
        public function handle(Matter $matter, array $preferred): Collection
        {
            throw new RuntimeException('Hỏng có chủ ý để đo khả năng chịu lỗi.');
        }
    });

    $reply = $this->action->handle($this->request, $this->clientUser, 'Tôi hỏi thêm.');

    expect($reply)->not->toBeNull()
        ->and($reply->content)->toBe('Tôi hỏi thêm.')
        ->and(allReplies()->count())->toBe(1);
});
