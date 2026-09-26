<?php

use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * M6.5 Task 11 — hành vi RIÊNG của job (đọc lại tại thời điểm chạy, re-check trước khi gửi), tách
 * khỏi câu hỏi "job có được dispatch đúng lúc, đúng người hay không" (đó là việc của
 * `tests/Feature/Schedule/CheckDeadlinesTest.php`). Mọi test ở đây gọi `->handle()` TRỰC TIẾP trên
 * một instance job tự dựng — cùng phong cách `tests/Feature/Jobs/RecheckClientIdentityConflictsTest.php`.
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

it('mails every recipient it was given, re-read fresh from the database', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id], 'd3');
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
    [$deadline, $lawyer, $matter] = deadlineWithLawyer();
    $matter->delete();

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id], 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/** Cặp dương: vụ việc còn nguyên thì vẫn gửi như thường — ghim rằng test trên đỏ vì huỷ vụ, không vì lý do khác. */
it('still mails when the matter has not been cancelled', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id], 'd3');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});

/**
 * Cùng carry-forward: người phụ trách có thể bị vô hiệu hoá (R7, nghỉ việc) trong CÙNG khoảng trễ
 * đó. Job bỏ qua đúng người đó, KHÔNG ném lỗi, và vẫn gửi cho những người nhận còn lại trong cùng
 * danh sách.
 *
 * Mutation probe: xoá `->where('is_active', true)` khỏi truy vấn người nhận của
 * `SendDeadlineReminderMail::handle()` — test này ĐỎ vì lawyer bị khoá vẫn nhận được thư
 * (`Mail::assertNotSent` thất bại).
 */
it('skips a recipient who has been deactivated since the deadline was queued, but still mails the others', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $lawyer->update(['is_active' => false]);

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id, $manager->id], 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($manager->email));
    Mail::assertNotSent(fn (DeadlineReminder $mail) => $mail->hasTo($lawyer->email));
});

/** Cặp dương: cả hai còn hoạt động thì cả hai đều nhận được thư. */
it('still mails a recipient who has not been deactivated', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id, $manager->id], 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, 2);
});

/**
 * Mốc có thể đã bị xoá mềm (R14) hoặc đánh dấu xong giữa lúc job chờ tới lượt — cùng nguyên tắc
 * "không ném lỗi, chỉ bỏ qua" của toàn bộ job này.
 */
it('skips silently when the deadline no longer exists or is already marked complete', function () {
    Mail::fake();
    [$deadline, $lawyer] = deadlineWithLawyer();
    $deadline->update(['is_completed' => true, 'completed_at' => now()]);

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id], 'd3');
    $job->handle();

    Mail::assertNothingSent();
});

/** `$tries - 1` độ trễ, cùng kỷ luật đã ghim ở `RecheckClientIdentityConflictsTest`. */
it('configures exactly one backoff delay per release', function () {
    $job = new SendDeadlineReminderMail(1, [1], 'd7');

    expect($job->backoff())->toHaveCount($job->tries - 1);
});
