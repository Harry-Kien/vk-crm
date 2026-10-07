<?php

use App\Enums\ChecklistItemStatus;
use App\Enums\Confidentiality;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendMissingDocumentsMail;
use App\Mail\Client\MissingDocuments;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\MatterChecklistItem;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Hành vi RIÊNG của job (đọc lại lúc chạy, kiểm tra lại trước khi gửi) — tách khỏi câu hỏi "job có
 * được xếp đúng lúc, đúng hồ sơ hay không" (việc của `tests/Feature/Schedule/
 * RemindMissingDocumentsTest.php`). Mọi test ở đây gọi `->handle()`/`->failed()` TRỰC TIẾP trên một
 * instance job tự dựng — cùng phong cách `SendStaleMatterMailTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{0: Matter, 1: ClientUser, 2: User, 3: MatterChecklistItem} */
function jobMatter(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id]);
    $item = MatterChecklistItem::factory()->for($matter)->create([
        'name' => 'Chứng minh nhân dân',
        'is_required' => true,
        'status' => ChecklistItemStatus::Missing,
    ]);

    return [$matter, $account, $lawyer, $item];
}

it('mails the eligible accounts the list of what is missing, computed fresh from the database', function () {
    Mail::fake();
    [$matter, $account, , $item] = jobMatter();

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertSent(MissingDocuments::class, 1);
    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email)
        && $mail->matter->is($matter)
        && $mail->items->pluck('id')->all() === [$item->id]);
});

/**
 * "Job TÍNH LẠI danh sách còn thiếu lúc gửi": khách vừa nộp một trong hai giấy tờ (văn phòng chưa
 * duyệt — `pending_review`) giữa lúc xếp hàng và lúc chạy → thư chỉ liệt kê cái còn lại.
 *
 * Đo hành vi (job đọc danh sách lúc chạy, không mang danh sách từ lúc dispatch — payload chỉ có
 * `matterId`), không có probe riêng: không có chỗ nào để "chụp sẵn" một danh sách.
 */
it('lists only what is still missing at send time, not what was missing when it was queued', function () {
    Mail::fake();
    [$matter, , , $first] = jobMatter();
    $second = MatterChecklistItem::factory()->for($matter)->create(['name' => 'Sổ hộ khẩu', 'is_required' => true, 'status' => ChecklistItemStatus::Missing]);
    $job = new SendMissingDocumentsMail($matter->id);

    $first->update(['status' => ChecklistItemStatus::PendingReview]);

    $job->handle();

    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->items->pluck('id')->all() === [$second->id]);
});

/**
 * Mutation probe: thay `ChecklistProgress::mattersAwaitingClient(...)->first()` trong
 * `SendMissingDocumentsMail::handle()` bằng `Matter::query()->find(...)` (bỏ kiểm tra "còn thiếu")
 * — test này ĐỎ (một thư rỗng đi ra).
 */
it('sends nothing once the client has submitted everything that was missing', function () {
    Mail::fake();
    [$matter, , , $item] = jobMatter();
    $item->update(['status' => ChecklistItemStatus::PendingReview]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertNothingSent();
});

it('skips silently when the matter has been soft-deleted', function () {
    Mail::fake();
    [$matter] = jobMatter();
    $id = $matter->id;
    $matter->delete();

    (new SendMissingDocumentsMail($id))->handle();

    Mail::assertNothingSent();
});

it('skips silently when the matter no longer exists at all', function () {
    Mail::fake();

    (new SendMissingDocumentsMail(999999))->handle();

    Mail::assertNothingSent();
});

it('skips silently when the matter has been closed since it was queued', function () {
    Mail::fake();
    [$matter] = jobMatter();
    $matter->update(['closed_at' => now()]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertNothingSent();
});

it('skips silently when the matter has been unpublished from the portal since it was queued', function () {
    Mail::fake();
    [$matter] = jobMatter();
    $matter->update(['is_published_to_portal' => false]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/**
 * Việc sau gộp M7 (làn fu2): thư cho khách về một vụ hỏi "vụ còn trên cổng của CHÍNH người nhận"
 * bằng `ResolveClientRecipients::onPortal()` — như bốn thư khách còn lại — không chỉ cờ
 * `is_published_to_portal` của `mattersAwaitingClient()`. Trạng thái dựng thẳng: một vụ ĐANG MỞ mà
 * dòng lưu trữ còn `client_access_until` đã qua (dòng mà một lần mở lại không dọn — cùng trạng thái
 * `DocumentPublishedNotificationTest` dùng), khách còn một vụ khác trên cổng. Cổng không cho khách
 * mở vụ, nên thư (kèm liên kết tới một trang 404) không đi, và nút "Gửi lại" hỏi cùng câu. Vế dương:
 * hôm nay là ngày tra cứu cuối thì thư vẫn đi.
 *
 * Mutation probe: bỏ `onPortal()` khỏi `SendMissingDocumentsMail::context()` — hàng "đã hết hạn" ĐỎ.
 */
it('mails nobody once the portal no longer shows the matter to the client, but still mails on its last day', function (string $accessUntil, int $expected) {
    $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
    Mail::fake();
    [$matter, $account] = jobMatter();
    MatterArchive::factory()->create(['matter_id' => $matter->id, 'client_access_until' => $accessUntil]);
    Matter::factory()->create(['client_id' => $account->client_id, 'is_published_to_portal' => true]);

    $job = new SendMissingDocumentsMail($matter->id);

    expect($job->eligibleRecipients())->toHaveCount($expected);

    $job->handle();

    Mail::assertSent(MissingDocuments::class, $expected);
})->with([
    'hạn tra cứu đã qua từ hôm qua' => ['2026-10-20', 0],
    'hôm nay là ngày tra cứu cuối' => ['2026-10-21', 1],
]);

/** R12 lúc gửi: tài khoản bị khoá giữa lúc xếp hàng và lúc chạy thì không nhận. */
it('re-derives the recipients at send time (R12)', function () {
    Mail::fake();
    [$matter, $account] = jobMatter();
    $job = new SendMissingDocumentsMail($matter->id);

    $account->update(['is_active' => false]);

    $job->handle();

    Mail::assertNothingSent();
});

it('does not mail an account twice when it already has a sent ledger row within 3 days', function () {
    Mail::fake();
    [$matter, $account] = jobMatter();
    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $account->email,
        'template' => 'client.missing_documents',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDay(),
    ]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertNothingSent();
});

it('still mails an account whose only ledger row in the window is a failed send', function () {
    Mail::fake();
    [$matter, $account] = jobMatter();
    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $account->email,
        'template' => 'client.missing_documents',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Failed,
        'sent_at' => now()->subHour(),
    ]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email));
});

it('mails an account whose last send was stamped seconds after the morning run 3 days ago', function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-14 08:00:02'));
    [$matter, $account] = jobMatter();
    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $account->email,
        'template' => 'client.missing_documents',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => Carbon::parse('2026-10-11 08:00:40'),
    ]);

    (new SendMissingDocumentsMail($matter->id))->handle();

    Mail::assertSent(MissingDocuments::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * "Thử lại không gửi trùng": người thứ hai làm transport ném lỗi → `handle()` ném lại, hàng đợi thả
 * job, lần thử sau chạy TỪ ĐẦU. Người thứ nhất (đã nhận) không được nhận bản thứ hai. KHÔNG
 * `Mail::fake()`: cần `outbound_messages` ghi THẬT qua cánh cửa framework.
 */
it('does not re-mail the first account when the job retries after the second account failed', function () {
    [$matter, $first] = jobMatter();
    $failing = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id, 'email' => 'khach-hong@vidu.test']);

    config(['mail.default' => missingDocsSelectiveFailMailer('khach-hong@vidu.test')]);

    $job = new SendMissingDocumentsMail($matter->id);

    foreach ([1, 2] as $attempt) {
        try {
            $job->handle();
        } catch (TransportException) {
            // Đúng kỳ vọng: một người nhận, một người hỏng — ngoại lệ thoát ra ngoài.
        }
    }

    $sentToFirst = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $first->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentToFirst)->toBe(1);
});

/** Người hỏng KHÔNG chặn người sau, và job vẫn ném lại để hàng đợi thấy nó hỏng. */
it('keeps trying the remaining accounts after one fails, then rethrows the first failure', function () {
    [$matter, $failing] = jobMatter();
    // Người hỏng có id nhỏ hơn nên đứng TRƯỚC người ổn trong thứ tự trả về.
    $failing->update(['email' => 'khach-hong@vidu.test']);
    $ok = ClientUser::factory()->activated()->create(['client_id' => $matter->client_id]);

    config(['mail.default' => missingDocsSelectiveFailMailer('khach-hong@vidu.test')]);

    expect(fn () => (new SendMissingDocumentsMail($matter->id))->handle())->toThrow(TransportException::class);

    $sentToOk = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $ok->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentToOk)->toBe(1);
});

class MissingDocsSelectiveFailTransport implements TransportInterface
{
    public function __construct(private readonly string $failingAddress) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($message instanceof Email) {
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $this->failingAddress) {
                    throw new TransportException('SMTP từ chối '.$this->failingAddress);
                }
            }
        }

        return new SentMessage($message, new Envelope(
            new Address('gui@vidu.test'),
            [new Address('nhan@vidu.test')],
        ));
    }

    public function __toString(): string
    {
        return 'missing-docs-selective-fail://';
    }
}

function missingDocsSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.missing_docs_selective_fail', ['transport' => 'missing_docs_selective_fail']);
    Mail::extend('missing_docs_selective_fail', fn () => new MissingDocsSelectiveFailTransport($failingAddress));

    return 'missing_docs_selective_fail';
}

// ---------------------------------------------------------------------------------------------
// failed()
// ---------------------------------------------------------------------------------------------

it('writes an audit row and notifies the lead lawyer when the job permanently fails', function () {
    [$matter, , $lawyer] = jobMatter();
    $manager = User::factory()->withRole(Role::Manager)->create();

    (new SendMissingDocumentsMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'missing_documents_reminder_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('matter_id'))->toBe($matter->id)
        ->and($lawyer->notifications()->count())->toBe(1)
        // Chỉ luật sư phụ trách (người gọi điện cho khách), không phải mọi manager.
        ->and($manager->notifications()->count())->toBe(0);
});

/** R3: luật sư phụ trách bị vô hiệu hoá thì chuỗi dự phòng thế chỗ, không im lặng. */
it('falls back down the recipient chain when the lead lawyer is deactivated', function () {
    [$matter, , $lawyer] = jobMatter();
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    (new SendMissingDocumentsMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($lawyer->notifications()->count())->toBe(0)
        ->and($manager->notifications()->count())->toBe(1);
});

/** Review Focus 1: vụ `restricted` — manager không xem được không nhận, admin thế chỗ. */
it('notifies an admin instead of a manager when a restricted matter permanently fails', function () {
    [$matter, , $lawyer] = jobMatter();
    $matter->update(['confidentiality' => Confidentiality::Restricted]);
    $lawyer->update(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    (new SendMissingDocumentsMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($admin->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0);
});

it('escapes the matter code in the failure notification body', function () {
    [$matter, , $lawyer] = jobMatter();
    $matter->forceFill(['code' => 'X<a href="x">y</a>'])->saveQuietly();

    (new SendMissingDocumentsMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $body = $lawyer->notifications()->first()->data['body'];
    expect($body)->not->toContain('<a href')->and($body)->toContain('&lt;a href');
});

// ---------------------------------------------------------------------------------------------
// Khe hở giữa hai lần đọc của chính job
// ---------------------------------------------------------------------------------------------

/**
 * Job đọc hồ sơ (kiểm tra tập §6.9) RỒI mới đọc danh sách đầu mục. Nếu khách nộp nốt giấy tờ đúng
 * giữa hai lần đọc đó, danh sách rỗng — thư rỗng ("anh/chị vui lòng gửi những giấy tờ sau:" và
 * không có gì) không bao giờ được đi ra.
 *
 * Mutation probe: xoá khối `if ($items->isEmpty()) { return; }` khỏi `SendMissingDocumentsMail::
 * handle()` — test này ĐỎ (một thư với danh sách rỗng).
 */
it('never sends an empty list when the last item is submitted between the two reads', function () {
    Mail::fake();
    [$matter, , , $item] = jobMatter();

    $fired = false;
    DB::listen(function ($query) use (&$fired, $item) {
        if (! $fired && preg_match('/^select \* from [`"]matters[`"]/i', $query->sql) === 1) {
            $fired = true;
            DB::table('matter_checklist_items')->where('id', $item->id)->update(['status' => 'pending_review']);
        }
    });

    (new SendMissingDocumentsMail($matter->id))->handle();

    expect($fired)->toBeTrue();
    Mail::assertNothingSent();
});
