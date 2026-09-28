<?php

use App\Actions\Notification\NotifyStaffOfNewClientRequest;
use App\Actions\Portal\OpenClientRequest;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\ClientRequestOpened;
use App\Listeners\SendNewClientRequestNotification;
use App\Mail\Staff\NewClientRequest as NewClientRequestMail;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\NewClientRequestAlert;
use App\Notifications\Staff\NewClientRequestMailFailedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC §9 `staff.new_client_request` — lấp `requests/REQ-1` (audit 2026-09-24): khách gửi yêu
 * cầu qua cổng mà không ai trong văn phòng được báo. Dispatch: `App\Events\ClientRequestOpened`
 * (`App\Actions\Portal\OpenClientRequest::handle()`).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
});

/** @return array{0: Matter, 1: User} Vụ việc bình thường, luật sư phụ trách. */
function requestableMatter(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    return [$matter, $lawyer];
}

function openRequestAsClient(Matter $matter, string $subject = 'Hỏi về ngày hoà giải', string $content = 'Xin hỏi văn phòng ngày hoà giải đã có chưa ạ.'): ClientRequest
{
    $client = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    return app(OpenClientRequest::class)->handle($matter, $client, $subject, $content);
}

it('emails the lead lawyer when a client opens a new request', function () {
    Mail::fake();
    [$matter, $lawyer] = requestableMatter();

    openRequestAsClient($matter);

    Mail::assertSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

it('notifies the lead lawyer in-app when a client opens a new request', function () {
    [$matter, $lawyer] = requestableMatter();

    openRequestAsClient($matter);

    expect($lawyer->fresh()->notifications()->where('type', NewClientRequestAlert::class)->count())->toBe(1);
});

/** SPEC §6.6 bước 9 "lead lawyer và trợ lý": mọi trợ lý trong ĐỘI NGŨ của vụ việc, không riêng lead. */
it('also emails every team assistant of the matter', function () {
    Mail::fake();
    [$matter, $lawyer] = requestableMatter();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    openRequestAsClient($matter);

    Mail::assertSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($assistant->email));
});

/** Cặp âm của test trên — soi bằng mutation trong báo cáo. */
it('does not email a team member with any other role', function () {
    Mail::fake();
    [$matter] = requestableMatter();
    $associate = User::factory()->withRole(Role::Lawyer)->create();
    $matter->addTeamMember($associate, MatterRole::Associate);

    openRequestAsClient($matter);

    Mail::assertNotSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($associate->email));
});

it('never tells an inactive lead lawyer, falling back to a manager who can view the matter', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);

    openRequestAsClient($matter);

    Mail::assertNotSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($manager->email));
});

/**
 * Review Focus 1 (brief): vụ `restricted` — một manager KHÔNG trong đội ngũ không được thấy gì,
 * kể cả một thông báo. Đo bằng `ResolveStaffRecipients::supervisorsFor()` — thay manager bằng
 * admin cho vụ restricted.
 */
it('escalates to an admin instead of a manager when the matter is restricted', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create([
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
        'confidentiality' => Confidentiality::Restricted,
    ]);

    openRequestAsClient($matter);

    Mail::assertNotSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($manager->email));
    Mail::assertSent(NewClientRequestMail::class, fn ($mail) => $mail->hasTo($admin->email));
});

it('never puts the clients own subject line in the email subject, only the matter code', function () {
    [$matter] = requestableMatter();
    $lawyer = $matter->leadLawyer;
    $request = openRequestAsClient($matter, 'MOT-TIEU-DE-BI-MAT-'.uniqid());

    $subject = (new NewClientRequestMail($request->fresh(), $lawyer))->envelope()->subject;

    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain($request->subject);
});

/** Cặp dương: nội dung khách gõ CÓ mặt trong thân thư (nơi chỉ người xem được vụ mới đọc được). */
it('puts the clients subject and content in the email body', function () {
    [$matter] = requestableMatter();
    $lawyer = $matter->leadLawyer;
    $request = openRequestAsClient($matter, 'Cau hoi rieng biet', 'Noi dung rieng biet');

    $html = (new NewClientRequestMail($request->fresh(), $lawyer))->render();

    expect($html)->toContain('Cau hoi rieng biet')
        ->and($html)->toContain('Noi dung rieng biet');
});

it('sends nothing when the matter was cancelled before the job ran', function () {
    // KHÔNG `Mail::fake()` ở đây: lần mở yêu cầu THẬT đã tự gửi một thư (listener chạy đồng bộ,
    // hàng đợi `sync` của bộ test) — bật fake TRƯỚC sẽ ghi nhận lần gửi đó và làm
    // `assertNothingSent()` dưới đây đỏ vì một lý do KHÁC (thư của bước dựng, không phải của
    // bước đo). Cùng nguyên tắc `DocumentPublishedNotificationTest`'s tương tự.
    [$matter] = requestableMatter();
    $request = openRequestAsClient($matter);
    $matter->delete();

    Mail::fake();
    $sent = app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Mutation probe: bỏ `->open()` khỏi `NotifyStaffOfNewClientRequest::openMatterFor()` — test
 * này ĐỎ (vụ đóng nhưng KHÔNG xoá mềm, nên `SoftDeletingScope` mặc định không tự bắt vế này).
 */
it('sends nothing when the matter was closed (not soft-deleted) before the job ran', function () {
    [$matter] = requestableMatter();
    $request = openRequestAsClient($matter);
    $matter->update(['closed_at' => now()]);

    Mail::fake();
    $sent = app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Chống gửi trùng qua nhật ký thư (không `Mail::fake()` — cùng lý do các Notify* khác trong
 * lane: `MailFake` không phát `MessageSending`/`MessageSent` nên `outbound_messages` không có gì
 * để `alreadyDelivered()` đọc lại).
 *
 * Mutation probe: bỏ `alreadyDelivered()` khỏi `handle()` — test này ĐỎ (đếm ra 2 thay vì 1).
 */
it('never sends the same request notification twice to the same recipient', function () {
    [$matter] = requestableMatter();
    $lawyer = $matter->leadLawyer;
    $request = openRequestAsClient($matter);

    app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());
    app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $lawyer->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(1);
});

/**
 * Chống trùng của thông báo trong hệ thống — riêng, độc lập với nhật ký thư.
 *
 * Mutation probe: bỏ `alreadyAlerted()` khỏi `handle()` — test này ĐỎ (2 dòng thay vì 1).
 */
it('never writes the same in-app alert twice to the same recipient', function () {
    [$matter] = requestableMatter();
    $lawyer = $matter->leadLawyer;
    $request = openRequestAsClient($matter);

    app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());
    app(NotifyStaffOfNewClientRequest::class)->handle($request->fresh());

    expect($lawyer->fresh()->notifications()->where('type', NewClientRequestAlert::class)->count())->toBe(1);
});

it('wires the ClientRequestOpened event to the listener', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(ClientRequestOpened::class, $listeners))->toBeTrue();
});

it('is a queued listener with a real retry budget', function () {
    $listener = app(SendNewClientRequestNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

/**
 * Final review B-M3 style: hỏng hẳn thì báo luật sư phụ trách trong hệ thống.
 *
 * Lọc theo `type` thay vì `->latest()`: `openRequestAsClient()` đã tự ghi MỘT
 * `NewClientRequestAlert` trước đó, và trên cột `created_at` không có độ chính xác micro giây —
 * hai dòng cùng giây có thể hoà, khiến `latest()` không đáng tin để phân biệt CHÚNG.
 */
it('tells the lead lawyer in-app when the request mail fails for good', function () {
    [$matter] = requestableMatter();
    $lawyer = $matter->leadLawyer;
    $request = openRequestAsClient($matter);

    app(NotifyStaffOfNewClientRequest::class)->reportFailure($request->fresh());

    $notice = $lawyer->fresh()->notifications()
        ->where('type', NewClientRequestMailFailedAlert::class)
        ->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('requests.new_request_failed_notification.title'));
});
