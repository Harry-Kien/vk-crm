<?php

use App\Enums\Confidentiality;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendStaleMatterMail;
use App\Mail\Staff\StaleMatterReminder;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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
 * Hành vi RIÊNG của job (đọc lại tại thời điểm chạy, re-check trước khi gửi) — tách khỏi câu hỏi
 * "job có được dispatch đúng lúc, đúng người hay không" (việc của
 * `tests/Feature/Schedule/CheckStaleMattersTest.php`). Mọi test ở đây gọi `->handle()`/`->failed()`
 * TRỰC TIẾP trên một instance job tự dựng — cùng phong cách
 * `tests/Feature/Jobs/SendDeadlineReminderMailTest.php`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function staleMatterWithLawyer(int $daysSinceUpdate = 22): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays($daysSinceUpdate),
    ]);

    return [$matter, $lawyer];
}

it('mails the recipients, computed fresh from the database', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email) && $mail->matter->is($matter));
});

it('skips silently when the matter has been soft-deleted', function () {
    Mail::fake();
    [$matter] = staleMatterWithLawyer();
    $id = $matter->id;
    $matter->delete();

    (new SendStaleMatterMail($id))->handle();

    Mail::assertNothingSent();
});

/**
 * Vụ việc được cập nhật cho khách GIỮA lúc `CheckStaleMatters` xếp job này và lúc nó thật sự chạy
 * — job phải bỏ qua, không gửi một thư nói sai sự thật. (Điều kiện chặn ở đây thật ra là
 * `MatterStaleness::olderThan()`, không phải `scopeStale()` — một cập nhật vừa xảy ra đưa
 * `daysSinceUpdate` về 0, dưới cả hai ngưỡng; xem hai test dưới cho mutation probe RIÊNG của từng
 * điều kiện.)
 */
it('skips silently when the matter has been updated for the client since it was queued', function () {
    Mail::fake();
    [$matter] = staleMatterWithLawyer();

    $matter->update(['last_client_update_at' => now()]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/**
 * Đóng vụ không đổi đồng hồ cập nhật — CHỈ `MatterStaleness::scopeStale()` (qua `Matter::open()`)
 * bắt được trường hợp này, `olderThan()` một mình sẽ không thấy gì khác.
 *
 * Mutation probe: xoá điều kiện `MatterStaleness::scopeStale(...)->exists()` khỏi
 * `SendStaleMatterMail::handle()` — test này ĐỎ (thư vẫn gửi dù vụ vừa đóng).
 */
it('skips silently when the matter has been closed since it was queued', function () {
    Mail::fake();
    [$matter] = staleMatterWithLawyer();

    $matter->update(['closed_at' => now()]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/** Cặp dương của test trên, cho vế "tắt cổng" của cùng điều kiện `scopeStale()`. */
it('skips silently when the matter has been unpublished from the portal since it was queued', function () {
    Mail::fake();
    [$matter] = staleMatterWithLawyer();

    $matter->update(['is_published_to_portal' => false]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/**
 * Mutation probe: xoá điều kiện `MatterStaleness::olderThan($matter, EMAIL_AFTER_DAYS)` khỏi
 * `SendStaleMatterMail::handle()` — test này ĐỎ (thư vẫn gửi cho một vụ mới 15 ngày, chưa tới mốc
 * email).
 */
it('skips silently when the matter has not reached the 21-day email threshold', function () {
    Mail::fake();
    [$matter] = staleMatterWithLawyer(15);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/**
 * Vòng sửa "re-derive audience at send time": job không nhận một danh sách người nhận nào — nó tự
 * gọi lại `CheckStaleMatters::recipientsFor()` lúc THẬT SỰ chạy, nên một vụ vừa chuyển `restricted`
 * GIỮA lúc dispatch và lúc job chạy vẫn được tôn trọng đúng luật mới nhất.
 */
it('re-derives the audience at send time, so a matter turning restricted after dispatch is honored', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $matter->update(['confidentiality' => Confidentiality::Restricted]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($admin->email));
    Mail::assertNotSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($manager->email));
});

it('does not mail a recipient twice when they already have a sent ledger row within 7 days', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDays(2),
    ]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: một dòng đã gửi CŨ hơn 7 ngày không chặn — người đó vẫn nhận thư mới. */
it('still mails a recipient whose only sent ledger row is more than 7 days old', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDays(9),
    ]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * Chỉ thư CÙNG MẪU mới tính là "đã nhắc": một thư khác về cùng vụ việc, tới cùng người (ví dụ nhắc
 * mốc thời hạn, hay thư khách trả lời) trong 7 ngày qua không được nuốt mất lời nhắc này.
 *
 * Mutation probe: xoá `->where('template', 'staff.stale_matter')` khỏi
 * `SendStaleMatterMail::alreadyDelivered()` — test này ĐỎ (không ai nhận thư).
 */
it('is not silenced by a sent mail of another template about the same matter', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.new_client_request',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Sent,
        'sent_at' => now()->subDay(),
    ]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * Một dòng `failed` trong 7 ngày qua (lượt gửi trước hỏng) không phải "đã nhận": người đó vẫn phải
 * được gửi lại. `sent_at` gán tường minh để test đo ĐÚNG điều kiện `status = sent`, không để điều
 * kiện `sent_at` bên cạnh che mất (cùng lý lẽ test "failed send" của CheckStaleMattersTest).
 *
 * Mutation probe: xoá `->where('status', OutboundStatus::Sent)` khỏi
 * `SendStaleMatterMail::alreadyDelivered()` — test này ĐỎ (không ai nhận thư).
 */
it('still mails a recipient whose only ledger row in the last 7 days is a failed send', function () {
    Mail::fake();
    [$matter, $lawyer] = staleMatterWithLawyer();

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.stale_matter',
        'payload' => [],
        'related_type' => $matter->getMorphClass(),
        'related_id' => $matter->getKey(),
        'status' => OutboundStatus::Failed,
        'error' => 'Thử nghiệm',
        'sent_at' => now()->subDay(),
    ]);

    (new SendStaleMatterMail($matter->id))->handle();

    Mail::assertSent(StaleMatterReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * "Không gửi trùng khi thử lại": nếu người thứ hai làm transport ném lỗi, `handle()` ném lại và
 * hàng đợi thả job — lần thử tiếp theo chạy lại `handle()` TỪ ĐẦU. Người ĐẦU (đã nhận thành công ở
 * lượt trước) không được nhận thêm bản thứ hai. KHÔNG `Mail::fake()`: cần `outbound_messages` ghi
 * THẬT qua cánh cửa framework để `alreadyDelivered()` có gì mà tra.
 */
it('does not re-mail the first recipient when the job retries after the second recipient failed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $failingManager = User::factory()->withRole(Role::Manager)->create(['email' => 'quanly-hong@vidu.test']);
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'last_client_update_at' => now()->subDays(22),
    ]);

    config(['mail.default' => staleMatterSelectiveFailMailer('quanly-hong@vidu.test')]);

    $job = new SendStaleMatterMail($matter->id);

    try {
        $job->handle();
    } catch (TransportException) {
        // Đúng kỳ vọng: người 1 (lawyer) đã nhận, người 2 (manager) hỏng — ngoại lệ thoát ra ngoài.
    }

    try {
        $job->handle();
    } catch (TransportException) {
        //
    }

    $sentToLawyer = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $lawyer->email)
        ->where('status', OutboundStatus::Sent)
        ->count();

    expect($sentToLawyer)->toBe(1);
});

class StaleMatterSelectiveFailTransport implements TransportInterface
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
        return 'stale-matter-selective-fail://';
    }
}

function staleMatterSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.stale_matter_selective_fail', ['transport' => 'stale_matter_selective_fail']);
    Mail::extend('stale_matter_selective_fail', fn () => new StaleMatterSelectiveFailTransport($failingAddress));

    return 'stale_matter_selective_fail';
}

// ---------------------------------------------------------------------------------------------
// failed()
// ---------------------------------------------------------------------------------------------

it('writes an audit row and notifies the lead lawyer and manager when the job permanently fails', function () {
    [$matter, $lawyer] = staleMatterWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    (new SendStaleMatterMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'stale_matter_reminder_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('matter_id'))->toBe($matter->id);

    expect($lawyer->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(1);
});

/** Vụ `restricted`: failed() phải thấy admin thay manager qua supervisorsFor(), không một nhánh riêng. */
it('notifies an admin instead of a manager when a restricted matter permanently fails', function () {
    [$matter, $lawyer] = staleMatterWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $matter->update(['confidentiality' => Confidentiality::Restricted]);

    (new SendStaleMatterMail($matter->id))->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($admin->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0)
        ->and($lawyer->notifications()->count())->toBe(1);
});
