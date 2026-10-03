<?php

use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Jobs\SendReassignmentDigest;
use App\Mail\OutboundHeaders;
use App\Mail\Staff\MatterReassigned;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * M7 Task 1 — thư tổng hợp mốc hạn cho lead mới sau một lần bàn giao (SPEC §6.11 bước 3, R10).
 *
 * Cùng phong cách `tests/Feature/Jobs/SendDeadlineReminderMailTest.php`: mọi test ở đây gọi
 * `->handle()` TRỰC TIẾP trên một instance job tự dựng, đo đúng việc RIÊNG của job (dựng lại
 * TOÀN BỘ nội dung tại thời điểm gửi — mốc nào còn hợp lệ, vụ nào người nhận còn xem được — chứ
 * không tin bất kỳ ảnh chụp nào được truyền vào constructor ngoài chính danh sách id). Việc job
 * có được `ReassignMatter::handle()` dispatch đúng lúc, đúng payload hay không là việc của
 * `tests/Feature/Filament/ReassignMatterActionTest.php` (đi qua Livewire, đúng CLAUDE.md).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reassignmentDigestFixture(): array
{
    $oldLead = User::factory()->withRole(Role::Lawyer)->create();
    $newLead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $newLead->id]);

    return [$newLead, $oldLead, $matter];
}

it('mails the new lead the deadlines moved for a matter, excluding one already finished', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();

    $moved = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
        'due_date' => today()->addDays(5),
        'severity' => DeadlineSeverity::Critical,
    ]);
    // Đã hoàn thành TRƯỚC khi job chạy (ví dụ đánh dấu xong ngay sau khi bàn giao) — không được
    // liệt vào thư, dù id của nó nằm trong payload lúc dispatch (ReassignMatter::handle() chỉ
    // biết trạng thái LÚC bàn giao, không biết trạng thái LÚC job chạy).
    $finishedAfterHandover = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => [
            'deadline_ids' => [$moved->id, $finishedAfterHandover->id],
            'client_request_ids' => [],
            'reason' => 'Luật sư cũ nghỉ việc.',
        ],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($newLead, $matter, $moved, $finishedAfterHandover): bool {
        expect($mail->recipient->is($newLead))->toBeTrue()
            ->and($mail->blocks)->toHaveCount(1);

        $block = $mail->blocks[0];
        $deadlineIds = collect($block['deadlines'])->pluck('id');

        expect($block['matter']->is($matter))->toBeTrue()
            ->and($deadlineIds)->toContain($moved->id)
            ->and($deadlineIds)->not->toContain($finishedAfterHandover->id);

        return $mail->hasTo($newLead->email);
    });
});

/** Cặp dương: một mốc còn CHƯA xong thì vẫn có mặt — test trên đỏ đúng vì đã xong, không vì lý do khác. */
it('still lists a deadline that has not been completed', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $moved = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$moved->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => collect($mail->blocks[0]['deadlines'])->pluck('id')->contains($moved->id));
});

/**
 * Mutation probe (bỏ điều kiện `responsible_user_id` khỏi truy vấn mốc của job): một mốc đã bị
 * bàn giao TIẾP cho một luật sư thứ ba (không còn do $newLead phụ trách) không được xuất hiện
 * trong thư của $newLead nữa, dù id của nó vẫn còn trong payload lúc dispatch.
 */
it('excludes a deadline that was handed off again to someone else before the digest ran', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $thirdLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);
    $deadline->update(['responsible_user_id' => $thirdLawyer->id]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => collect($mail->blocks[0]['deadlines'])->isEmpty());
});

/**
 * R8 (brief Task 1): "Vụ không có mốc nào vẫn có mặt trong thư... lead mới cần biết mình vừa
 * nhận vụ." Một lô mà mọi mốc đã bị lọc ra (hoàn thành, hay bàn giao tiếp) vẫn giữ nguyên KHỐI
 * của vụ việc đó, chỉ danh sách mốc rỗng — không phải loại hẳn khối đó khỏi thư.
 */
it('still includes the matter block with an empty deadline list when every moved deadline no longer qualifies', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => 'Không có mốc hạn nào.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($matter): bool {
        expect($mail->blocks)->toHaveCount(1)
            ->and($mail->blocks[0]['matter']->is($matter))->toBeTrue()
            ->and($mail->blocks[0]['deadlines'])->toBeEmpty();

        return true;
    });
});

// -------------------------------------------------------------------------------------------
// R7/R8 — vụ mà người nhận không còn qua được ResolveStaffRecipients::qualifies() bị loại KHỎI
// TOÀN BỘ lô, không chỉ lọc mốc của riêng nó (vụ restricted vừa bàn giao tiếp cho người khác).
// -------------------------------------------------------------------------------------------

it('excludes an entire matter the recipient no longer qualifies to view, because it was handed off again before the digest ran', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $thirdLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);

    // Vụ bị siết restricted rồi bàn giao TIẾP cho người thứ ba — $newLead không còn lead_lawyer_id
    // và không còn trong team, nên Matter::isListableBy() nhánh restricted từ chối họ hoàn toàn.
    $matter->update(['confidentiality' => Confidentiality::Restricted, 'lead_lawyer_id' => $thirdLawyer->id]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    // Đây là VỤ DUY NHẤT trong lô, và nó bị loại — cả lô rỗng, "không còn gì thì không gửi".
    Mail::assertNothingSent();
});

/** Cặp dương: cùng kịch bản nhưng KHÔNG bàn giao tiếp — vụ vẫn có mặt trong thư như thường. */
it('still includes the matter when the recipient was not handed off again', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => count($mail->blocks) === 1);
});

// -------------------------------------------------------------------------------------------
// "Người nhận bị vô hiệu hoá giữa lúc xếp hàng và lúc gửi → không gửi."
// -------------------------------------------------------------------------------------------

it('sends nothing when the recipient was deactivated between queueing and sending', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);
    $newLead->update(['is_active' => false]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: người nhận còn hoạt động thì vẫn gửi — test trên đỏ đúng vì vô hiệu hoá, không vì lý do khác. */
it('still sends when the recipient is still active', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, 1);
});

it('sends nothing when the recipient no longer exists at all', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $ghostId = $newLead->id + 999_000;

    $job = new SendReassignmentDigest($ghostId, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertNothingSent();
});

// -------------------------------------------------------------------------------------------
// Số yêu cầu khách hàng chưa đóng đã chuyển (R8).
// -------------------------------------------------------------------------------------------

it('counts the still-open client requests moved for a matter, re-derived at send time', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $open = ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $newLead->id,
        'status' => ClientRequestStatus::InProgress,
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [$open->id], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->blocks[0]['client_requests_moved'] === 1);
});

/**
 * Mutation probe (bỏ điều kiện `status != closed` khỏi truy vấn yêu cầu khách của job): một yêu
 * cầu đã ĐÓNG giữa lúc bàn giao và lúc job chạy không được đếm.
 */
it('does not count a client request that was closed before the digest ran', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $request = ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $newLead->id,
        'status' => ClientRequestStatus::InProgress,
    ]);
    $request->update(['status' => ClientRequestStatus::Closed]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [$request->id], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->blocks[0]['client_requests_moved'] === 0);
});

/**
 * Mutation probe (bỏ điều kiện `assigned_to` khỏi truy vấn yêu cầu khách của job): một yêu cầu
 * đã bị giao TIẾP cho người khác không được đếm vào lô của $newLead nữa.
 */
it('does not count a client request reassigned to someone else before the digest ran', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();
    $thirdLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $request = ClientRequest::factory()->for($matter)->create([
        'assigned_to' => $newLead->id,
        'status' => ClientRequestStatus::InProgress,
    ]);
    $request->update(['assigned_to' => $thirdLawyer->id]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [$request->id], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, fn (MatterReassigned $mail): bool => $mail->blocks[0]['client_requests_moved'] === 0);
});

// -------------------------------------------------------------------------------------------
// Tiêu đề thư không nêu mã/tiêu đề vụ (phán quyết Task 1).
// -------------------------------------------------------------------------------------------

it('does not name any matter code or title in the subject line', function () {
    Mail::fake();
    [$newLead, , $matter] = reassignmentDigestFixture();

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->handle();

    Mail::assertSent(MatterReassigned::class, function (MatterReassigned $mail) use ($matter): bool {
        $subject = $mail->envelope()->subject;

        expect($subject)->not->toContain($matter->code)
            ->and($subject)->not->toContain($matter->title);

        return true;
    });
});

/**
 * `Mail::fake()` không dựng thật view của thư — mọi test ở trên đo NỘI DUNG `$blocks` truyền vào
 * `MatterReassigned`, không đo việc file Blade thật sự biên dịch được. Test này KHÔNG fake: cùng
 * thành ngữ `tests/Feature/Jobs/SendDeadlineReminderMailTest.php`, "never lets the internal tier
 * header reach the wire" — thư đi qua transport `array` thật (`MAIL_MAILER=array`, phpunit.xml),
 * nên `emails.staff.matter-reassigned(-text)` phải biên dịch và render không lỗi, và dòng
 * `outbound_messages` phải mở đúng (`RecordOutboundMessage::sending()` chỉ chạy trên một
 * `Email` Symfony thật, không chạy dưới `Mail::fake()`).
 */
it('renders the real Blade views without error and opens a ledger row, with no internal header reaching the wire', function () {
    [$newLead, , $matter] = reassignmentDigestFixture();
    $deadline = Deadline::factory()->for($matter)->create([
        'responsible_user_id' => $newLead->id,
        'is_completed' => false,
        'severity' => DeadlineSeverity::Critical,
    ]);

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [$deadline->id], 'client_request_ids' => [], 'reason' => 'Bàn giao thật, không fake.'],
    ]);
    $job->handle();

    $sentEmail = Mail::mailer()->getSymfonyTransport()->innerTransport()->messages()->last()->getOriginalMessage();

    expect($sentEmail->getHeaders()->has(OutboundHeaders::TEMPLATE))->toBeFalse()
        ->and($sentEmail->getHeaders()->has(OutboundHeaders::RELATED))->toBeFalse();

    $row = OutboundMessage::query()->withoutGlobalScopes()->sole();
    expect($row->template)->toBe('staff.matter_reassigned')
        ->and($row->recipient)->toBe($newLead->email);
});

it('configures exactly one backoff delay per release', function () {
    $job = new SendReassignmentDigest(1, []);

    expect($job->backoff())->toHaveCount($job->tries - 1);
});

// -------------------------------------------------------------------------------------------
// failed() — cùng hình dạng SendDeadlineReminderMail::failed().
// -------------------------------------------------------------------------------------------

it('writes an audit row and notifies the recipient when the job permanently fails', function () {
    [$newLead, , $matter] = reassignmentDigestFixture();

    $job = new SendReassignmentDigest($newLead->id, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'matter_reassignment_digest_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('new_lead_id'))->toBe($newLead->id)
        ->and($audit->properties->get('matter_ids'))->toBe([$matter->id]);

    expect($newLead->notifications()->count())->toBe(1);
});

it('does not notify a recipient who no longer exists when the job permanently fails', function () {
    [$newLead, , $matter] = reassignmentDigestFixture();
    $ghostId = $newLead->id + 999_000;

    $job = new SendReassignmentDigest($ghostId, [
        $matter->id => ['deadline_ids' => [], 'client_request_ids' => [], 'reason' => 'Bàn giao.'],
    ]);

    // Không được ném lỗi dù người nhận không còn tồn tại.
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'matter_reassignment_digest_failed')->latest('id')->first();
    expect($audit)->not->toBeNull();
});
