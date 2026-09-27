<?php

use App\Actions\Matter\CancelMatter;
use App\Actions\Schedule\CheckDeadlines;
use App\Enums\Confidentiality;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterRole;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Jobs\SendDeadlineReminderMail;
use App\Mail\Staff\DeadlineReminder;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterType;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Staff\DeadlineOverdueAlert;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Transport CHỌN LỌC dùng riêng cho các test Task 11 của tệp này: hỏng cho đúng một địa chỉ,
 * thành công (không thật sự gửi, chỉ trả về một `SentMessage`) cho những địa chỉ khác — cùng kỹ
 * thuật `tests/Feature/Mail/StageUpdateNotificationTest.php` dùng, tên riêng để không đụng lớp.
 */
class DeadlineSelectiveFailTransport implements TransportInterface
{
    public function __construct(private readonly string $failingAddress) {}

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SymfonySentMessage
    {
        if ($message instanceof Email) {
            foreach ($message->getTo() as $address) {
                if ($address->getAddress() === $this->failingAddress) {
                    throw new TransportException('SMTP từ chối '.$this->failingAddress);
                }
            }
        }

        return new SymfonySentMessage($message, new SymfonyEnvelope(
            new Address('gui@vidu.test'),
            [new Address('nhan@vidu.test')],
        ));
    }

    public function __toString(): string
    {
        return 'deadline-selective-fail://';
    }
}

function deadlineSelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.deadline_selective_fail', ['transport' => 'deadline_selective_fail']);
    Mail::extend('deadline_selective_fail', fn (): TransportInterface => new DeadlineSelectiveFailTransport($failingAddress));

    return 'deadline_selective_fail';
}

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

/**
 * M6.5 Task 12 (`deadlines/F3`): trước bản sửa này tiêu đề bậc quá hạn là chuỗi cố định
 * "ĐÃ QUÁ HẠN" (viết hoa, không mang số ngày thật). Giờ tiêu đề mang đúng số ngày đã trôi qua
 * hạn, chữ thường theo lang/vi/deadlines.php — cập nhật cụm assert theo đúng chữ mới, không phải
 * nới lỏng: bài test vẫn ghim đúng yêu cầu gốc ("phân biệt được còn hạn với quá hạn qua tiêu đề").
 */
it('tells the reader in the subject line whether it is a warning or a breach', function () {
    $soon = deadlineDueIn(7);
    $late = deadlineDueIn(-2);

    $soonSubject = (new DeadlineReminder($soon, $soon->responsible, 'd7'))->envelope()->subject;
    $lateSubject = (new DeadlineReminder($late, $late->responsible, 'overdue'))->envelope()->subject;

    // Người mở hộp thư lúc 7 giờ sáng phải phân biệt được hai thứ này mà không cần mở thư.
    expect($soonSubject)->toContain('7 ngày')
        ->and($lateSubject)->toContain('Đã quá hạn 2 ngày')
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
 * M6.5 Task 14 (R14): xoá một mốc là xoá mềm — `Deadline::query()` (bảng ứng viên của
 * `handle()`) mang sẵn `SoftDeletingScope` từ M1, nên một mốc đã gỡ không lọt vào danh sách này
 * mà không cần thêm điều kiện. Mốc thứ hai, không bị gỡ, cùng bậc, là vế dương trong cùng lượt.
 */
it('does not remind a deadline that has been soft-deleted, while still reminding one that has not', function () {
    Mail::fake();

    $deleted = deadlineDueIn(1);
    $deleted->delete();
    $kept = deadlineDueIn(1);

    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($kept->responsible->email));
    Mail::assertNotSent(DeadlineReminder::class, fn ($mail) => $mail->hasTo($deleted->responsible->email));
    // `fresh()` đọc không qua global scope, nên thấy được cả dòng đã xoá mềm.
    expect($deleted->fresh()->reminders_sent)->toBe([]);
});

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

/**
 * Fix round 1, finding S1: `whereHas('matter')` chỉ loại vụ đã XOÁ MỀM, không loại vụ đã ĐÓNG
 * (`closed_at` có giá trị, qua TransitionMatterStage vào giai đoạn is_terminal — R8). Một vụ đã
 * đóng và OpenWork (nghỉ việc, gỡ thành viên) đều coi là "không còn việc dở dang" — CheckDeadlines
 * phải đồng ý với chúng, dùng đúng `Matter::scopeOpen()`, không viết lại định nghĩa lần nữa.
 */
it('does not remind a deadline whose matter has been closed, even though it was not soft-deleted', function () {
    Mail::fake();

    $deadline = deadlineDueIn(1);
    $deadline->matter->update(['closed_at' => now()->subDay()]);

    (new CheckDeadlines)->handle();

    Mail::assertNothingSent();
});

/**
 * Cặp dương: chưa đóng thì vẫn nhắc — ghim rằng test trên đỏ vì `closed_at`, không vì lý do khác.
 */
it('still reminds a deadline whose matter has not been closed', function () {
    Mail::fake();

    $deadline = deadlineDueIn(1);

    expect($deadline->matter->closed_at)->toBeNull();

    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 1);
});

/**
 * Fix round 1, finding S1 (phần thứ hai của phán quyết): "re-check dưới khoá của từng dòng rằng
 * vụ việc vẫn còn mở, để một lần huỷ chạy đua với cron không gửi gì cả". Tập ứng viên được dựng
 * TRƯỚC vòng lặp; nếu một vụ việc bị huỷ NGAY GIỮA lúc cron đang xử lý các mốc khác (không phải
 * lúc dựng danh sách), mốc của vụ đó đã lọt vào danh sách rồi — phải bị chặn LẦN NỮA, ngay trong
 * giao dịch của chính dòng đó, không chỉ ở câu truy vấn đầu.
 *
 * Mô phỏng cuộc đua bằng `TransactionCommitted`: mốc thứ nhất (due sớm hơn, xử lý trước) huỷ vụ
 * việc của mốc thứ hai (due muộn hơn, xử lý sau) ngay khi giao dịch xử lý mốc thứ nhất VỪA commit
 * — tức đúng khoảng giữa hai lượt của vòng lặp, không phải trước khi vòng lặp bắt đầu.
 */
it('sends nothing for a deadline whose matter is cancelled between building the candidate list and its own turn in the loop', function () {
    Mail::fake();

    $admin = User::factory()->withRole(Role::Admin)->create();

    $first = deadlineDueIn(1);
    $second = deadlineDueIn(2);

    $raced = false;

    Event::listen(TransactionCommitted::class, function () use (&$raced, $second, $admin): void {
        if ($raced) {
            return;
        }

        $raced = true;

        app(CancelMatter::class)->handle(
            $second->matter,
            $admin,
            'Huỷ giữa lúc cron đang xử lý mốc khác.',
        );
    });

    (new CheckDeadlines)->handle();

    Event::forget(TransactionCommitted::class);

    Mail::assertSent(DeadlineReminder::class, 1);
    Mail::assertNotSent(function (DeadlineReminder $mail) use ($second): bool {
        return $mail->deadline->is($second);
    });
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 11 (`deadlines/F1`, `notify/notify-2`, `e2e/F3`) — thư ra khỏi transaction
// ---------------------------------------------------------------------------------------------

/**
 * Đúng kịch bản của brief Task 11: ba mốc, thư của mốc thứ nhất (mốc GẤP NHẤT — `orderBy('due_date')`
 * xử lý nó trước) hỏng. Mốc hai và ba vẫn được xếp thư bình thường — không dùng `Mail::fake()`,
 * vì điều đang được đo là hành vi THẬT của transport/transaction, đúng tiền lệ
 * `tests/Feature/Mail/OutboundLedgerTest.php`.
 *
 * Cả ba tầng đều tránh bậc `d1`/`overdue` (có gộp thêm TOÀN BỘ quản lý — xem `recipientsFor()`),
 * để mỗi mốc chỉ có ĐÚNG một người nhận và phép đếm dưới đây không lẫn lộn.
 *
 * Vòng sửa 1: rút hàng đợi thật ĐÚNG MỘT LƯỢT (`queue:work`, hàng đợi `database`, không phải
 * `sync` mặc định — xem lý do bên dưới), rồi đối chiếu `reminders_sent` của mốc hỏng vẫn CÒN
 * đánh dấu, vì một lần hỏng không phải là "hết lượt thử". Hành vi "rút lại đánh dấu SAU KHI hết
 * $tries" được đo riêng, xa hơn ở `SendDeadlineReminderMailTest` (gọi thẳng `->failed()`, không
 * cần dựng lại toàn bộ nhịp thời gian 5 lượt thử/backoff của hàng đợi thật) và ở test "dispatches
 * the tier again..." ngay dưới đây — tách khỏi test này để không phải mô phỏng cả năm lượt
 * `backoff()` (60/300/900/3600s) bằng `$this->travel()` chỉ để chứng minh một điều đã đo được
 * gọn hơn ở nơi khác.
 *
 * **Vì sao test này KHÔNG dùng hàng đợi `sync` mặc định.** `Illuminate\Queue\Jobs\Job::fail()`
 * (được `SyncQueue::handleException()` gọi cho MỌI ngoại lệ, không kiểm tra `$tries`) luôn gọi
 * `failed()` ngay từ lần hỏng ĐẦU TIÊN — `sync` không có khái niệm "còn lượt thử" vì nó không
 * xếp hàng thật. Chỉ `Illuminate\Queue\Worker::process()` (dùng bởi `queue:work`, tức hàng đợi
 * `database` thật) mới so `attempts()` với `$tries` trước khi quyết định thả lại (`release()`)
 * hay coi là hỏng hẳn (`fail()`) — và CHỈ MỘT lượt thử (chưa hết `$tries`) là đủ để phân biệt hai
 * đường đó, không cần đi hết cả năm lượt.
 */
it('keeps mailing the other deadlines when the first one fails once, without treating one failure as exhausted', function () {
    config(['queue.default' => 'database']);
    $failingLawyer = User::factory()->withRole(Role::Lawyer)->create(['email' => 'ls-hong-thu@vidu.test']);
    config(['mail.default' => deadlineSelectiveFailMailer('ls-hong-thu@vidu.test')]);

    $first = deadlineDueIn(2, $failingLawyer); // tier d3, xử lý TRƯỚC vì due_date gần nhất.
    $second = deadlineDueIn(5); // tier d7.
    $third = deadlineDueIn(9, severity: DeadlineSeverity::Critical); // tier d14.

    $result = (new CheckDeadlines)->handle();

    expect($result['reminded'])->toBe(3);

    // Rút từng job MỘT, bằng `--once` (tiền lệ `tests/Feature/Actions/OpenMatterTest.php`,
    // `openMatterDrainDatabaseQueue()`) — KHÔNG dùng `--stop-when-empty`: một lần hỏng ngay job
    // ĐẦU TIÊN khiến `release()` XOÁ RỒI TẠO LẠI dòng đó với id MỚI (lớn hơn hai job còn lại).
    // Vòng lặp bên trong CỦA MỘT lần gọi `queue:work` (giữa các lượt `getNextJob()`) đã đo được
    // là không luôn tiếp tục sang job kế tiếp một cách đáng tin cậy ngay sau một job vừa ném lỗi
    // và được thả lại — ba lời gọi `--once` RIÊNG BIỆT, mỗi lời gọi khởi tạo một `Worker` mới, mới
    // chắc chắn mỗi job (dù thành công hay hỏng) đều được xét đúng một lần.
    for ($i = 0; $i < 3; $i++) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
    }

    $secondSent = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $second->responsible->email)->where('status', OutboundStatus::Sent)->count();
    $thirdSent = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $third->responsible->email)->where('status', OutboundStatus::Sent)->count();

    // Mốc gấp nhất hỏng KHÔNG chặn mốc hai và mốc ba — vẫn xếp thư và gửi được như thường.
    expect($secondSent)->toBe(1)
        ->and($thirdSent)->toBe(1);

    $failedRows = OutboundMessage::query()->withoutGlobalScopes()
        ->where('recipient', $failingLawyer->email)->where('status', OutboundStatus::Failed)->get();

    expect($failedRows)->toHaveCount(1)
        ->and($failedRows->first()->error)->toContain('TransportException');

    // Job của mốc một chưa hết $tries: vẫn còn trong bảng jobs (thả lại chờ backoff), CHƯA sang
    // failed_jobs — nên reminders_sent của nó vẫn còn 'd3'. Một lần hỏng không phải là "hết lượt
    // thử": xem docblock `SendDeadlineReminderMail::failed()`.
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    expect($first->fresh()->reminders_sent)->toContain('d3')
        ->and($second->fresh()->reminders_sent)->toContain('d7')
        ->and($third->fresh()->reminders_sent)->toContain('d14');
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1, C1 (critical): job hỏng HẲN không được để mốc mất vĩnh viễn.
// ---------------------------------------------------------------------------------------------

/**
 * C1: trước bản sửa này, `reminders_sent` chỉ được ghi TRƯỚC khi gửi (Task 11) và không có gì
 * rút lại nếu job hỏng hẳn — mốc mất vĩnh viễn, vì lượt `CheckDeadlines` kế tiếp bỏ qua nó mãi
 * mãi (`in_array($key, $already, true)` đúng ở dòng 164 của Action). `SendDeadlineReminderMail::
 * failed()` (mới, vòng sửa 1) rút bậc đó ra khỏi `reminders_sent`, nên lượt kế tiếp coi mốc này
 * như CHƯA từng được xếp hàng ở bậc đó, và xếp lại.
 */
it('dispatches the tier again on the next CheckDeadlines run after the job for it permanently failed', function () {
    Mail::fake();
    $deadline = deadlineDueIn(3); // tier d3

    (new CheckDeadlines)->handle();
    Mail::assertSent(DeadlineReminder::class, 1);
    expect($deadline->fresh()->reminders_sent)->toContain('d3');

    $job = new SendDeadlineReminderMail($deadline->id, 'd3');
    $job->failed(new RuntimeException('SMTP giả lập chết hẳn, hết mọi lượt thử.'));

    expect($deadline->fresh()->reminders_sent)->not->toContain('d3');

    (new CheckDeadlines)->handle();

    Mail::assertSent(DeadlineReminder::class, 2);
    expect($deadline->fresh()->reminders_sent)->toContain('d3');
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 12 (`deadlines/F2`, `notify/notify-3`; `deadlines/F4`, `notify/notify-4`) —
// người nhận đi qua ResolveStaffRecipients (R3), không còn tự lọc thủ công.
// ---------------------------------------------------------------------------------------------

/**
 * `deadlines/F2`, `notify/notify-3`: trước bản sửa này, bậc 1 ngày/quá hạn cộng THẲNG
 * `User::query()->role('manager')` mà không hỏi Gate::view — một vụ `restricted` vẫn gửi mã hồ
 * sơ và tiêu đề cho trưởng phòng, người `Matter::isListableBy()` từ chối thẳng thừng. R3 (câu
 * thứ hai) đọc lại SPEC §6.8: vụ `restricted` thì bậc này cộng ADMIN thay vì manager.
 *
 * Người phụ trách của mốc CỐ Ý không phải là luật sư phụ trách vụ (lead lawyer) — chỉ lead lawyer
 * và admin qua được Gate::view() của một vụ restricted (xem `Matter::isListableBy()`), nên nếu
 * responsible = lead lawyer thì test không phân biệt được "admin nhận vì được cộng vào" với "admin
 * nhận vì lead lawyer tình cờ có vai trò admin".
 */
it('replaces the manager audience with admin at the one-day tier of a restricted matter', function () {
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
        'due_date' => today()->addDay(),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd1');

    expect($recipients->pluck('id')->all())->toContain($admin->id)
        ->and($recipients->pluck('id')->all())->not->toContain($manager->id)
        ->and($recipients->pluck('id')->all())->not->toContain($responsible->id);
});

/** Cặp dương: cùng bậc, cùng dữ liệu, nhưng vụ THƯỜNG thì manager nhận, không phải admin. */
it('still widens the one-day tier to managers on a standard (non-restricted) matter', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

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
        'due_date' => today()->addDay(),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd1');

    expect($recipients->pluck('id')->all())->toContain($manager->id)
        ->and($recipients->pluck('id')->all())->not->toContain($admin->id);
});

/**
 * Carry-forward của rà soát Task 3 (brief Task 12): một trợ lý đứng tên trong đội ngũ của một vụ
 * `restricted` (dữ liệu có thể có TỪ TRƯỚC khi luật hạn chế siết lại — `matter_user` không tự dọn)
 * không được nhận thư nhắc bậc 3 ngày, vì `Matter::isListableBy()` nhánh restricted không đọc
 * `team()` chút nào — chỉ `lead_lawyer_id`/admin.
 */
it('does not mail a team assistant who cannot view a restricted matter at the three-day tier', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $deadline = deadlineDueIn(3);
    $deadline->matter->update(['confidentiality' => Confidentiality::Restricted]);
    $deadline->matter->addTeamMember($assistant, MatterRole::Assistant);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline->fresh(), 'd3');

    expect($recipients->pluck('id')->all())->not->toContain($assistant->id);
});

/** Cặp dương: cùng đội ngũ, vụ THƯỜNG thì trợ lý nhận bình thường — test trên đỏ vì restricted, không vì lý do khác. */
it('still mails a team assistant on a standard matter at the three-day tier', function () {
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $deadline = deadlineDueIn(3);
    $deadline->matter->addTeamMember($assistant, MatterRole::Assistant);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline->fresh(), 'd3');

    expect($recipients->pluck('id')->all())->toContain($assistant->id);
});

/**
 * `deadlines/F4`, `notify/notify-4`: người phụ trách bị vô hiệu hoá không còn để mốc im lặng tới
 * bậc 1 ngày — R3 "không bao giờ im lặng" đưa lời nhắc bậc 7 ngày tới luật sư phụ trách VỤ
 * (lead lawyer), qua chuỗi dự phòng của `ResolveStaffRecipients`.
 */
it('falls back to the lead lawyer at the seven-day tier when the responsible person has been deactivated', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);

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

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd7');

    expect($recipients->pluck('id')->all())->toBe([$lead->id]);
});

/** Cặp dương: người phụ trách còn hoạt động thì thư đi thẳng tới họ, không phải lead lawyer. */
it('still goes straight to the responsible person at the seven-day tier when they have not been deactivated', function () {
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

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd7');

    expect($recipients->pluck('id')->all())->toBe([$responsible->id]);
});

/**
 * `deadlines/F4`, `notify/notify-4`: lead lawyer CŨNG bị vô hiệu hoá — chuỗi dự phòng đi tiếp tới
 * một manager được xem vụ, R3 "không bao giờ im lặng".
 */
it('falls back to a manager who can view the matter when both the responsible person and the lead lawyer have been deactivated', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $responsible = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();

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

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd7');

    expect($recipients->pluck('id')->all())->toBe([$manager->id]);
});

/**
 * Vòng sửa 2 (minor, `task-12-fix2-findings.md`): "khi cả người phụ trách LẪN luật sư phụ trách
 * vụ đều không hợp lệ, cộng supervisorsFor ở MỌI bậc, không chỉ d1/quá hạn."
 *
 * Bậc `d3` là bậc DUY NHẤT trước bản sửa này có thể để `$preferred` KHÔNG rỗng (một trợ lý trong
 * đội ngũ hợp lệ) trong khi ô "người phụ trách" (responsible/lead) vẫn trống — nên chuỗi dự phòng
 * của `ResolveStaffRecipients::handle()` (chỉ kích hoạt khi `$preferred` rỗng TOÀN BỘ) không bao
 * giờ chạy, và một mốc chỉ còn được đúng một trợ lý biết tới — không quản lý/admin nào giám sát,
 * dù "người phụ trách" thật sự của mốc đã hoàn toàn biến mất khỏi bức tranh. Ruling: một trợ lý
 * không được đứng MỘT MÌNH thay cho một chuỗi giám sát đã mất — cộng `supervisorsFor` NGAY CẢ ở
 * `d3`, khi ô người phụ trách trống.
 *
 * Mutation probe: xem báo cáo — trả điều kiện cộng `supervisorsFor` về lại `$key === 'd1' ||
 * $key === self::OVERDUE_KEY` (bỏ `|| ! $responsibleSlotFilled`) làm chính test này đỏ (thiếu
 * manager).
 */
it('escalates to a supervisor at the three-day tier too when neither the responsible person nor the lead lawyer qualifies', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $responsible = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $matter->addTeamMember($responsible, MatterRole::Associate);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDays(3),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd3');

    expect($recipients->pluck('id')->all())->toContain($assistant->id)
        ->and($recipients->pluck('id')->all())->toContain($manager->id);
});

/**
 * Cặp dương: cùng bậc `d3`, cùng đội ngũ, nhưng người phụ trách CÒN hoạt động (ô đó ĐÃ được lấp) —
 * manager không được cộng vào, để chứng minh test trên đỏ đúng vì ô người phụ trách trống, không
 * phải vì `d3` giờ luôn cộng supervisor vô điều kiện.
 */
it('does not escalate to a supervisor at the three-day tier when the responsible person already qualifies', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create();
    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    $matter->addTeamMember($responsible, MatterRole::Associate);
    $matter->addTeamMember($assistant, MatterRole::Assistant);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDays(3),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd3');

    expect($recipients->pluck('id')->all())->toContain($assistant->id)
        ->and($recipients->pluck('id')->all())->not->toContain($manager->id);
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 12 (`deadlines/F3`) — tiêu đề mang số ngày còn lại THẬT, không mang con số của bậc.
// ---------------------------------------------------------------------------------------------

/** Mốc còn 10 ngày, xét ở bậc 14 (critical): tiêu đề phải ghi "Còn 10 ngày", không phải "Còn 14 ngày". */
it('writes the real number of days left in the subject, not the tier label', function () {
    $deadline = deadlineDueIn(10, severity: DeadlineSeverity::Critical);

    $subject = (new DeadlineReminder($deadline, $deadline->responsible, 'd14'))->envelope()->subject;

    expect($subject)->toContain('Còn 10 ngày')
        ->and($subject)->not->toContain('Còn 14 ngày');
});

/** Mốc đến hạn hôm nay (còn 0 ngày): tiêu đề "Hết hạn hôm nay", không phải "Còn 0 ngày". */
it('writes "Hết hạn hôm nay" for a deadline due today, never "0 ngày"', function () {
    $deadline = deadlineDueIn(0);

    $subject = (new DeadlineReminder($deadline, $deadline->responsible, 'd1'))->envelope()->subject;

    expect($subject)->toContain('Hết hạn hôm nay')
        ->and($subject)->not->toContain('0 ngày');
});

/** Cùng luật ở thân thư (không chỉ tiêu đề): dòng đầu tiên khách/nhân sự đọc cũng không được nói "còn 0 ngày". */
it('shows "Hết hạn hôm nay" in the body headline for a deadline due today, never "0 ngày"', function () {
    $deadline = deadlineDueIn(0);

    $html = (new DeadlineReminder($deadline, $deadline->responsible, 'd1'))->render();

    expect($html)->toContain('Hết hạn hôm nay')
        ->and($html)->not->toContain('0 ngày');
});

// ---------------------------------------------------------------------------------------------
// Vòng sửa 1, I1 — luật sư phụ trách VỤ thế chỗ người phụ trách MỐC không hợp lệ ở MỌI bậc, không
// chỉ khi TOÀN BỘ preferred rỗng (bản trước: một trợ lý/quản lý hợp lệ khác trong preferred giữ
// $qualified không rỗng, nên chuỗi dự phòng toàn cục không bao giờ kích hoạt, và luật sư phụ
// trách vụ biến mất khỏi bậc đó dù người phụ trách MỐC đã nghỉ việc).
// ---------------------------------------------------------------------------------------------

/**
 * Ruling I1, kịch bản 1: người phụ trách mốc bị vô hiệu hoá, bậc 1 ngày — luật sư phụ trách vụ
 * PHẢI có mặt CẠNH quản lý (supervisorsFor), không chỉ một mình quản lý.
 */
it('substitutes the lead lawyer for a deactivated responsible person, alongside the tier supervisors, at the one-day tier', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $manager = User::factory()->withRole(Role::Manager)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDay(),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd1');

    expect($recipients->pluck('id')->sort()->values()->all())->toBe(
        collect([$lead->id, $manager->id])->sort()->values()->all()
    );
});

/**
 * Ruling I1, kịch bản 2: người phụ trách mốc là một cộng sự cũ (từ trước khi vụ bị siết thành
 * restricted) nên không còn Gate::view() được — luật sư phụ trách vụ thế chỗ, CẠNH admin
 * (supervisorsFor của vụ restricted), không phải chỉ một mình admin.
 */
it('substitutes the lead lawyer for a legacy associate responsible who cannot view a restricted matter, alongside admins, at the one-day tier', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $responsible = User::factory()->withRole(Role::Lawyer)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $manager = User::factory()->withRole(Role::Manager)->create();

    $type = MatterType::factory()->withStages()->create();
    $matter = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $lead->id,
        'matter_type_id' => $type->id,
        'stage' => $type->stages->reject(fn ($s) => $s->is_terminal)->first()->key,
    ]);
    // Dữ liệu cũ: cộng sự được thêm vào đội ngũ TRƯỚC khi vụ bị siết thành restricted.
    $matter->addTeamMember($responsible, MatterRole::Associate);

    $deadline = Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDay(),
        'is_completed' => false,
        'reminders_sent' => [],
    ]);

    $recipients = (new CheckDeadlines)->recipientsFor($deadline, 'd1');

    expect($recipients->pluck('id')->sort()->values()->all())->toBe(
        collect([$lead->id, $admin->id])->sort()->values()->all()
    )->and($recipients->pluck('id')->all())->not->toContain($manager->id)
        ->and($recipients->pluck('id')->all())->not->toContain($responsible->id);
});

// ---------------------------------------------------------------------------------------------
// M6.5 Task 14 (`deadlines/F6`, `spec-gap-05`) — bậc quá hạn tạo một dòng `notifications` TRONG
// HỆ THỐNG, cạnh email (SPEC §6.8: bậc "< 0" ghi "Đánh dấu quá hạn, TẠO THÔNG BÁO CẢNH BÁO").
// ---------------------------------------------------------------------------------------------

it('creates a database notification for the responsible lawyer at the overdue tier', function () {
    Mail::fake();
    $deadline = deadlineDueIn(-3);

    (new CheckDeadlines)->handle();

    expect(DatabaseNotification::query()
        ->where('notifiable_id', $deadline->responsible_user_id)
        ->where('type', DeadlineOverdueAlert::class)
        ->count())->toBe(1);
});

/**
 * Cặp âm/dương với test trên: các bậc CHƯA quá hạn (7/3/1 ngày, SPEC §6.8 chỉ ghi "Email") không
 * tạo thông báo trong hệ thống — chỉ bậc `< 0` mới làm việc đó.
 */
it('does not create a database notification at the seven-day tier', function () {
    Mail::fake();
    $deadline = deadlineDueIn(7);

    (new CheckDeadlines)->handle();

    expect(DatabaseNotification::query()
        ->where('notifiable_id', $deadline->responsible_user_id)
        ->where('type', DeadlineOverdueAlert::class)
        ->count())->toBe(0);
});

/**
 * "Chỉ tạo MỘT dòng mỗi mốc mỗi bậc" — cùng khoá chống trùng `reminders_sent` mà email dùng, nên
 * cron gọi lại (gia hạn gói, đổi múi giờ, người quản trị chạy tay) không đẻ thêm thông báo.
 */
it('never creates a second database notification for the same deadline and tier, however often the job runs', function () {
    Mail::fake();
    $deadline = deadlineDueIn(-3);

    (new CheckDeadlines)->handle();
    (new CheckDeadlines)->handle();
    (new CheckDeadlines)->handle();

    expect(DatabaseNotification::query()
        ->where('notifiable_id', $deadline->responsible_user_id)
        ->where('type', DeadlineOverdueAlert::class)
        ->count())->toBe(1);
});

/**
 * R3: "người nhận thư về một vụ việc là người được xem vụ đó", ĐÚNG NHƯ email cùng bậc — cùng
 * `$recipients` đã tính, không phải một luật nhận thứ hai. Bậc quá hạn cộng
 * `ResolveStaffRecipients::supervisorsFor()` (một quản lý được xem vụ), nên cả người phụ trách
 * LẪN quản lý đều phải nhận thông báo trong hệ thống, đúng số người mà `Mail::assertSent(...)` đã
 * đếm ở test "marks every earlier tier as spent..." phía trên.
 */
it('notifies every recipient of the overdue tier in the database, matching the mail audience', function () {
    Mail::fake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $deadline = deadlineDueIn(-3);

    (new CheckDeadlines)->handle();

    $notifiedIds = DatabaseNotification::query()
        ->where('type', DeadlineOverdueAlert::class)
        ->pluck('notifiable_id')
        ->all();

    expect($notifiedIds)->toContain($deadline->responsible_user_id)
        ->and($notifiedIds)->toContain($manager->id)
        ->and($notifiedIds)->toHaveCount(2);
});

/**
 * Review Focus 1 (vụ `restricted` đi qua mọi thông báo mới): cùng luật R3 như thư cùng bậc —
 * `supervisorsFor()` thay trưởng phòng bằng admin ở vụ hạn chế. Trưởng phòng không được nhận một
 * thông báo mang mã hồ sơ và tên mốc của một vụ họ không được xem.
 */
it('notifies an admin instead of a manager about an overdue deadline of a restricted matter', function () {
    Mail::fake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->admin()->create();
    $deadline = deadlineDueIn(-2);
    $deadline->matter->update(['confidentiality' => Confidentiality::Restricted]);

    (new CheckDeadlines)->handle();

    $notifiedIds = DatabaseNotification::query()
        ->where('type', DeadlineOverdueAlert::class)
        ->pluck('notifiable_id')
        ->all();

    expect($notifiedIds)->toContain($deadline->responsible_user_id)
        ->and($notifiedIds)->toContain($admin->id)
        ->and($notifiedIds)->not->toContain($manager->id);
});

/**
 * Chuông thông báo của panel admin (`->databaseNotifications()`) dựng lại mỗi dòng bằng
 * `Filament\Notifications\Notification::fromDatabase()`. Dòng do `DeadlineOverdueAlert` ghi phải
 * đọc ra đúng tiêu đề, nội dung (tên mốc, mã hồ sơ) và màu — không thì nó là một dòng trong bảng
 * mà không ai thấy.
 */
it('writes an overdue notification the admin panel bell can render', function () {
    Mail::fake();
    $deadline = deadlineDueIn(-2);

    (new CheckDeadlines)->handle();

    $row = DatabaseNotification::query()->where('type', DeadlineOverdueAlert::class)->sole();
    $rendered = FilamentNotification::fromDatabase($row);

    expect($rendered->getTitle())->toBe(__('deadlines.overdue_notification.title'))
        ->and($rendered->getBody())->toContain($deadline->name)
        ->and($rendered->getBody())->toContain($deadline->matter->code)
        ->and($rendered->getColor())->toBe('danger');
});

/**
 * Fix round 1, M2: một lần `notify()` ném lỗi (ở đây: cho người phụ trách) không được làm những
 * người nhận còn lại của CÙNG mốc mất cảnh báo. Lỗi vẫn được `report()`, không nuốt im lặng.
 */
it('still notifies the other overdue recipients when notifying one of them throws', function () {
    Mail::fake();
    Exceptions::fake();
    $manager = User::factory()->withRole(Role::Manager)->create();
    $deadline = deadlineDueIn(-3);
    $responsibleId = $deadline->responsible_user_id;

    Event::listen(NotificationSending::class, function ($event) use ($responsibleId): void {
        if ($event->notification instanceof DeadlineOverdueAlert && $event->notifiable->getKey() === $responsibleId) {
            throw new RuntimeException('database channel down for this recipient');
        }
    });

    (new CheckDeadlines)->handle();

    $notifiedIds = DatabaseNotification::query()
        ->where('type', DeadlineOverdueAlert::class)
        ->pluck('notifiable_id')
        ->all();

    expect($notifiedIds)->toBe([$manager->id]);
    Exceptions::assertReported(RuntimeException::class);
});
