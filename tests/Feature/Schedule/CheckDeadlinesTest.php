<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\DeadlineSeverity;
use App\Enums\Role;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function deadlineDueIn(int $days, ?User $responsible = null, DeadlineSeverity $severity = DeadlineSeverity::Normal): Deadline
{
    $lawyer = $responsible ?? User::factory()->withRole(Role::Lawyer)->create();
    $type = MatterType::factory()->withStages()->create();

    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lawyer->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);

    return Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $lawyer->id,
        'due_date' => today()->addDays($days),
        'severity' => $severity,
        'is_completed' => false,
        'reminders_sent' => [],
    ]);
}

// ---------------------------------------------------------------------------------------------
// Bậc nhắc
// ---------------------------------------------------------------------------------------------

it('picks the nearest tier that still has meaning', function (int $daysLeft, ?string $expected) {
    $deadline = deadlineDueIn($daysLeft);

    expect((new CheckDeadlines)->tierFor($deadline))->toBe($expected);
})->with([
    'còn 30 ngày, quá xa để nhắc' => [30, null],
    'còn 8 ngày, vẫn chưa tới bậc nào' => [8, null],
    'còn đúng 7 ngày' => [7, 'd7'],
    'còn 5 ngày, bậc gần nhất là 7' => [5, 'd7'],
    'còn đúng 3 ngày' => [3, 'd3'],
    'còn 2 ngày, bậc gần nhất là 3' => [2, 'd3'],
    'còn đúng 1 ngày' => [1, 'd1'],
    'hết hạn hôm nay vẫn là bậc khẩn' => [0, 'd1'],
    'đã quá hạn' => [-1, 'overdue'],
    'quá hạn lâu rồi' => [-20, 'overdue'],
]);

it('gives a critical deadline an extra tier fourteen days out', function () {
    $normal = deadlineDueIn(12);
    $critical = deadlineDueIn(12, severity: DeadlineSeverity::Critical);

    // Cùng một số ngày còn lại, hai câu trả lời khác nhau: đó là toàn bộ ý nghĩa của `critical`.
    expect((new CheckDeadlines)->tierFor($normal))->toBeNull()
        ->and((new CheckDeadlines)->tierFor($critical))->toBe('d14');
});

// ---------------------------------------------------------------------------------------------
// Gửi thư
// ---------------------------------------------------------------------------------------------

it('sends one reminder to the responsible lawyer seven days out', function () {
    Mail::fake();
    $deadline = deadlineDueIn(7);

    $result = (new CheckDeadlines)->handle();

    expect($result['reminded'])->toBe(1);
    Mail::assertSent(DeadlineReminder::class, 1);
    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($deadline->responsible->email));
});

it('never sends a second reminder for the same tier, however often the job runs', function () {
    Mail::fake();
    deadlineDueIn(7);

    (new CheckDeadlines)->handle();
    (new CheckDeadlines)->handle();
    (new CheckDeadlines)->handle();

    // Cron gọi trùng là chuyện thường: gia hạn gói, đổi múi giờ, người quản trị chạy tay.
    Mail::assertSent(DeadlineReminder::class, 1);
});

it('widens the audience as the deadline gets closer', function () {
    Mail::fake();
    User::factory()->withRole(Role::Manager)->create();
    $deadline = deadlineDueIn(1);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd1');

    // SPEC §6.8: bậc một ngày gửi cho người phụ trách VÀ toàn bộ quản lý. Một lời nhắc chỉ gửi
    // cho đúng người đang bận là một lời nhắc bị bỏ qua.
    expect($recipients->pluck('id')->all())->toContain($deadline->responsible_user_id)
        ->and($recipients)->toHaveCount(2);
});

it('never mails a deactivated account', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    deadlineDueIn(7, $lawyer);

    $result = (new CheckDeadlines)->handle();

    // Gửi vào một hộp thư không ai đọc là tự dựng bằng chứng sai rằng văn phòng đã được nhắc.
    Mail::assertNothingSent();
    expect($result['reminded'])->toBe(0);
});

it('keeps the reminder pending when nobody could receive it', function () {
    Mail::fake();
    $lawyer = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $deadline = deadlineDueIn(7, $lawyer);

    (new CheckDeadlines)->handle();

    // Không đánh dấu đã gửi: bật lại tài khoản thì lời nhắc phải còn nguyên, không biến mất.
    expect($deadline->fresh()->reminders_sent)->not->toContain('d7');

    $lawyer->update(['is_active' => true]);
    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});

it('leaves a completed deadline alone', function () {
    Mail::fake();
    $deadline = deadlineDueIn(1);
    $deadline->update(['is_completed' => true, 'completed_at' => now()]);

    (new CheckDeadlines)->handle();

    Mail::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------
// Bậc bị bỏ lỡ — chỗ SPEC không nói, và là chỗ quyết định tác vụ này có ích hay không
// ---------------------------------------------------------------------------------------------

it('sends exactly one reminder after the schedule has been dead for days, not three', function () {
    Mail::fake();
    $deadline = deadlineDueIn(2);

    // Lịch chết mấy ngày: mốc này lẽ ra đã phải nhắc ở bậc 7. Gửi cả ba thư cùng lúc sẽ dạy
    // người đọc rằng thư của hệ thống là rác; im lặng thì đúng cái mà tác vụ này sinh ra để chống.
    $result = (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
    expect($result['reminded'])->toBe(1)
        ->and($deadline->fresh()->reminders_sent)->toContain('d3')
        // Bậc đã trôi qua được đánh dấu, để nó không bắn ngược về sau.
        ->and($deadline->fresh()->reminders_sent)->toContain('d7')
        ->and($result['skipped_tiers'])->toBe(1);
});

it('does not fire a skipped tier later when the clock moves on', function () {
    Mail::fake();
    $deadline = deadlineDueIn(2);

    (new CheckDeadlines)->handle();
    Mail::assertSent(DeadlineReminder::class, 1);

    $this->travelTo(today()->addDay());
    (new CheckDeadlines)->handle();

    // Ngày hôm sau còn 1 ngày: đúng một thư mới ở bậc d1, không có thư d7 muộn màng.
    Mail::assertSent(DeadlineReminder::class, 2);
    expect($deadline->fresh()->reminders_sent)->toContain('d1');
});

it('marks every earlier tier as spent once a deadline is already overdue', function () {
    Mail::fake();
    User::factory()->withRole(Role::Manager)->create();
    $deadline = deadlineDueIn(-3);

    (new CheckDeadlines)->handle();

    $sent = $deadline->fresh()->reminders_sent;

    // "Đánh dấu quá hạn" của SPEC §6.8 không phải một cột: quá hạn là due_date < today tính lúc
    // đọc. Dấu vết của việc ĐÃ CẢNH BÁO nằm ở đây.
    expect($sent)->toContain('overdue')
        ->and($sent)->toContain('d1')
        ->and($sent)->toContain('d3')
        ->and($sent)->toContain('d7');

    // Bậc quá hạn gửi cho người phụ trách VÀ toàn bộ quản lý (SPEC §6.8), nên lần chạy đầu là
    // hai thư, không phải một. Điều cần ghim ở đây là lần chạy thứ hai không thêm thư nào.
    Mail::assertSent(DeadlineReminder::class, 2);

    (new CheckDeadlines)->handle();
    Mail::assertSent(DeadlineReminder::class, 2);
});

// ---------------------------------------------------------------------------------------------
// Nội dung thư
// ---------------------------------------------------------------------------------------------

it('tells the reader in the subject line whether it is a warning or a breach', function () {
    $soon = deadlineDueIn(7);
    $late = deadlineDueIn(-2);

    $soonSubject = (new DeadlineReminder($soon, $soon->responsible, 'd7'))->envelope()->subject;
    $lateSubject = (new DeadlineReminder($late, $late->responsible, 'overdue'))->envelope()->subject;

    // Người mở hộp thư lúc 7 giờ sáng phải phân biệt được hai thứ này mà không cần mở thư.
    expect($soonSubject)->toContain('7 ngày')
        ->and($lateSubject)->toContain('QUÁ HẠN')
        ->and($soonSubject)->not->toBe($lateSubject);
});

it('carries the matter code, because this one goes to staff and not to a client', function () {
    $deadline = deadlineDueIn(3);

    $subject = (new DeadlineReminder($deadline, $deadline->responsible, 'd3'))->envelope()->subject;

    expect($subject)->toContain($deadline->matter->code);
});

// ---------------------------------------------------------------------------------------------
// Vụ việc đã huỷ (M6.5 Task 5, finding deadlines/F8)
// ---------------------------------------------------------------------------------------------

/**
 * `deadlines/F8`: trước bản sửa này, tập ứng viên chỉ lọc `is_completed = false`, không hỏi gì
 * về vụ việc đứng sau. Xoá mềm vụ việc (nay có đường thật qua `CancelMatter`, admin) khiến
 * `$deadline->matter` trả `null` — thư vẫn gửi, mã hồ sơ rỗng, và `SetDeadlineCompletion` không
 * đánh dấu xong được vì `MatterPolicy::update` chặn vụ đã xoá mềm — mốc cứ leo bậc nhắc mãi.
 */
it('does not remind a deadline whose matter has been soft-deleted (cancelled)', function () {
    Mail::fake();

    $deadline = deadlineDueIn(1);
    $deadline->matter->delete();

    (new CheckDeadlines)->handle();

    Mail::assertNothingSent();
});

/** Cặp dương của test trên: một mốc y hệt, nhưng vụ việc còn nguyên, vẫn được nhắc như thường. */
it('still reminds a deadline whose matter has not been cancelled', function () {
    Mail::fake();

    $deadline = deadlineDueIn(1);

    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});
