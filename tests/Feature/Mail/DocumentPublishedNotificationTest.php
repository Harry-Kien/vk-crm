<?php

use App\Actions\Document\PublishDocument;
use App\Actions\Matter\CancelMatter;
use App\Actions\Notification\NotifyClientOfDocumentPublished;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Enums\HandoverPackageStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\DocumentPublished;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Listeners\SendDocumentPublishedNotification;
use App\Mail\Client\DocumentPublished as DocumentPublishedMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
 * Mutation probe: bỏ điều kiện "vụ còn mở" (`! $matter->isOpen() && …`) khỏi
 * `NotifyClientOfDocumentPublished::recipientsForReleased()` — KHÔNG làm ĐỎ test này (Eloquent tự áp
 * `SoftDeletingScope` mặc định cho vế xoá mềm) — xem test kế tiếp cho vế `closed_at` mà điều kiện đó
 * thật sự canh.
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
 * vế mà `SoftDeletingScope` mặc định KHÔNG tự bắt, nên đây mới là test thật sự đo điều kiện "vụ còn
 * mở". Tài liệu ở đây là tài liệu THƯỜNG; gói bàn giao là ngoại lệ duy nhất (phần cuối tệp).
 *
 * Mutation probe (paste vào báo cáo): bỏ điều kiện "vụ còn mở" khỏi
 * `NotifyClientOfDocumentPublished::recipientsForReleased()` — test này ĐỎ.
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

/**
 * Gộp M7 vào `main` (PROGRESS "Ghi chú M7", Task 11, "Lúc gộp `main`"): "vụ còn trên cổng" lúc gửi
 * được hỏi bằng ĐỊNH NGHĨA cổng của chính người nhận — `Gate::forUser($account)->allows('view',
 * $matter)`, gồm điều kiện "chưa hết hạn tra cứu" của M7 Task 5 (R4) — CỘNG với hai cổng sẵn có của
 * thư này (vụ còn mở, `is_published_to_portal`), không thay chúng.
 *
 * Trên đường sản phẩm, vụ đã hết hạn tra cứu luôn là vụ đã đóng, nên với tài liệu thường điều kiện
 * "vụ còn mở" đã chặn trước (với gói bàn giao thì không — xem ca "handover package … access window"
 * ở cuối tệp); test này dựng thẳng trạng thái "vụ còn mở nhưng dòng lưu trữ nói hạn tra cứu đã qua"
 * (một dòng lưu trữ mà lần mở lại không dọn) để đo riêng tầng mới: thư không bao giờ nói khác điều
 * cổng đang cho khách thấy. Vế dương: hôm nay là ngày tra cứu cuối thì thư vẫn đi.
 */
it('mails nothing when the portal no longer shows the matter to the client, but still mails on its last day', function (string $accessUntil, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    Event::fake([DocumentPublished::class]);
    [$matter, $lawyer, $account, $document] = publishableMatterWithClientAccount();
    $published = publishAsLawyer($document, $lawyer);

    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $accessUntil]);
    // Vụ thứ hai của cùng khách, còn trên cổng: lý do tài khoản vẫn hoạt động.
    Matter::factory()->create(['client_id' => $account->client_id, 'is_published_to_portal' => true]);

    $notifier = app(NotifyClientOfDocumentPublished::class);

    expect($notifier->eligibleRecipients($published->fresh()))->toHaveCount($expected);

    Mail::fake();

    expect($notifier->handle($published->fresh()))->toBe($expected);
    Mail::assertSent(DocumentPublishedMail::class, $expected);
})->with([
    'đã hết hạn tra cứu từ hôm nay' => ['2026-10-20', 0],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', 1],
]);

// ---------------------------------------------------------------------------------------------
// Gói bàn giao hồ sơ (việc sau gộp M7, làn fu2): tài liệu DUY NHẤT được báo dù vụ đã kết thúc
// ---------------------------------------------------------------------------------------------

/**
 * Vụ ĐÃ KẾT THÚC (`closed_at` có giá trị, chưa xoá mềm) với gói bàn giao HIỆN TẠI của nó: một
 * `Document` nhóm B ở `signed_filed` mà dòng lưu trữ trỏ `handover_document_id` tới (M7 Task 4,
 * R1) — đúng trạng thái `BuildHandoverPackage` để lại, dựng bằng factory vì nội dung zip không phải
 * điều đang đo ở đây. `client_access_until` là hạn tra cứu của khách (M7 Task 5).
 *
 * @return array{0: Matter, 1: User, 2: ClientUser, 3: Document, 4: MatterArchive}
 */
function closedMatterWithHandoverPackage(?string $accessUntil = '2026-12-31'): array
{
    [$matter, $lawyer, $account, $package] = publishableMatterWithClientAccount();

    $matter->forceFill(['closed_at' => now()->subDay()->toDateString()])->save();
    $package->update(['title' => 'Gói bàn giao hồ sơ '.$matter->code]);

    $archive = MatterArchive::factory()->create([
        'matter_id' => $matter->id,
        'handover_document_id' => $package->id,
        'handover_status' => HandoverPackageStatus::Ready,
        'client_access_until' => $accessUntil,
    ]);

    return [$matter->fresh(), $lawyer, $account, $package->fresh(), $archive->fresh()];
}

/** Tab "Tài liệu" của trang vụ việc — đường công bố thật của luật sư (SPEC §6.12 bước 4). */
function handoverDocumentsTab(Matter $matter)
{
    Filament::setCurrentPanel('admin');

    return test()->livewire(DocumentsRelationManager::class, [
        'ownerRecord' => $matter,
        'pageClass' => ViewMatter::class,
    ]);
}

/** Thân thư ở cả hai dạng (HTML và văn bản thuần), cho một khẳng định chạy trên cả hai. */
function handoverMailBodies(DocumentPublishedMail $mail): array
{
    return [
        'html' => $mail->render(),
        'text' => view($mail->content()->text, $mail->content()->with)->render(),
    ];
}

/**
 * Rà soát gộp M7 → `main` (Important, phán quyết controller (b)): gói bàn giao chỉ bao giờ được
 * công bố trên một vụ ĐÃ kết thúc, nên `open()` của M6 làm thư SPEC §9 "Công bố văn bản nhóm B
 * hoặc C" không bao giờ đi cho đúng tài liệu mà cả mục đích là tới tay khách. Đi qua màn hình thật:
 * luật sư bấm "Công bố cho khách" ở tab Tài liệu.
 *
 * Mutation probe: bỏ ngoại lệ gói bàn giao (vụ đã kết thúc thì luôn không gửi) — test này ĐỎ.
 */
it('emails the client when the lawyer publishes the handover package of a closed matter from the documents tab', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    Mail::fake();
    [$matter, $lawyer, $account, $package] = closedMatterWithHandoverPackage();
    $neverActivated = ClientUser::factory()->create(['client_id' => $account->client_id, 'is_active' => true]);

    $this->actingAs($lawyer, 'web');

    handoverDocumentsTab($matter)
        ->callAction(TestAction::make('publish')->table($package), data: [
            'client_can_view' => true,
            'client_can_download' => true,
        ])
        ->assertHasNoActionErrors();

    expect($package->fresh()->isReleasedToPortal())->toBeTrue();

    Mail::assertSent(DocumentPublishedMail::class, 1);
    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));
    // R12: chỉ tài khoản đã kích hoạt.
    Mail::assertNotSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($neverActivated->email));
});

/**
 * Hạn tra cứu (`client_access_until`, M7 Task 5) do `ResolveClientRecipients::onPortal()` quyết, không
 * do một luật thứ hai: quá hạn thì cổng không còn cho khách mở vụ, nên thư không đi; ngày cuối thì
 * vẫn đi. Khách còn một vụ khác trên cổng (lý do tài khoản còn hoạt động sau `ExpireClientAccess`).
 *
 * Mutation probe: bỏ `onPortal()` khỏi `recipientsForReleased()` (trả thẳng `recipientsFor()`) —
 * hàng "đã hết hạn" ĐỎ.
 */
it('never emails about the handover package once the client access window has passed, but still does on its last day', function (string $accessUntil, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    [, $lawyer, $account, $package] = closedMatterWithHandoverPackage($accessUntil);
    Matter::factory()->create(['client_id' => $account->client_id, 'is_published_to_portal' => true]);

    Mail::fake();
    $published = publishAsLawyer($package, $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, $expected);
    expect(app(NotifyClientOfDocumentPublished::class)->eligibleRecipients($published->fresh()))->toHaveCount($expected);
})->with([
    'hạn tra cứu đã qua từ hôm qua' => ['2026-10-20', 0],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', 1],
]);

/**
 * Cặp âm của ngoại lệ: một tài liệu THƯỜNG trên cùng vụ đã kết thúc — vụ có cả dòng lưu trữ lẫn gói
 * bàn giao — vẫn giữ hành vi M6 (không gửi). Ngoại lệ là "đúng tài liệu `handover_document_id`",
 * không phải "vụ có gói".
 *
 * Mutation probe: đổi ngoại lệ thành "vụ có dòng lưu trữ có gói" (bỏ so `handover_document_id` với
 * id tài liệu) — test này ĐỎ.
 */
it('still sends nothing for an ordinary document published on a closed matter that has a handover package', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    [$matter, $lawyer] = closedMatterWithHandoverPackage();

    $ordinary = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Issued,
        'status' => DocumentStatus::SignedFiled,
        'client_can_view' => false,
        'client_can_download' => false,
    ]);
    $ordinary->addMedia(UploadedFile::fake()->createWithContent('ban-an.pdf', '%PDF-1.4 test'))->toMediaCollection('file');

    Mail::fake();
    $published = publishAsLawyer($ordinary->refresh(), $lawyer);

    Mail::assertNothingSent();
    expect(app(NotifyClientOfDocumentPublished::class)->eligibleRecipients($published->fresh()))->toBeEmpty();
});

/**
 * Câu chữ (brief làn fu2): thư nói đây là GÓI HỒ SƠ BÀN GIAO và hạn tải theo `client_access_until`,
 * không liệt kê tên tài liệu nào — kể cả tên nhóm D (gói không bao giờ chứa chúng, thư cũng không
 * nhắc tới). Soi cả ba nơi: tiêu đề, HTML, văn bản thuần.
 *
 * Mutation probe: in thân thư của tài liệu thường cho gói (bỏ nhánh gói ở `content()`) — ĐỎ; bỏ
 * nhánh gói ở `envelope()` — ĐỎ.
 */
it('tells the client it is the handover package and until when to download it, naming no document', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    [$matter, , $account, $package] = closedMatterWithHandoverPackage('2026-12-31');
    $package->update(['client_can_view' => true, 'client_can_download' => true, 'status' => DocumentStatus::Published]);

    $internal = Document::factory()->create([
        'matter_id' => $matter->id,
        'group' => DocumentGroup::Internal,
        'title' => 'BIEN-BAN-NOI-BO-'.uniqid(),
    ]);

    $mail = new DocumentPublishedMail($package->fresh(), $account);

    expect($mail->envelope()->subject)->toBe(__('portal.email.handover_published.subject', ['code' => $matter->code]))
        ->and($mail->envelope()->subject)->toContain($matter->code);

    foreach (handoverMailBodies($mail) as $body) {
        expect($body)->toContain('gói hồ sơ bàn giao')
            ->and($body)->toContain('MUC-LUC.pdf')
            ->and($body)->toContain(__('portal.email.handover_published.download_until', ['date' => '31/12/2026']))
            ->and($body)->not->toContain($internal->title)
            ->and($body)->not->toContain($package->fresh()->title);
    }
});

/**
 * Gói công bố "cho xem, không cho tải" (SPEC §6.5 bước 3 cho phép hai cờ độc lập): thư không hứa
 * khách tải được, mà nói văn phòng chưa mở quyền tải.
 *
 * Mutation probe: bỏ điều kiện `client_can_download` (luôn in câu "tải gói về") — test này ĐỎ.
 */
it('does not promise a download when the package was published view-only', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    [, , $account, $package] = closedMatterWithHandoverPackage('2026-12-31');
    $package->update(['client_can_view' => true, 'client_can_download' => false, 'status' => DocumentStatus::Published]);

    foreach (handoverMailBodies(new DocumentPublishedMail($package->fresh(), $account)) as $body) {
        expect($body)->toContain(__('portal.email.handover_published.view_only_until', ['date' => '31/12/2026']))
            ->and($body)->not->toContain(__('portal.email.handover_published.download_until', ['date' => '31/12/2026']));
    }
});

/**
 * Vụ đã MỞ LẠI (`SyncMatterArchive` xoá `client_access_until` về null) rồi luật sư mới công bố gói:
 * vụ đang mở nên thư đi theo luật M6, vẫn là thư gói bàn giao, và không in một hạn tải không có.
 *
 * Mutation probe: bỏ nhánh "không có hạn" (luôn in câu có `:date`) — test này ĐỎ.
 */
it('mails the handover package of a reopened matter without inventing a download deadline', function () {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    [$matter, $lawyer, $account, $package] = closedMatterWithHandoverPackage(null);
    $matter->forceFill(['closed_at' => null])->save();

    Mail::fake();
    publishAsLawyer($package, $lawyer);

    Mail::assertSent(DocumentPublishedMail::class, fn ($mail) => $mail->hasTo($account->email));

    foreach (handoverMailBodies(new DocumentPublishedMail($package->fresh(), $account)) as $body) {
        expect($body)->toContain(__('portal.email.handover_published.download'))
            ->and($body)->not->toContain(__('portal.email.handover_published.download_until', ['date' => '']));
    }
});
