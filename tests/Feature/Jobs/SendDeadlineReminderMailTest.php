<?php

use App\Enums\Confidentiality;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

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

// ---------------------------------------------------------------------------------------------
// M6.5 Task 12 (R3) — re-check ĐI QUA ResolveStaffRecipients tại thời điểm chạy, không chỉ lọc
// is_active trên danh sách id đã cũ từ lúc dispatch.
// ---------------------------------------------------------------------------------------------

/**
 * Giữa lúc `CheckDeadlines` xếp job này và lúc nó thật sự chạy, vụ việc có thể đã bị siết thành
 * `restricted` (đổi `confidentiality`, một Action của M6.5 khác) — R3: người nhận thư về một vụ
 * việc chỉ là người ĐANG được xem vụ đó, kiểm tra lại tại thời điểm gửi, không phải tại thời điểm
 * dispatch. Một quản lý có mặt trong payload không còn được xem vụ hạn chế thì không được nhận,
 * dù id của họ vẫn còn trong `$recipientIds`.
 *
 * Mutation probe: thay lời gọi `ResolveStaffRecipients` bằng bộ lọc cũ (chỉ `is_active`, không
 * `Gate::view`) — test này ĐỎ vì quản lý vẫn nhận được thư (`Mail::assertNotSent` thất bại).
 */
it('re-checks Gate::view at send time, so a manager in the payload does not receive it once the matter has turned restricted since the deadline was queued', function () {
    Mail::fake();
    [$deadline, $lawyer, $matter] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $matter->update(['confidentiality' => Confidentiality::Restricted]);

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id, $manager->id], 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertNotSent(fn (DeadlineReminder $mail) => $mail->hasTo($manager->email));
});

/** Cặp dương: vụ việc còn bình thường thì cả hai (luật sư phụ trách vụ VÀ quản lý) đều nhận, như trước. */
it('still mails everyone in the payload when the matter has not turned restricted', function () {
    Mail::fake();
    [$deadline, $lawyer, $matter] = deadlineWithLawyer();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $job = new SendDeadlineReminderMail($deadline->id, [$lawyer->id, $manager->id], 'd1');
    $job->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($lawyer->email));
    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($manager->email));
});

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
    [$deadline, $responsible] = deadlineWithDistinctResponsibleAndLead();
    $deadline->update(['reminders_sent' => ['d14', 'd7']]);

    $job = new SendDeadlineReminderMail($deadline->id, [$responsible->id], 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($deadline->fresh()->reminders_sent)->not->toContain('d7');
});

/** Cặp dương: một bậc KHÁC bậc đang hỏng (đã gửi thật ở một lượt trước) không bị đụng tới. */
it('leaves every other tier untouched when only one tier failed', function () {
    [$deadline, $responsible] = deadlineWithDistinctResponsibleAndLead();
    $deadline->update(['reminders_sent' => ['d14', 'd7']]);

    $job = new SendDeadlineReminderMail($deadline->id, [$responsible->id], 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    expect($deadline->fresh()->reminders_sent)->toBe(['d14']);
});

/**
 * C1, phần "surface the failure in the app": một dòng audit mang ID và bậc (KHÔNG mang gì khác —
 * không tên mốc, không nội dung), và ba nhóm nhận thông báo trong ứng dụng: người phụ trách mốc,
 * luật sư phụ trách vụ (qua `ResolveStaffRecipients`, để R3 giữ nguyên), và MỌI admin đang hoạt
 * động. Admin đã vô hiệu hoá không nhận — cặp âm/dương nằm chung một test.
 *
 * Mutation probe từng phần (xem báo cáo cho log ĐỎ):
 *  - xoá `Audit::record(...)` → `$audit` là null;
 *  - bỏ `$deadline->responsible` khỏi `$preferred` → `$responsible->notifications()->count()` = 0;
 *  - bỏ `$matter->leadLawyer` khỏi `$preferred` → `$lead->notifications()->count()` = 0;
 *  - bỏ `$admins` khỏi `$preferred` → `$admin->notifications()->count()` = 0.
 */
it('writes an audit row and notifies the responsible user, the lead and every active admin when the job permanently fails', function () {
    [$deadline, $responsible, $lead] = deadlineWithDistinctResponsibleAndLead();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $inactiveAdmin = User::factory()->withRole(Role::Admin)->create(['is_active' => false]);

    $job = new SendDeadlineReminderMail($deadline->id, [$responsible->id], 'd7');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn.'));

    $audit = Activity::query()->where('event', 'deadline_reminder_failed')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->properties->get('deadline_id'))->toBe($deadline->id)
        ->and($audit->properties->get('tier'))->toBe('d7');

    expect($responsible->notifications()->count())->toBe(1)
        ->and($lead->notifications()->count())->toBe(1)
        ->and($admin->notifications()->count())->toBe(1)
        ->and($inactiveAdmin->notifications()->count())->toBe(0);
});
