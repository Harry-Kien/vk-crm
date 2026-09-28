<?php

use App\Actions\Document\SubmitClientDocument;
use App\Actions\Notification\NotifyStaffOfNewClientDocument;
use App\Enums\Confidentiality;
use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Events\ClientDocumentSubmitted;
use App\Listeners\SendNewClientDocumentNotification;
use App\Mail\Staff\NewClientDocument as NewClientDocumentMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\NewClientDocumentAlert;
use App\Notifications\Staff\NewClientDocumentMailFailedAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC §9 `staff.new_client_document` — khách nộp tài liệu qua cổng, kích hoạt bởi
 * `App\Events\ClientDocumentSubmitted` (`App\Actions\Document\SubmitClientDocument`, một sự kiện
 * cho MỘT LẦN NỘP — M6.5 Task 17).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
});

function documentSubmissionPdf(string $name = 'giay-to.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
}

/** @return array{0: Matter, 1: User, 2: MatterChecklistItem, 3: ClientUser} */
function documentSubmissionFixture(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create(['name' => 'Giấy chứng nhận quyền sử dụng đất']);
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);

    return [$matter, $lawyer, $item, $clientUser];
}

/**
 * `SubmitClientDocument::handle()` trả `Illuminate\Support\Collection` (nó chỉ dựng một
 * `EloquentCollection` NỘI BỘ để dispatch sự kiện — xem docblock `App\Events\
 * ClientDocumentSubmitted`) — bọc lại ở đây cho đúng kiểu mà `NotifyStaffOfNewClientDocument::
 * handle()` đòi, khi test cần tự gọi lại Action đó thủ công.
 *
 * @return EloquentCollection<int, Document>
 */
function submitDocuments(MatterChecklistItem $item, ClientUser $actor, int $count = 1): EloquentCollection
{
    $files = array_map(fn (int $i): UploadedFile => documentSubmissionPdf('tep-'.$i.'.pdf'), range(1, $count));

    $documents = app(SubmitClientDocument::class)->handle(checklistItem: $item, actor: $actor, files: $files);

    return EloquentCollection::make($documents->all());
}

it('emails the lead lawyer when a client submits a document', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();

    submitDocuments($item, $clientUser);

    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

it('notifies the lead lawyer in-app when a client submits a document', function () {
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();

    submitDocuments($item, $clientUser);

    expect($lawyer->fresh()->notifications()->where('type', NewClientDocumentAlert::class)->count())->toBe(1);
});

/**
 * Fix round 1 (finding Critical 1), cùng lỗ hổng ở `NewClientDocumentAlert::toDatabase()`: tên
 * đầu mục danh mục hồ sơ (`item`) không escape trước khi nội suy vào body, và Filament render
 * body của thông báo trong hệ thống bằng `str($body)->sanitizeHtml()` — sanitizer của nó giữ lại
 * `<a href>`/style. Tên đầu mục do VĂN PHÒNG gõ (không phải khách), nhưng vẫn là dữ liệu tự do —
 * cùng kênh hở, cùng cách vá.
 *
 * Mutation probe: bỏ `e()` quanh `item` ở `NewClientDocumentAlert::toDatabase()` — test này ĐỎ.
 */
it('never lets HTML in the checklist item name survive as live markup in the staff alert', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $item->update(['name' => '<a href="https://evil.example/login" style="position:fixed;inset:0;background:#fff">Phiên đăng nhập hết hạn</a>']);

    submitDocuments($item->fresh(), $clientUser);

    $row = $lawyer->fresh()->notifications()->where('type', NewClientDocumentAlert::class)->first();
    $html = Notification::fromDatabase($row)->toEmbeddedHtml();

    expect($html)->not->toContain('href="https://evil.example/login"')
        ->and($html)->not->toContain('style="position:fixed')
        ->and($html)->not->toContain('<a ')
        ->and($html)->toContain('Phiên đăng nhập hết hạn');
});

it('also emails every team assistant of the matter', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    submitDocuments($item, $clientUser);

    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($assistant->email));
});

/**
 * M6.5 Task 17 ruling: MỘT lần nộp nhiều tệp (CCCD hai mặt) = MỘT sự kiện, MỘT thư — không phải
 * một thư mỗi tệp. Đo bằng số THƯ, và thân thư nói đúng số tệp thật.
 */
it('sends exactly one mail for a two-file submission, naming the real count', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();

    submitDocuments($item, $clientUser, 2);

    Mail::assertSent(NewClientDocumentMail::class, 1);
    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->count === 2);
});

/**
 * Một trong hai tệp của lô bị gỡ (xoá mềm) GIỮA lúc sự kiện bắn và lúc job chạy — thư phải nói số
 * THẬT lúc gửi, không phải ảnh chụp lúc dispatch.
 *
 * Mutation probe: đổi `freshCount()` để LUÔN trả `$fallback` (bỏ câu đếm lại) — test này ĐỎ.
 */
it('recounts the batch at send time instead of trusting the count captured at dispatch', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $documents = submitDocuments($item, $clientUser, 2);
    $documents->last()->delete();

    app(NotifyStaffOfNewClientDocument::class)->handle($documents);

    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->count === 1);
});

/**
 * Fix round 1 (finding Important 1): `freshCount()` không còn đếm lại theo
 * `matter_checklist_item_id` + `version` (không lọc nhóm, vi phạm ruling "đọc thẳng collection
 * này, không tự truy vấn lại"), mà đếm THẲNG trong `$documents` của chính sự kiện — một tài liệu
 * NỘI BỘ (nhóm D) mà văn phòng tự gắn vào cùng đầu mục, cùng version KHÔNG có mặt trong lô sự
 * kiện đó nên không được tính là "khách vừa nộp".
 *
 * Mutation probe: đổi `freshCount()` lại thành đếm theo `matter_checklist_item_id` + `version`
 * (bỏ `whereKey($documents->modelKeys())`) — test này ĐỎ (thư nói "2 tệp" thay vì "1 tệp").
 */
it('never counts an office-internal document on the same item as part of the clients submission', function () {
    Mail::fake();
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    Document::factory()->create([
        'matter_id' => $matter->id,
        'matter_checklist_item_id' => $item->id,
        'group' => DocumentGroup::Internal,
        'version' => 1,
    ]);

    submitDocuments($item, $clientUser, 1);

    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->count === 1);
});

/**
 * Fix round 1 (finding Important 1), phần R10: một lần nộp BỔ SUNG (một tệp thêm khi đầu mục còn
 * `pending_review`) tái dùng CÙNG version — nhưng sự kiện thứ hai chỉ mang tài liệu của LẦN NỘP
 * THỨ HAI, nên thư báo đúng "1 tệp", không cộng dồn tệp của lần nộp trước.
 *
 * Mutation probe: cùng đổi ở trên — test này ĐỎ (thư thứ hai nói "2 tệp").
 */
it('does not recount an earlier submissions files when reporting a supplement made while pending review', function () {
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    submitDocuments($item, $clientUser, 1);

    Mail::fake();
    submitDocuments($item->fresh(), $clientUser, 1);

    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->count === 1);
});

it('never tells an inactive lead lawyer, falling back to a manager who can view the matter', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create(['lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);

    submitDocuments($item, $clientUser);

    Mail::assertNotSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($manager->email));
});

/** Review Focus 1 (brief): vụ restricted đi qua thư mới — thay manager bằng admin. */
it('escalates to an admin instead of a manager when the matter is restricted', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $matter = Matter::factory()->for($client)->create([
        'lead_lawyer_id' => $lawyer->id,
        'confidentiality' => Confidentiality::Restricted,
    ]);
    $item = MatterChecklistItem::factory()->for($matter)->create();
    $clientUser = ClientUser::factory()->create(['client_id' => $client->id]);

    submitDocuments($item, $clientUser);

    Mail::assertNotSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($manager->email));
    Mail::assertSent(NewClientDocumentMail::class, fn ($mail) => $mail->hasTo($admin->email));
});

it('never puts the checklist item name in the email subject, only the matter code', function () {
    [$matter, $lawyer, $item] = documentSubmissionFixture();
    $item->update(['name' => 'MOT-TEN-BI-MAT-'.uniqid()]);
    $document = Document::factory()->create(['matter_id' => $matter->id, 'matter_checklist_item_id' => $item->id]);

    $subject = (new NewClientDocumentMail($document, 1, $lawyer))->envelope()->subject;

    expect($subject)->toContain($matter->code)
        ->and($subject)->not->toContain($item->name);
});

/** Cặp dương: tên đầu mục CÓ mặt trong thân thư. */
it('puts the checklist item name in the email body', function () {
    [$matter, $lawyer, $item] = documentSubmissionFixture();
    $item->update(['name' => 'Ten dau muc rieng biet']);
    $document = Document::factory()->create(['matter_id' => $matter->id, 'matter_checklist_item_id' => $item->id]);

    $html = (new NewClientDocumentMail($document, 1, $lawyer))->render();

    expect($html)->toContain('Ten dau muc rieng biet');
});

it('sends nothing when the matter was cancelled before the job ran', function () {
    [$matter, , $item, $clientUser] = documentSubmissionFixture();
    $document = submitDocuments($item, $clientUser)->first();
    $matter->delete();

    Mail::fake();
    $sent = app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document]));

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Mutation probe: bỏ `->open()` khỏi `NotifyStaffOfNewClientDocument::openMatterFor()` — test
 * này ĐỎ.
 */
it('sends nothing when the matter was closed (not soft-deleted) before the job ran', function () {
    [$matter, , $item, $clientUser] = documentSubmissionFixture();
    $document = submitDocuments($item, $clientUser)->first();
    $matter->update(['closed_at' => now()]);

    Mail::fake();
    $sent = app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document]));

    expect($sent)->toBe(0);
    Mail::assertNothingSent();
});

/**
 * Chống gửi trùng qua nhật ký thư — cùng lý do các Notify* khác không `Mail::fake()`.
 * Mutation probe: bỏ `alreadyDelivered()` khỏi `handle()` — test này ĐỎ.
 */
it('never sends the same document notification twice to the same recipient', function () {
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $document = submitDocuments($item, $clientUser)->first();

    app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document->fresh()]));
    app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document->fresh()]));

    $sentRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $lawyer->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentRows)->toBe(1);
});

/** Mutation probe: bỏ `alreadyAlerted()` khỏi `handle()` — test này ĐỎ. */
it('never writes the same in-app alert twice to the same recipient', function () {
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $document = submitDocuments($item, $clientUser)->first();

    app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document->fresh()]));
    app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection([$document->fresh()]));

    expect($lawyer->fresh()->notifications()->where('type', NewClientDocumentAlert::class)->count())->toBe(1);
});

it('does nothing for an empty collection instead of blowing up', function () {
    $sent = app(NotifyStaffOfNewClientDocument::class)->handle(new EloquentCollection);

    expect($sent)->toBe(0);
});

it('wires the ClientDocumentSubmitted event to the listener', function () {
    $listeners = Event::getRawListeners();

    expect(array_key_exists(ClientDocumentSubmitted::class, $listeners))->toBeTrue();
});

it('is a queued listener with a real retry budget', function () {
    $listener = app(SendNewClientDocumentNotification::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->tries)->toBe(5)
        ->and($listener->backoff)->toHaveCount($listener->tries - 1);
});

it('tells the lead lawyer in-app when the document mail fails for good', function () {
    [$matter, $lawyer, $item, $clientUser] = documentSubmissionFixture();
    $document = submitDocuments($item, $clientUser)->first();

    app(NotifyStaffOfNewClientDocument::class)->reportFailure(new EloquentCollection([$document->fresh()]));

    $notice = $lawyer->fresh()->notifications()->where('type', NewClientDocumentMailFailedAlert::class)->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('requests.new_document_failed_notification.title'));
});
