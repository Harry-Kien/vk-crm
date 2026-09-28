<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Mail\OutboundHeaders;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * M6.5 Task 11 — hành vi RIÊNG của job (đọc lại tại thời điểm chạy, re-check trước khi gửi), tách
 * khỏi câu hỏi "job có được dispatch đúng lúc, đúng người hay không" (đó là việc của
 * `tests/Feature/Schedule/CheckDeadlinesTest.php`). Mọi test ở đây gọi `->handle()` TRỰC TIẾP trên
 * một instance job tự dựng — cùng phong cách `tests/Feature/Jobs/RecheckClientIdentityConflictsTest.php`.
 *
 * Vòng sửa 1 (ruling "re-derive the audience at send time"): `SendDeadlineReminderMail` không còn
 * nhận `$recipientIds` — job chỉ mang `deadlineId` + `tierKey`, và tự gọi
 * `CheckDeadlines::recipientsFor()` để tính lại TOÀN BỘ đối tượng nhận thư mỗi lần `handle()` chạy.
 * Vì vậy phần lớn test "re-check is_active/Gate::view" của vòng Task 12 gốc không còn cần thiết ở
 * ĐÂY — chúng đã được `tests/Feature/Schedule/CheckDeadlinesTest.php` phủ kỹ cho chính hàm
 * `recipientsFor()`. Test còn lại ở tệp này chỉ đo NHỮNG GÌ RIÊNG của job: các điều kiện dừng sớm
 * của chính `handle()` (mốc/vụ việc không còn hợp lệ), việc nó THẬT SỰ gọi lại `recipientsFor()`
 * mỗi lần chạy (không có gì để mà "chụp ảnh" nữa), chống gửi trùng khi thử lại, và `failed()`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function deadlineWithLawyer(): array
{
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today()->addDays(3),
        'is_completed' => false,
    ]);

    return [$deadline, $lawyer, $matter];
}

it('mails the responsible lawyer, computed fresh from the database', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email) && $mail->deadline->is($deadline));
});

/**
 * Carry-forward binding của Task 11: vụ việc có thể bị huỷ (`CancelMatter`, Task 5) GIỮA lúc
 * `CheckDeadlines` xếp job này và lúc nó thật sự chạy (có thể trễ tới hàng giờ sau một lần
 * `backoff()`). Job phải BỎ QUA, không ném lỗi.
 *
 * Mutation probe: xoá điều kiện `! Matter::query()->whereKey($deadline->matter_id)->open()->exists()`
 * khỏi `SendDeadlineReminderMail::handle()` — test này ĐỎ vì `Mail::assertNothingSent()` thất bại
 * (RuntimeException: Ran into unexpected mail).
 */
it('skips silently when the matter has been cancelled since the deadline was queued', function () {
    Mail::fake();
    [$deadline, , $matter] = deadlineWithLawyer();
    $matter->delete();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: vụ việc còn nguyên thì vẫn gửi như thường — ghim rằng test trên đỏ vì huỷ vụ, không vì lý do khác. */
it('still mails when the matter has not been cancelled', function () {
    Mail::fake();
    [$deadline] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});

/**
 * Mốc có thể đã bị xoá mềm (R14) hoặc đánh dấu xong giữa lúc job chờ tới lượt — cùng nguyên tắc
 * "không ném lỗi, chỉ bỏ qua" của toàn bộ job này.
 */
it('skips silently when the deadline no longer exists or is already marked complete', function () {
    Mail::fake();
    [$deadline] = deadlineWithLawyer();
    $deadline->update(['is_completed' => true, 'completed_at' => now()]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/**
 * M6.5 Task 14 (R14): xoá một mốc là xoá mềm. Job có thể đã được xếp hàng TRƯỚC khi văn phòng gỡ
 * mốc (`DeleteDeadline`) và chỉ chạy SAU đó — `Deadline::query()->find()` (dòng đầu của
 * `handle()`) mang sẵn `SoftDeletingScope` (từ M1) nên một mốc đã gỡ trả về `null` và job dừng ở
 * ĐÚNG điều kiện đã có, không cần thêm mã.
 *
 * Mutation probe (đã chạy tay, khôi phục sau khi dán bằng chứng vào báo cáo): đổi
 * `Deadline::query()->find($this->deadlineId)` thành `Deadline::withTrashed()->find(...)` —
 * `Mail::assertNothingSent()` thất bại (RuntimeException: Ran into unexpected mail), chứng minh
 * đây là chỗ đang thật sự chặn, không phải một dòng vô hại.
 */
it('skips silently when the deadline has been soft-deleted', function () {
    Mail::fake();
    [$deadline] = deadlineWithLawyer();
    $deadline->delete();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: một mốc còn nguyên (chưa gỡ) thì vẫn gửi như thường — test trên đỏ vì xoá mềm, không vì lý do khác. */
it('still mails when the deadline has not been deleted', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/** `$tries - 1` độ trễ, cùng kỷ luật đã ghim ở `RecheckClientIdentityConflictsTest`. */
it('configures exactly one backoff delay per release', function () {
    $job = new SendDeadlineReminderMail(1, 'd7');

    expect($job->backoff())->toHaveCount($job->tries - 1);
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1 — "re-derive the audience at send time": không còn `$recipientIds` để mà "chụp ảnh",
// nên job PHẢI thấy đúng trạng thái MỚI NHẤT khi `handle()` chạy, dù trạng thái đó đổi SAU KHI
// job đã được khởi tạo (constructor không mang gì ngoài deadlineId/tierKey).
// ---------------------------------------------------------------------------------------------

/**
 * Giữa lúc job được dựng và lúc `handle()` chạy, vụ việc có thể đã bị siết thành `restricted` —
 * job phải thấy trạng thái MỚI, không phải trạng thái lúc dựng (mà giờ cũng không CÓ gì để "chụp
 * ảnh" nữa — điểm khác biệt so với vòng Task 12 gốc).
 *
 * Mutation probe: xem báo cáo — thay lời gọi `CheckDeadlines::recipientsFor()` bằng một danh sách
 * cứng dựng SẴN lúc `handle()` bắt đầu (mô phỏng "chụp ảnh") làm test này đỏ.
 */
it('re-derives the audience when it runs, so a matter turning restricted after construction is honored', function () {
    Mail::fake();
    [$deadline, $lawyer, $matter] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $job = new SendDeadlineReminderMail($deadline->id, 'd1');

    // Đổi trạng thái SAU KHI job đã dựng — không có gì trong constructor để mà "biết trước".
    $matter->update(['confidentiality' => Confidentiality::Restricted]);

    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertNotSent(fn (DeadlineReminder $mail) => $mail->hasTo($manager->email));
});

/** Cặp dương: vụ việc còn bình thường thì quản lý vẫn nhận, như tính lại lúc dispatch sẽ cho ra. */
it('still mails the manager when the matter has not turned restricted', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $job = new SendDeadlineReminderMail($deadline->id, 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($manager->email));
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1, ruling "no duplicate reminders on retry".
// ---------------------------------------------------------------------------------------------

/**
 * Người nhận đã có một dòng `outbound_messages` trạng thái `sent` cho ĐÚNG mốc này và ĐÚNG tiêu
 * đề (bậc) này — đúng dấu vết mà một lượt `handle()` trước đó (thử lại vì người KHÁC trong cùng
 * bậc hỏng) để lại. Lượt `handle()` này phải BỎ QUA người đó, không gửi lần hai.
 *
 * Mutation probe: xoá điều kiện `alreadyDelivered()` khỏi vòng lặp của `handle()` — test này ĐỎ
 * vì thư được gửi lại (`Mail::assertNothingSent()` thất bại).
 */
it('does not mail a recipient twice when they already have a sent ledger row for this exact tier', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.deadline_reminder',
        'payload' => ['subject' => 'Bất kỳ, không quan trọng', 'tier' => 'd3'],
        'related_type' => $deadline->getMorphClass(),
        'related_id' => $deadline->getKey(),
        'status' => OutboundStatus::Sent,
    ]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/**
 * Phân biệt với `NotifyClientOfStageUpdate::alreadyDelivered()` (xem docblock lớp): một dòng
 * `sent` từ một bậc CŨ, KHÁC không được chặn bậc MỚI của HÔM NAY — nếu không, một mốc từng nhắc
 * `d3` tuần trước sẽ KHÔNG BAO GIỜ nhắc được `d1` tuần này, đúng "im lặng" mà cả tác vụ này chống.
 *
 * Mutation probe: xem báo cáo — bỏ `->where('payload->tier', $this->tierKey)` khỏi
 * `alreadyDelivered()` làm chính test này đỏ (bị chặn nhầm bởi dòng `sent` của bậc cũ).
 */
it('still mails a recipient whose only sent ledger row belongs to an earlier, different tier', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer(); // due in 3 days => tier d3

    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.deadline_reminder',
        'payload' => ['subject' => 'Còn 3 ngày: cũ', 'tier' => 'd3'],
        'related_type' => $deadline->getMorphClass(),
        'related_id' => $deadline->getKey(),
        'status' => OutboundStatus::Sent,
    ]);

    // Thời gian trôi: mốc giờ chỉ còn 1 ngày — bậc MỚI (d1), khác bậc của dòng đã gửi ở trên (d3).
    $deadline->update(['due_date' => today()->addDay()]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * Vòng sửa 2, I1 (Important) — ĐÚNG kịch bản của phán quyết: bậc `d1` áp dụng cho CẢ `daysLeft = 1`
 * LẪN `daysLeft = 0` (`tierFor()`: `$daysLeft <= 1`), nên CÙNG một bậc `d1` có thể mang HAI tiêu đề
 * THẬT khác nhau ("Còn 1 ngày" hôm qua, "Hết hạn hôm nay" hôm nay) tuỳ ngày job chạy. Khoá chống
 * gửi trùng theo `payload->subject` (bản trước vòng sửa này) sẽ KHÔNG nhận ra đây là "đã gửi bậc
 * này rồi" — người đã nhận "Còn 1 ngày" hôm qua sẽ nhận thêm "Hết hạn hôm nay" hôm nay, một lần
 * gửi trùng thật sự cho CÙNG một bậc.
 *
 * Với bậc `overdue`, lỗi này lặp lại MỖI NGÀY MÃI MÃI: tiêu đề đổi theo số ngày quá hạn, không bao
 * giờ trùng chính nó, nên chống gửi trùng theo subject không bao giờ nhận ra.
 *
 * Mutation probe: xem báo cáo — quay lại khoá theo `payload->subject` (bản trước) làm chính test
 * này đỏ (lawyer bị gửi lại).
 */
it('does not mail a recipient twice for the same tier even when the real subject text has changed across a day boundary', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today(), // daysLeft = 0 HÔM NAY — vẫn là bậc d1 (0 <= 1).
        'is_completed' => false,
    ]);

    // Giả lập: lawyer đã nhận bậc d1 HÔM QUA, khi due_date còn cách 1 ngày ("Còn 1 ngày" — tiêu đề
    // THẬT của lúc đó), khác hẳn tiêu đề THẬT của HÔM NAY ("Hết hạn hôm nay") — nhưng CÙNG bậc d1.
    OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $lawyer->email,
        'template' => 'staff.deadline_reminder',
        'payload' => ['subject' => 'Còn 1 ngày: '.$deadline->name.' ('.$matter->code.')', 'tier' => 'd1'],
        'related_type' => $deadline->getMorphClass(),
        'related_id' => $deadline->getKey(),
        'status' => OutboundStatus::Sent,
    ]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd1');
    $job->handle();

    // Lawyer KHÔNG nhận lại — đã có dòng sent cho ĐÚNG bậc d1 này rồi, dù tiêu đề khác.
    Mail::assertNotSent(fn (DeadlineReminder $mail) => $mail->hasTo($lawyer->email));
    // Manager (từ supervisorsFor ở bậc d1) chưa từng nhận — vẫn phải được gửi như thường.
    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($manager->email));
});

/**
 * Vòng sửa 2, I1: header nội bộ mang bậc (`X-VKCRM-Ledger-Tier`) không được lọt ra ngoài — cùng
 * luật với Template/Related/Ledger-Id (`notify/notify-11`). Không `Mail::fake()`: cần thư đi qua
 * transport thật (`array`, `phpunit.xml`) để đọc lại thông điệp đã "gửi".
 */
it('never lets the internal tier header reach the wire, but the ledger still records the tier and the due date it is about', function () {
    [$deadline, $lawyer] = deadlineWithLawyer();

    Mail::to($lawyer->email)->send(new DeadlineReminder($deadline, $lawyer, 'd3'));

    $sentEmail = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();

    expect($sentEmail->getHeaders()->has(OutboundHeaders::LEDGER_TIER))->toBeFalse();

    // Fix round 1 (C1): khoá là `bậc@ngày đến hạn` — xem `DeadlineReminder::ledgerTier()`.
    $row = OutboundMessage::query()->withoutGlobalScopes()->sole();
    expect($row->payload['tier'] ?? null)->toBe('d3@'.$deadline->due_date->toDateString());
});

/** Cặp dương: không có dòng `sent` nào từ trước thì vẫn gửi như thường — test trên đỏ đúng vì dòng đã có, không vì lý do khác. */
it('still mails a recipient who has no prior sent ledger row for this tier', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});

/**
 * Kịch bản trọn vẹn của ruling: hai người nhận CÙNG bậc, một transport hỏng CHỌN LỌC cho đúng một
 * địa chỉ. Lượt đầu: người 1 nhận thành công (dòng `sent`), người 2 hỏng (ném ngoại lệ, thoát khỏi
 * `handle()`). Lượt "thử lại" (gọi lại `handle()`, đúng cách hàng đợi thật thả lại job): người 1
 * KHÔNG được gửi lần hai, chỉ người 2 được thử lại.
 */
it('does not re-mail the first recipient when the job retries after the second recipient failed', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $failingManager = User::factory()->withRole(Role::Manager)->create(['email' => 'quanly-hong@vidu.test']);

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today()->addDay(),
        'is_completed' => false,
    ]);

    config(['mail.default' => deadlineJobSelectiveFailMailer('quanly-hong@vidu.test')]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd1');

    try {
        $job->handle();
    } catch (TransportException) {
        // Đúng kỳ vọng: người 1 (lawyer, xử lý trước theo thứ tự ResolveStaffRecipients) đã nhận,
        // người 2 (manager) hỏng — ngoại lệ thoát ra ngoài, đúng như hàng đợi thật sẽ thấy.
    }

    // "Thử lại": hàng đợi thật gọi lại chính `handle()` này trên CÙNG instance job.
    try {
        $job->handle();
    } catch (TransportException) {
        //
    }

    $sentToLawyer = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $lawyer->email)->where('status', OutboundStatus::Sent)->count();

    expect($sentToLawyer)->toBe(1);
});

/** Transport hỏng cho ĐÚNG MỘT địa chỉ, dùng riêng cho test retry ở trên. */
class DeadlineJobSelectiveFailTransport implements TransportInterface
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
        return 'deadline-job-selective-fail://';
    }
}

function deadlineJobSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.deadline_job_selective_fail', ['transport' => 'deadline_job_selective_fail']);
    Mail::extend('deadline_job_selective_fail', fn () => new DeadlineJobSelectiveFailTransport($failingAddress));

    return 'deadline_job_selective_fail';
}

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1, C1 (critical): job hỏng HẲN không được để mốc mất vĩnh viễn.
// ---------------------------------------------------------------------------------------------

/**
 * `responsible` và `leadLawyer` là HAI người KHÁC NHAU — để phân biệt được ba nhóm nhận thông
 * báo của C1 (người phụ trách mốc, luật sư phụ trách vụ, quản trị) không lẫn vào nhau. Người
 * phụ trách được thêm vào đội ngũ vụ việc, đúng SPEC (`lang/vi/deadlines.php`:
 * "Chỉ chọn được người trong đội ngũ vụ việc"), để `Gate::view()` của `ResolveStaffRecipients`
 * không tình cờ loại họ ra vì lý do KHÁC với điều đang được đo.
 */
function deadlineWithDistinctResponsibleAndLead(): array
{
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $matter->addTeamMember($responsible, MatterRole::Associate);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDays(7),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    return [$deadline, $responsible, $lead, $matter];
}

/**
 * C1 (critical, vòng sửa 1): trước bản sửa này, `reminders_sent` được đánh dấu TRƯỚC khi job gửi
 * (Task 11), nhưng không có gì rút lại đánh dấu đó nếu job hỏng HẲN — mốc `d1`/`overdue` mất
 * vĩnh viễn, không lần chạy `CheckDeadlines` nào sau đó còn thử lại.
 *
 * Mutation probe: xoá khối un-mark tier khỏi `SendDeadlineReminderMail::failed()` — test này ĐỎ
 * vì `reminders_sent` vẫn còn `'d7'` (xem báo cáo).
 */
it('un-marks the tier in reminders_sent when the job permanently fails, so it is not lost forever', function () {
    [$deadline] = deadlineWithDistinctResponsibleAndLead();
    $deadline->update(['reminders_sent' => ['d14', 'd7']]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($deadline->fresh()->reminders_sent)->not->toContain('d7');
});

/** Cặp dương: một bậc KHÁC bậc đang hỏng (đã gửi thật ở một lượt trước) không bị đụng tới. */
it('leaves every other tier untouched when only one tier failed', function () {
    [$deadline] = deadlineWithDistinctResponsibleAndLead();
    $deadline->update(['reminders_sent' => ['d14', 'd7']]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($deadline->fresh()->reminders_sent)->toBe(['d14']);
});

/**
 * C1, phần "surface the failure in the app": một dòng audit mang ID và bậc (KHÔNG mang gì khác),
 * và thông báo trong ứng dụng cho người phụ trách mốc + luật sư phụ trách vụ. Không có manager
 * hay admin nào trong kịch bản này (vụ THƯỜNG, không tạo ai khác) — vòng sửa 1, M1: `failed()`
 * giờ cộng {@see ResolveStaffRecipients::supervisorsFor()} thay vì "mọi
 * admin đang hoạt động" không điều kiện, nên một vụ THƯỜNG không có manager thì không kéo thêm ai.
 */
it('writes an audit row and notifies the responsible user and the lead when the job permanently fails', function () {
    [$deadline, $responsible, $lead] = deadlineWithDistinctResponsibleAndLead();

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'deadline_reminder_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('deadline_id'))->toBe($deadline->id)
        ->and($audit->properties->get('tier'))->toBe('d7');

    expect($responsible->notifications()->count())->toBe(1)
        ->and($lead->notifications()->count())->toBe(1);
});

/**
 * Vòng sửa 1, M1: trên một vụ THƯỜNG, `failed()` không còn kéo MỌI admin đang hoạt động không
 * điều kiện — chỉ những ai `supervisorsFor()` thật sự trả về (quản lý được xem vụ, trên vụ
 * THƯỜNG). Một admin không phải quản lý thì KHÔNG nhận, trừ khi chuỗi dự phòng của
 * `ResolveStaffRecipients` phải kích hoạt (không xảy ra ở đây, vì responsible+lead đã hợp lệ).
 *
 * Mutation probe: đổi `supervisorsFor($matter)` trong `failed()` trở lại
 * `User::query()->where('is_active', true)->role(Role::Admin->value)->get()` (bản cũ) — test này
 * ĐỎ vì admin nhận được thông báo (`count()` thành 1).
 */
it('does not notify an admin about a standard matter’s permanent failure, only a manager, via supervisorsFor', function () {
    [$deadline, , , $matter] = deadlineWithDistinctResponsibleAndLead();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($admin->notifications()->count())->toBe(0)
        ->and($matter->confidentiality)->toBe(Confidentiality::Normal);
});

/** Cặp dương: cùng kịch bản, nhưng một manager được xem vụ THÌ nhận — chứng minh supervisorsFor() thật sự có nối dây, không phải luôn rỗng. */
it('notifies a manager (not an admin) about a standard matter’s permanent failure, via supervisorsFor', function () {
    [$deadline] = deadlineWithDistinctResponsibleAndLead();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($manager->notifications()->count())->toBe(1);
});

/**
 * Vụ `restricted`: `supervisorsFor()` đổi sang admin thay vì manager — `failed()` phải thấy đúng
 * điều đó, không phải một nhánh riêng của chính job này.
 */
it('notifies an admin instead of a manager about a restricted matter’s permanent failure, via supervisorsFor', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $matter->addTeamMember($responsible, MatterRole::Associate);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDays(7),
        'is_completed' => false,
        'reminders_sent' => ['d14', 'd7'],
    ]);

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($admin->notifications()->count())->toBe(1)
        ->and($manager->notifications()->count())->toBe(0);
});

/** Admin đã vô hiệu hoá không nhận, kể cả khi họ là admin duy nhất — cặp âm/dương của "lưới an toàn cuối cùng". */
it('does not notify a deactivated admin even as the last-resort fallback', function () {
    [$deadline, $responsible, $lead] = deadlineWithDistinctResponsibleAndLead();
    $responsible->update(['is_active' => false]);
    $lead->update(['is_active' => false]);
    $inactiveAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => false]);
    $activeAdmin = User::factory()->withRole(Role::Admin)->create();

    $job = new SendDeadlineReminderMail($deadline->id, 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($inactiveAdmin->notifications()->count())->toBe(0)
        ->and($activeAdmin->notifications()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 14, fix round 1 (C1): khoá chống gửi trùng là BẬC + NGÀY ĐẾN HẠN mà lời nhắc nói tới
// (`d1@2026-10-01`), không chỉ bậc. Job mang ảnh chụp `due_date` lúc CheckDeadlines xếp hàng.
// ---------------------------------------------------------------------------------------------

function ledgerRowFor(Deadline $deadline, User $recipient, string $tier): OutboundMessage
{
    return OutboundMessage::factory()->create([
        'channel' => OutboundChannel::Email,
        'recipient' => $recipient->email,
        'template' => 'staff.deadline_reminder',
        'payload' => ['subject' => 'Bất kỳ', 'tier' => $tier],
        'related_type' => $deadline->getMorphClass(),
        'related_id' => $deadline->getKey(),
        'status' => OutboundStatus::Sent,
    ]);
}

/** Thử lại CÙNG một job (cùng bậc, cùng ngày đến hạn): người đã nhận không nhận lần hai. */
it('does not mail twice for the same tier and the same due date', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    $dueDate = $deadline->due_date->toDateString();
    ledgerRowFor($deadline, $lawyer, 'd3@'.$dueDate);

    (new SendDeadlineReminderMail($deadline->id, 'd3', $dueDate))->handle();

    Mail::assertNothingSent();
});

/** Hoãn phiên toà: `d3` đã gửi cho ngày CŨ không chặn `d3` của ngày MỚI. */
it('still mails a tier that was delivered for a different due date', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    ledgerRowFor($deadline, $lawyer, 'd3@'.today()->subDays(20)->toDateString());

    (new SendDeadlineReminderMail($deadline->id, 'd3', $deadline->due_date->toDateString()))->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * Dòng cũ chỉ mang bậc trần (ghi trước bản sửa này) không biết nó nói về ngày nào — nên nó KHÔNG
 * chặn một job mới (có ảnh chụp ngày). Nó vẫn chặn đúng một job CŨ đang chờ thử lại (không có ảnh
 * chụp) — các test "does not mail … same tier" ở trên đo vế đó bằng `new SendDeadlineReminderMail($id, 'd1')`.
 */
it('does not let a legacy bare-tier ledger row block a job that carries its due date', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    ledgerRowFor($deadline, $lawyer, 'd3');

    (new SendDeadlineReminderMail($deadline->id, 'd3', $deadline->due_date->toDateString()))->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/** CheckDeadlines xếp job kèm ngày đến hạn của lúc đó. */
it('is queued by CheckDeadlines with the due date it is about', function () {
    Queue::fake();
    [$deadline] = deadlineWithLawyer();

    (new CheckDeadlines)->handle();

    Queue::assertPushed(
        SendDeadlineReminderMail::class,
        fn (SendDeadlineReminderMail $job): bool => $job->deadlineId === $deadline->id
            && $job->tierKey === 'd3'
            && $job->dueDate === $deadline->due_date->toDateString(),
    );
});

/**
 * Fix round 2 (Important): một job xếp hàng TRƯỚC 5098fd6 được serialize không có `dueDate`.
 * `unserialize()` không chạy constructor, nên thuộc tính readonly có kiểu đó ở trạng thái CHƯA KHỞI
 * TẠO — mặc định `= null` là của tham số constructor, không phải của thuộc tính. Đọc thẳng nó ném
 * Error, job hỏng đủ 5 lần, `failed()` rút bậc, lượt sau gửi lại cho người đã nhận. Test dựng đúng
 * payload đó: serialize một job thật, gỡ `dueDate` khỏi chuỗi, unserialize, chạy `handle()`.
 */
function legacyJobWithoutDueDate(int $deadlineId, string $tier): SendDeadlineReminderMail
{
    $serialized = serialize(new SendDeadlineReminderMail($deadlineId, $tier));

    $stripped = str_replace('s:7:"dueDate";N;', '', $serialized, $removed);
    expect($removed)->toBe(1);

    // Số thuộc tính trong tiêu đề `O:<len>:"<class>":<count>:` giảm đi một.
    $stripped = preg_replace_callback(
        '/^O:(\d+):"([^"]+)":(\d+):/',
        fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':',
        $stripped,
    );

    $job = unserialize($stripped);
    expect((new ReflectionProperty($job, 'dueDate'))->isInitialized($job))->toBeFalse();

    return $job;
}

it('runs a job queued before the due-date snapshot existed, and still honours its legacy bare-tier ledger row', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer(); // due in 3 days => tier d3
    ledgerRowFor($deadline, $lawyer, 'd3');

    legacyJobWithoutDueDate($deadline->id, 'd3')->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: cùng job cũ, không có dòng nhật ký nào — nó gửi, tức `handle()` thật sự chạy hết. */
it('mails from a job queued before the due-date snapshot existed when nothing was delivered yet', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    legacyJobWithoutDueDate($deadline->id, 'd3')->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
});

/**
 * Final review X5 (B-I1): người nhận ĐẦU TIÊN hỏng (hộp thư của luật sư phụ trách bị máy chủ từ
 * chối) không được chặn thư leo thang tới trưởng phòng — ở mọi lần thử lại, mọi ngày. Mỗi người
 * nhận một lần thử riêng; ngoại lệ đầu tiên vẫn được ném lại SAU vòng lặp, nên hàng đợi vẫn thấy
 * job hỏng và `failed()` vẫn chạy đúng như trước.
 */
it('still mails the manager when the first recipient\'s transport fails, and still fails the job', function () {
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['email' => 'luatsu-hong@vidu.test']);
    $manager = User::factory()->withRole(Role::Manager)->create(['email' => 'quanly@vidu.test']);

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today()->addDay(),
        'is_completed' => false,
    ]);

    config(['mail.default' => deadlineJobSelectiveFailMailer('luatsu-hong@vidu.test')]);

    $thrown = null;

    try {
        (new SendDeadlineReminderMail($deadline->id, 'd1', $deadline->due_date->toDateString()))->handle();
    } catch (TransportException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();

    $managerRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $manager->email)->where('status', OutboundStatus::Sent)->count();

    expect($managerRows)->toBe(1);
});
