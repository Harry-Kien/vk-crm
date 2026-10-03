<?php

use App\Actions\Notification\NotifyClientOfRequestAnswered;
use App\Actions\Portal\ReplyToClientRequest;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\ClientRequestAnswered;
use App\Listeners\SendClientRequestAnsweredNotification;
use App\Mail\Client\RequestAnswered as RequestAnsweredMail;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\RequestAnsweredMailFailedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * SPEC §9 `client.request_answered` (đính chính 2026-09-27, `requests/REQ-4`): một câu trả lời
 * của văn phòng vừa đưa một luồng vào `answered` LẦN ĐẦU. Dispatch:
 * `App\Events\ClientRequestAnswered` (`App\Actions\Portal\ReplyToClientRequest::advanceStatus()`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: Matter, 1: User, 2: ClientUser, 3: ClientRequest} */
function answerableThread(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $request = ClientRequest::factory()->for($matter)->create([
        'client_user_id' => $account->id,
        'status' => ClientRequestStatus::New,
    ]);

    return [$matter, $lawyer, $account, $request];
}

it('emails the activated client account when the office answer moves the thread into answered', function () {
    Mail::fake();
    [, $lawyer, $account, $request] = answerableThread();

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Ngày hoà giải là 12/10.');

    Mail::assertSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($account->email));
});

it('tells every activated account of the client, because a client may have two', function () {
    Mail::fake();
    [, $lawyer, $account, $request] = answerableThread();
    $spouse = ClientUser::factory()->activated()->create(['client_id' => $account->client_id, 'is_active' => true]);

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Ngày hoà giải là 12/10.');

    Mail::assertSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($spouse->email));
});

/** R12, ranh giới người nhận — soi bằng mutation ở ResolveClientRecipients (Task 3). */
it('never tells a never-activated account', function () {
    Mail::fake();
    [, $lawyer, $account, $request] = answerableThread();
    $account->update(['activated_at' => null]);

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Ngày hoà giải là 12/10.');

    Mail::assertNothingSent();
});

/**
 * Mutation probe: bỏ `->where('is_published_to_portal', true)` khỏi `NotifyClientOfRequestAnswered
 * ::publishedMatterFor()` — test này ĐỎ.
 */
it('sends nothing when the matter has the portal switch off', function () {
    Mail::fake();
    [$matter, $lawyer, , $request] = answerableThread();
    $matter->update(['is_published_to_portal' => false]);

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Ngày hoà giải là 12/10.');

    Mail::assertNothingSent();
});

/**
 * SPEC "đổi luồng SANG answered" — một câu trả lời thứ HAI vào một luồng ĐÃ answered không gửi
 * thư mới. Mutation probe: bỏ điều kiện `$previousStatus !== ClientRequestStatus::Answered` khỏi
 * `ReplyToClientRequest::advanceStatus()` — test này ĐỎ (2 thư thay vì 1).
 */
it('sends only one mail even when the office replies twice while the thread stays answered', function () {
    Mail::fake();
    [, $lawyer, , $request] = answerableThread();

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời lần một.');
    app(ReplyToClientRequest::class)->handle($request->fresh(), $lawyer, 'Trả lời lần hai, bổ sung thêm.');

    Mail::assertSent(RequestAnsweredMail::class, 1);
});

/**
 * Một luồng ĐI RA khỏi `answered` (khách hỏi tiếp) rồi ĐƯỢC TRẢ LỜI LẦN NỮA là một lần CHUYỂN
 * VÀO `answered` khác — gửi thư THỨ HAI, khoá theo câu trả lời cụ thể (không phải theo luồng).
 */
it('sends a second, separate mail when the thread leaves answered and is answered again', function () {
    Mail::fake();
    [, $lawyer, $account, $request] = answerableThread();

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời lần một.');
    app(ReplyToClientRequest::class)->handle($request->fresh(), $account, 'Tôi còn một ý chưa rõ.');
    app(ReplyToClientRequest::class)->handle($request->fresh(), $lawyer, 'Trả lời lần hai.');

    Mail::assertSent(RequestAnsweredMail::class, 2);
});

/**
 * `TriageClientRequest::setStatus(Answered)` (trả lời qua điện thoại, KHÔNG viết câu nào) không
 * gửi thư — phán quyết controller, task-4-brief.md. Không có `ClientRequestReply` nào để mời
 * khách "vào cổng xem".
 */
it('sends nothing when staff marks a thread answered by phone without writing a reply', function () {
    Mail::fake();
    [, $lawyer, , $request] = answerableThread();

    app(TriageClientRequest::class)->setStatus($request, $lawyer, ClientRequestStatus::Answered);

    Mail::assertNothingSent();
});

it('never puts the clients own request subject in the email subject, only the matter code', function () {
    [$matter, $lawyer, $account, $request] = answerableThread();
    $request->update(['subject' => 'MOT-TIEU-DE-BI-MAT-'.uniqid()]);
    $reply = app(ReplyToClientRequest::class)->handle($request->fresh(), $lawyer, 'Trả lời cho khách.');

    $subject = (new RequestAnsweredMail($reply->fresh(), $account))->envelope()->subject;

    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain($request->fresh()->subject);
});

/**
 * Fix round 1 (finding Important 2), R6 ranh giới nội dung: các cột NỘI BỘ
 * (`matters.description_internal`, `clients.note`) không có ranh giới công bố nào cho khách,
 * không bao giờ được vào thư — đo bằng chuỗi đánh dấu ở CẢ BA nơi (tiêu đề, HTML, văn bản thuần),
 * cùng lối `DocumentPublishedNotificationTest::'never carries the matters internal note into the
 * client mailbox'`. Marker trước đây chỉ đặt trong NỘI DUNG CÂU TRẢ LỜI (client-visible, không
 * phải nội bộ) và không soi tiêu đề — một mẫu sau này lỡ thêm mô tả vụ việc hoặc ghi chú khách
 * hàng vào thư sẽ không có test nào chuyển đỏ.
 */
it('never carries the matters internal note or the clients internal note into the answered mail', function () {
    [$matter, $lawyer, $account, $request] = answerableThread();
    $matterMarker = 'DAU-HIEU-NOI-BO-VU-VIEC-'.uniqid();
    $clientMarker = 'DAU-HIEU-NOI-BO-KHACH-HANG-'.uniqid();
    $matter->update(['description_internal' => $matterMarker]);
    $account->client()->update(['note' => $clientMarker]);

    $reply = app(ReplyToClientRequest::class)->handle($request->fresh(), $lawyer, 'Trả lời cho khách.');

    $mail = new RequestAnsweredMail($reply->fresh(), $account->fresh());
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($subject)->not->toContain($matterMarker)
        ->and($subject)->not->toContain($clientMarker)
        ->and($html)->not->toContain($matterMarker)
        ->and($html)->not->toContain($clientMarker)
        ->and($text)->not->toContain($matterMarker)
        ->and($text)->not->toContain($clientMarker)
        // Cặp dương: mã hồ sơ (nội dung ĐÃ công bố qua tiêu đề/thân thư) vẫn phải có mặt.
        ->and($html)->toContain($matter->code)
        ->and($text)->toContain($matter->code);
});

/**
 * R6/R7 — thân thư KHÔNG trích nội dung câu trả lời (SPEC §9: "chi tiết mời bấm vào portal").
 */
it('never puts the reply content in the email body, only an invitation to the portal', function () {
    [, $lawyer, $account, $request] = answerableThread();
    $marker = 'NOI-DUNG-TRA-LOI-RIENG-TU-'.uniqid();
    $reply = app(ReplyToClientRequest::class)->handle($request, $lawyer, $marker);

    $mail = new RequestAnsweredMail($reply->fresh(), $account);
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($html)->not->toContain($marker)
        ->and($text)->not->toContain($marker);
});

it('never tells an activated account belonging to a different client', function () {
    Mail::fake();
    [, $lawyer, $account, $request] = answerableThread();
    $strangerClient = Client::factory()->create();
    $strangerAccount = ClientUser::factory()->activated()->create(['client_id' => $strangerClient->id]);

    app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời cho khách.');

    Mail::assertSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($strangerAccount->email));
});

it('sends nothing when the matter was soft deleted before the job ran', function () {
    [$matter, $lawyer, , $request] = answerableThread();
    $reply = app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời cho khách.');
    $matter->delete();

    Mail::fake();
    $sent = app(NotifyClientOfRequestAnswered::class)->handle($reply->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Lệch có chủ ý khỏi khuôn `NotifyClientOfDocumentPublished` (không hỏi `Matter::open()`) — xem
 * docblock lớp. Một vụ việc ĐÃ ĐÓNG (không xoá mềm) vẫn hiện trên cổng, nên câu trả lời đã viết
 * ra vẫn phải tới tay khách.
 */
it('still sends when the matter is closed but not soft-deleted, still published to the portal', function () {
    [$matter, $lawyer, $account, $request] = answerableThread();
    $matter->update(['closed_at' => now()]);

    Mail::fake();
    app(ReplyToClientRequest::class)->handle($request->fresh(), $lawyer, 'Trả lời cho khách.');

    Mail::assertSent(RequestAnsweredMail::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * Chống gửi trùng qua nhật ký thư, khoá theo CÂU TRẢ LỜI (`related_type = client_request_reply`)
 * — không `Mail::fake()`, cùng lý do các Notify* khác. Mutation probe: bỏ `alreadyDelivered()`
 * khỏi `handle()` — test này ĐỎ.
 */
it('never sends the same answered notification twice to the same recipient', function () {
    [, $lawyer, $account, $request] = answerableThread();
    $reply = app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời cho khách.');

    app(NotifyClientOfRequestAnswered::class)->handle($reply->fresh());
    app(NotifyClientOfRequestAnswered::class)->handle($reply->fresh());

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(1);
});

it('wires the ClientRequestAnswered event to the listener', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(ClientRequestAnswered::class, $listeners))->toBeTrue();
});

it('is a queued listener with a real retry budget', function () {
    $listener = app(SendClientRequestAnsweredNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

it('tells the lead lawyer in-app when the answered mail fails for good', function () {
    [, $lawyer, , $request] = answerableThread();
    $reply = app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời cho khách.');

    app(NotifyClientOfRequestAnswered::class)->reportFailure($reply->fresh());

    $notice = $lawyer->fresh()->notifications()->where('type', RequestAnsweredMailFailedAlert::class)->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('matters.request_answered_failed_notification.title'));
});

/**
 * Gộp M7 vào `main` (PROGRESS "Ghi chú M7", Task 11, "Lúc gộp `main`"): "vụ còn trên cổng" lúc gửi
 * được hỏi bằng ĐỊNH NGHĨA cổng của chính người nhận — `Gate::forUser($account)->allows('view',
 * $matter)`, tức `MatterPolicy::view` nhánh khách, gồm điều kiện "chưa hết hạn tra cứu" của M7 Task 5
 * (R4) — không chỉ bằng cờ `is_published_to_portal`. Thư này cố ý đi cả cho vụ ĐÃ ĐÓNG còn trên cổng
 * (test ngay trên), nên trước bản gộp nó cũng đi cho vụ đã đóng mà hạn tra cứu đã qua: tài khoản khách
 * còn hoạt động nhờ một vụ khác, và thư mang liên kết tới một trang trả 404. Nút "Gửi lại" của nhật ký
 * thư hỏi cùng `eligibleRecipients()`. Vế dương: hôm nay là ngày tra cứu cuối thì thư vẫn đi, nên vế
 * âm không xanh nhờ một lý do khác.
 */
it('mails nothing about a closed matter whose client access has expired, but still mails on its last day', function (string $accessUntil, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    Event::fake([ClientRequestAnswered::class]);
    [$matter, $lawyer, $account, $request] = answerableThread();
    $reply = app(ReplyToClientRequest::class)->handle($request, $lawyer, 'Trả lời cho khách.');

    $matter->update(['closed_at' => '2026-07-22 10:00:00']);
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $accessUntil]);
    // Vụ thứ hai của cùng khách, còn trên cổng: lý do tài khoản vẫn hoạt động.
    Matter::factory()->create(['client_id' => $account->client_id, 'is_published_to_portal' => true]);

    $notifier = app(NotifyClientOfRequestAnswered::class);

    expect($notifier->eligibleRecipients($reply->fresh()))->toHaveCount($expected);

    Mail::fake();

    expect($notifier->handle($reply->fresh()))->toBe($expected);
    Mail::assertSent(RequestAnsweredMail::class, $expected);
})->with([
    'đã hết hạn tra cứu từ hôm nay' => ['2026-10-20', 0],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', 1],
]);
