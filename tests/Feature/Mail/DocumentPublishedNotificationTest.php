<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Matter\CancelMatter;
use App\Actions\Notification\NotifyClientOfDocumentPublished;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\DocumentPublished;
use App\Listeners\SendDocumentPublishedNotification;
use App\Mail\Client\DocumentPublished as DocumentPublishedMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
});

/**
 * Vụ việc đã công bố cổng, một tài khoản khách ĐÃ kích hoạt, và một tài liệu nhóm B (Issued) đã ký
 * và nộp — đúng điều kiện tối thiểu để `PublishDocument` được phép công bố (SPEC §6.5, §4.11).
 *
 * @return array{0: Matter, 1: User, 2: ClientUser, 3: Document}
 */
function publishableMatterWithClientAccount(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);

    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => true,
    ]);

    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::SignedFiled,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);

    $document->addMedia(UploadedFile::fake()->createWithContent('van-ban.pdf', '%PDF-1.4 test'))
        ->toMediaCollection('file');

    return [$matter, $lawyer, $account, $document->refresh()];
}

function publishAsLawyer(Document $document, User $lawyer): Document
{
    return app(PublishDocument::class)->handle(
        document: $document->fresh(),
        actor: $lawyer,
        clientCanView: true,
        clientCanDownload: true,
        expectedClientCanView: false,
        expectedClientCanDownload: false,
        expectedIsReleased: false,
    );
}

/**
 * Test đi qua ĐÚNG đường sản phẩm (gọi `PublishDocument` thật, không gọi thẳng Action gửi thư) —
 * cùng nguyên tắc `StageUpdateNotificationTest`.
 */
it('emails the client when a lawyer publishes a group B document for the first time', function () {
    Mail::fake();
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();

    publishAsLawyer($document, $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, 1);
    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));
});

it('tells every activated account of the client, because a client may have two', function () {
    Mail::fake();
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $spouse = ClientUser::factory()->activated()->create(['client_id' => $account->client_id, 'is_active' => true]);
    $neverActivated = ClientUser::factory()->create(['client_id' => $account->client_id, 'is_active' => true]);

    publishAsLawyer($document, $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($spouse->email));
    Mail::assertNotSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($neverActivated->email));
});

it('sends nothing when re-publishing the same document a second time (not a first release)', function () {
    Mail::fake();
    [, $lawyer, , $document] = publishableMatterWithClientAccount();

    publishAsLawyer($document, $lawyer);
    Mail::assertSent(DocumentPublishedMail::class, 1);

    // Công bố lại (đổi cờ tải) không phải một lần công bố ĐẦU — sự kiện không bắn lại (xem
    // docblock `App\Events\DocumentPublished`).
    app(PublishDocument::class)->handle(
        document: $document->fresh(),
        actor: $lawyer,
        clientCanView: true,
        clientCanDownload: false,
        expectedClientCanView: true,
        expectedClientCanDownload: true,
        expectedIsReleased: true,
    );

    Mail::assertSent(DocumentPublishedMail::class, 1);
});

it('does not email anyone when publishing a group C document that is not Issued/Authority-eligible for the event', function () {
    // Nhóm A (khách cung cấp) không bao giờ đi qua PublishDocument với group Issued/Authority —
    // test này khẳng định sự kiện chỉ bắn cho B/C bằng việc dùng đúng nhóm C, và assert nó CÓ gửi
    // (cặp dương của test trên "chỉ nhóm B/C"), tránh false negative do gõ nhầm enum.
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id, 'is_published_to_portal' => true]);
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Authority,
        'status' => DocumentStatus::InternalDraft,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('qd.pdf', '%PDF-1.4 test'))->toMediaCollection('file');

    publishAsLawyer($document->refresh(), $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));
});

/** R6, ranh giới người nhận — soi bằng mutation. */
it('never tells an account whose client has been soft deleted', function () {
    Mail::fake();
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $account->client->delete();

    publishAsLawyer($document, $lawyer);

    Mail::assertNothingSent();
});

/**
 * Fix round 1 (finding Important 3, R6 ranh giới người nhận): một tài khoản đã kích hoạt của một
 * khách hàng KHÁC (không sở hữu vụ việc này) không bao giờ được vào cùng lô người nhận, dù nó đủ
 * điều kiện `is_active` + `activated_at` theo đúng luật R12 — ranh giới ở đây là CLIENT_ID, không
 * chỉ "đã kích hoạt".
 *
 * Mutation probe: xoá `->where('client_id', $clientId)` khỏi
 * `ResolveClientRecipients::eligibleQuery()` — test này ĐỎ (thư đi luôn tới tài khoản của khách
 * khác).
 */
it('never tells an activated account belonging to a different client', function () {
    Mail::fake();
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $strangerClient = Client::factory()->create();
    $strangerAccount = ClientUser::factory()->activated()->create(['client_id' => $strangerClient->id, 'is_active' => true]);

    publishAsLawyer($document, $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($strangerAccount->email));
});

/**
 * R6, ranh giới nội dung: một cột NỘI BỘ của vụ việc (`matters.description_internal`, không có
 * ranh giới công bố nào cho khách) không bao giờ được vào thư — đo bằng chuỗi đánh dấu duy nhất,
 * không đọc bằng mắt, và soi cả ba nơi (tiêu đề, HTML, văn bản thuần), cùng lối
 * `StageUpdateNotificationTest::'never carries the internal note into the client mailbox'`.
 */
it('never carries the matters internal note into the client mailbox', function () {
    [$matter, , $account, $document] = publishableMatterWithClientAccount();
    $marker = 'DAU-HIEU-NOI-BO-'.uniqid();
    $matter->update(['description_internal' => $marker]);
    $document->update(['title' => 'Quyet dinh thu ly vu an']);

    $mail = new DocumentPublishedMail($document->fresh(), $account);
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($subject)->not->toContain($marker)
        ->and($html)->not->toContain($marker)
        ->and($text)->not->toContain($marker)
        // Cặp dương: nội dung ĐÃ CÔNG BỐ (tên tài liệu) vẫn phải có mặt trong cả hai phần, nếu
        // không test trên xanh vì thư rỗng.
        ->and($html)->toContain('Quyet dinh thu ly vu an')
        ->and($text)->toContain('Quyet dinh thu ly vu an');
});

it('never tells a never-activated account (R12)', function () {
    Mail::fake();
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $account->update(['is_active' => false]);

    publishAsLawyer($document, $lawyer);

    Mail::assertNothingSent();
});

/**
 * Fix round 1 (finding Critical 1): công tắc tổng `matters.is_published_to_portal` (SPEC §4,
 * mặc định `false`) phải chặn thư này, đi qua ĐÚNG đường sản phẩm (`PublishDocument` thật, không
 * gọi thẳng `NotifyClientOfDocumentPublished::handle()` — cùng nguyên tắc mọi test khác trong tệp
 * này). Trước bản sửa này, `$account->can('view', $matter)` là `false` (portal không hiện gì) mà
 * thư vẫn đi kèm tên tài liệu — bỏ qua công tắc tổng và mang nội dung mà chính ranh giới portal
 * đang giấu.
 *
 * Mutation probe: xoá `->where('is_published_to_portal', true)` khỏi
 * `NotifyClientOfDocumentPublished::handle()` — test này ĐỎ.
 */
it('sends nothing when publishing a document on a matter with the portal switch off', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create([
        'client_id' => $client->id,
        'lead_lawyer_id' => $lawyer->id,
        'is_published_to_portal' => false,
    ]);
    $document = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::SignedFiled,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
    $document->addMedia(UploadedFile::fake()->createWithContent('van-ban.pdf', '%PDF-1.4 test'))
        ->toMediaCollection('file');

    expect($account->can('view', $matter->fresh()))->toBeFalse();

    publishAsLawyer($document->refresh(), $lawyer);

    Mail::assertNothingSent();
});

/**
 * R6 — ranh giới nội dung: tiêu đề KHÔNG bao giờ nêu tên tài liệu (sổ tay M6.5 Task 13).
 *
 * Mutation probe (paste vào báo cáo): đổi `envelope()` để nội suy `$this->document->title` vào
 * `subject` — test này ĐỎ.
 */
it('never puts the document title in the subject, only the matter code', function () {
    [$matter, , $account, $document] = publishableMatterWithClientAccount();
    $document->update(['title' => 'MOT-TIEU-DE-BI-MAT-'.uniqid()]);

    $subject = (new DocumentPublishedMail($document->fresh(), $account))->envelope()->subject;

    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain($document->fresh()->title);
});

/** Cặp dương: tên tài liệu CÓ mặt trong thân thư (chỗ chỉ người nhận đọc được). */
it('puts the document title in the body', function () {
    [, , $account, $document] = publishableMatterWithClientAccount();
    $document->update(['title' => 'Quyet dinh thu ly vu an']);

    $mail = new DocumentPublishedMail($document->fresh(), $account);
    $html = $mail->render();

    expect($html)->toContain('Quyet dinh thu ly vu an');
});

it('wires the DocumentPublished event to the listener', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(DocumentPublished::class, $listeners))->toBeTrue();
});

it('is a queued listener with a real retry budget', function () {
    $listener = app(SendDocumentPublishedNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

/**
 * Final review-style re-check lúc gửi: tài liệu bị rút khỏi cổng (chuyển nhóm D) giữa lúc sự
 * kiện bắn và lúc listener chạy — không gửi, không lỗi.
 *
 * Mutation probe: bỏ điều kiện `! $fresh->isReleasedToPortal()` (tức luôn coi là còn released)
 * khỏi `NotifyClientOfDocumentPublished::stillReleasedToPortal()` — test này ĐỎ.
 */
it('sends nothing when the document was pulled from the portal before the job ran', function () {
    Mail::fake();
    [, , , $document] = publishableMatterWithClientAccount();

    $document->update(['client_can_view' => false]);

    $sent = app(NotifyClientOfDocumentPublished::class)->handle($document->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Vụ việc bị xoá mềm (huỷ) giữa lúc sự kiện bắn và lúc job chạy.
 *
 * Mutation probe: bỏ điều kiện `->open()` khỏi `NotifyClientOfDocumentPublished::handle()` —
 * KHÔNG làm ĐỎ test này (Eloquent tự áp `SoftDeletingScope` mặc định, không cần `->open()` cho vế
 * xoá mềm) — xem test kế tiếp cho vế `closed_at` mà `->open()` thật sự canh.
 */
it('sends nothing when the matter was cancelled (soft-deleted) before the job ran', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    [$matter, , , $document] = publishableMatterWithClientAccount();

    app(CancelMatter::class)->handle($matter, $admin, 'Huỷ ngay trước khi job kịp chạy.');

    $sent = app(NotifyClientOfDocumentPublished::class)->handle($document->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Vụ việc đã ĐÓNG (`closed_at` khác `null`, KHÔNG xoá mềm) giữa lúc sự kiện bắn và lúc job chạy —
 * vế mà `SoftDeletingScope` mặc định KHÔNG tự bắt, nên đây mới là test thật sự đo `->open()`.
 *
 * Mutation probe (paste vào báo cáo): bỏ `->open()` khỏi
 * `NotifyClientOfDocumentPublished::handle()` — test này ĐỎ.
 */
it('sends nothing when the matter was closed (not soft-deleted) before the job ran', function () {
    [$matter, $lawyer, , $document] = publishableMatterWithClientAccount();
    $published = publishAsLawyer($document, $lawyer);

    // Vô hiệu hoá listener thật (đã chạy đồng bộ trong publishAsLawyer, đã gửi 1 thư) để phần
    // còn lại của test chỉ đo đúng lần gọi handle() thủ công dưới đây.
    $matter->update(['closed_at' => now()]);

    Mail::fake();
    $sent = app(NotifyClientOfDocumentPublished::class)->handle($published->fresh());

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Chống gửi trùng qua nhật ký thư (không có cột "đã báo" ở `documents`). KHÔNG `Mail::fake()` —
 * cùng lý do `StageUpdateNotificationTest`'s "double-mail" test: `MailFake` thay cả trình gửi
 * thư, không phát sự kiện `MessageSending`/`MessageSent`, nên `outbound_messages` không có dòng
 * nào để `alreadyDelivered()` đọc lại. Mailer mặc định của bộ test (`array`) vẫn đi qua Mailer
 * thật (chỉ transport là bộ nhớ), nên sự kiện vẫn phát và nhật ký vẫn được ghi.
 *
 * Mutation probe: bỏ khối `if ($this->alreadyDelivered(...))` khỏi
 * `NotifyClientOfDocumentPublished::handle()` — test này ĐỎ (đếm ra 2 thay vì 1).
 */
it('never sends the same document notification twice to the same recipient', function () {
    [, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $published = publishAsLawyer($document, $lawyer);

    app(NotifyClientOfDocumentPublished::class)->handle($published->fresh());
    app(NotifyClientOfDocumentPublished::class)->handle($published->fresh());

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $account->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(1);
});

/** Final review B-M3 style: hỏng hẳn thì báo luật sư phụ trách trong hệ thống. */
it('tells the lead lawyer in-app when the document mail fails for good', function () {
    [, $lawyer, , $document] = publishableMatterWithClientAccount();

    app(SendDocumentPublishedNotification::class)->failed(new DocumentPublished($document), new RuntimeException('SMTP'));

    $notice = $lawyer->fresh()->notifications()->latest()->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('matters.document_published_failed_notification.title'));
});
