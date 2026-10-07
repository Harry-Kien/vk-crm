<?php

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\HandoverPackageStatus;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Jobs\SendHandoverPackageReady;
use App\Mail\Staff\HandoverPackageReady;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * `SendHandoverPackageReady` (M7 Task 4): báo luật sư phụ trách gói bàn giao đã sinh xong — thông
 * báo trong hệ thống và MỘT thư cho mỗi người nhận, người nhận qua `ResolveStaffRecipients`.
 *
 * Việc sau gộp M7 (làn fu2): thư gửi THẲNG từ trong job (`Mail::send()`, như mọi job thư khác của
 * `main`), không `Mail::queue()` — xem docblock job. Các test "thật" ở cuối tệp đi qua mailer và hàng
 * đợi thật, vì `Mail::fake()` không bao giờ tuần tự hoá thứ gì.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật sư Hương']);
    $this->matter = Matter::factory()->create([
        'lead_lawyer_id' => $this->lawyer->id,
        'title' => 'Tranh chấp thừa kế',
        'closed_at' => now()->subDay()->toDateString(),
    ]);
    $this->package = Document::factory()->create(['matter_id' => $this->matter->id]);
    $this->archive = MatterArchive::factory()->create([
        'matter_id' => $this->matter->id,
        'handover_document_id' => $this->package->id,
        'handover_status' => HandoverPackageStatus::Ready,
    ]);
});

function shprRun(object $test, ?int $documentId = null): void
{
    (new SendHandoverPackageReady($test->matter->id, $documentId ?? $test->package->id))
        ->handle(app(ResolveStaffRecipients::class));
}

function shprNotifications(User $user): int
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->count();
}

/** Bỏ `Mail::fake()` của `beforeEach` để đi qua mailer thật (transport `array` của phpunit.xml). */
function shprRealMailer(): void
{
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();
}

function shprSentRows(User $user): int
{
    return OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', 'staff.handover_ready')
        ->where('recipient', $user->email)
        ->where('status', OutboundStatus::Sent)
        ->count();
}

it('báo luật sư phụ trách bằng thông báo trong hệ thống và MỘT thư gửi từ trong job, không xếp thêm job thư', function () {
    shprRun($this);

    expect(shprNotifications($this->lawyer))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($this->lawyer->email));
    Mail::assertNothingQueued();
});

it('người bấm và luật sư phụ trách đều được báo; cùng một người thì chỉ một lần', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update(['handover_requested_by' => $manager->id]);

    shprRun($this);

    expect(shprNotifications($this->lawyer))->toBe(1)
        ->and(shprNotifications($manager))->toBe(1);

    Mail::assertSent(HandoverPackageReady::class, 2);

    Mail::fake();
    $this->archive->update(['handover_requested_by' => $this->lawyer->id]);

    shprRun($this);

    Mail::assertSent(HandoverPackageReady::class, 1);
});

it('luật sư phụ trách nghỉ việc thì người nhận là manager (chuỗi dự phòng của ResolveStaffRecipients)', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $this->lawyer->update(['is_active' => false]);

    shprRun($this);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($manager->email));
    expect(shprNotifications($this->lawyer))->toBe(0);
});

it('vụ restricted: manager không xem được vụ thì không nhận thư, người nhận dự phòng là admin', function () {
    $manager = User::factory()->withRole(Role::Manager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->update(['confidentiality' => 'restricted']);
    $this->lawyer->update(['is_active' => false]);

    shprRun($this);

    Mail::assertSent(HandoverPackageReady::class, 1);
    Mail::assertSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($admin->email));
    expect(shprNotifications($manager))->toBe(0);
});

it('không gửi cho khách hàng', function () {
    $clientUser = ClientUser::factory()->create(['client_id' => $this->matter->client_id]);

    shprRun($this);

    Mail::assertNotSent(HandoverPackageReady::class, fn (HandoverPackageReady $mail): bool => $mail->hasTo($clientUser->email));
});

it('bỏ qua khi tài liệu này không còn là gói mới nhất của vụ, hoặc vụ/tài liệu không còn', function () {
    $newer = Document::factory()->create(['matter_id' => $this->matter->id]);
    $this->archive->update(['handover_document_id' => $newer->id]);

    shprRun($this);

    Mail::assertNothingSent();
    expect(shprNotifications($this->lawyer))->toBe(0);

    shprRun($this, $newer->id);
    Mail::assertSent(HandoverPackageReady::class, 1);

    Mail::fake();
    $newer->forceDelete();

    shprRun($this, $newer->id);
    Mail::assertNothingSent();
});

it('thư mang mã và tên vụ, liên kết tới trang vụ việc, và không đính kèm gói', function () {
    $mail = new HandoverPackageReady($this->lawyer, $this->matter, $this->package);

    $html = $mail->render();
    $url = MatterResource::getUrl('view', ['record' => $this->matter], panel: 'admin');

    expect($mail->envelope()->subject)->toBe('Gói bàn giao hồ sơ '.$this->matter->code.' đã sẵn sàng')
        ->and($html)->toContain($this->matter->code)
        ->and($html)->toContain('Tranh chấp thừa kế')
        ->and($html)->toContain('Luật sư Hương')
        ->and($html)->toContain($url)
        ->and($html)->toContain('MUC-LUC.pdf')
        ->and($mail->attachments)->toBe([]);
});

it('thư ghi vào sổ thư đi với mẫu staff.handover_ready và liên kết tới tài liệu gói', function () {
    // `Mail::fake()` (beforeEach) đã thay cả facade lẫn binding `mail.manager` trong container: bỏ
    // instance giả để gọi lại bản thật (transport `array` của phpunit.xml) — cần cho sổ thư đi.
    shprRealMailer();

    Mail::to($this->lawyer->email)->send(new HandoverPackageReady($this->lawyer, $this->matter, $this->package));

    $message = OutboundMessage::query()->withoutGlobalScopes()->sole();

    expect($message->template)->toBe('staff.handover_ready')
        ->and($message->recipient)->toBe($this->lawyer->email)
        ->and($message->related_type)->toBe('document')
        ->and($message->related_id)->toBe($this->package->id);
});

// ---------------------------------------------------------------------------------------------
// Việc sau gộp M7 (làn fu2): không tuần tự hoá model vào `jobs.payload`, không báo trùng khi thử lại
// ---------------------------------------------------------------------------------------------

/**
 * Rà soát gộp M7 (minor, mail): `Mail::queue(new HandoverPackageReady($user, …))` đẩy một
 * `SendQueuedMailable` mang NGUYÊN model `User` (không `SerializesModels`) vào `jobs.payload` — gồm
 * mã băm mật khẩu và `two_factor_secret`/`two_factor_recovery_codes` đã mã hoá (`$hidden` không ảnh
 * hưởng `serialize()`), và sau năm lần hỏng thì nằm vĩnh viễn trong `failed_jobs`. Đi qua hàng đợi
 * THẬT (driver `database`) và mailer thật: không dòng `jobs` nào là thư này, không dòng nào mang bí
 * mật của người nhận, và thư thật sự đi (dòng `sent` trong sổ thư). Chuông Filament
 * (`DatabaseNotification`) vẫn được xếp hàng như mọi chuông khác của hệ thống — nó tuần tự hoá người
 * nhận bằng `SerializesModels` (chỉ id), nên được phép có mặt.
 *
 * Đo trên bản trước bản sửa: hai dòng `jobs` (`DatabaseNotification`, `HandoverPackageReady`), và
 * payload chứa `two_factor_secret`.
 *
 * Mutation probe: đổi `Mail::to(...)->send(...)` trong job lại thành `->queue(...)` — test này ĐỎ.
 */
it('gửi thư ngay trong job: không một dòng jobs nào mang mật khẩu băm hay bí mật 2FA của người nhận', function () {
    config(['queue.default' => 'database']);
    shprRealMailer();

    $this->lawyer->forceFill([
        'two_factor_secret' => 'BI-MAT-2FA-KHONG-DUOC-RA-HANG-DOI',
        'two_factor_recovery_codes' => ['MA-KHOI-PHUC-1', 'MA-KHOI-PHUC-2'],
    ])->save();
    $passwordHash = (string) $this->lawyer->fresh()->getAuthPassword();

    shprRun($this);

    $jobs = DB::table('jobs')->get();
    $payloads = $jobs->pluck('payload')->implode("\n");
    $queuedNames = $jobs->map(fn ($job): string => (string) json_decode($job->payload, true)['displayName'])->all();

    expect($queuedNames)->not->toContain(HandoverPackageReady::class)
        ->and($payloads)->not->toContain('two_factor_secret')
        ->and($payloads)->not->toContain('two_factor_recovery_codes')
        ->and($payloads)->not->toContain($passwordHash)
        // `jobs.payload` là JSON: dấu `/` trong mã băm bị thoát thành `\/`.
        ->and($payloads)->not->toContain(str_replace('/', '\/', $passwordHash))
        ->and(shprSentRows($this->lawyer))->toBe(1)
        // Chuông nằm trên hàng đợi (driver `database`), chưa tới bảng `notifications`.
        ->and($queuedNames)->toContain(DatabaseNotification::class);
});

/**
 * Thử lại không báo trùng (rà soát gộp M7, minor): người nhận thứ hai làm transport ném lỗi → job
 * ném lại, hàng đợi chạy lại TỪ ĐẦU. Người đã nhận thư không nhận thư lẫn chuông thứ hai; người hỏng
 * không nhận chuông thứ hai, và nhận thư ở lần thử sau khi transport lành lại. Người hỏng đứng TRƯỚC
 * (luật sư phụ trách) để đo luôn "một người hỏng không chặn người sau".
 *
 * Mutation probe: bỏ kiểm `alreadyDelivered()` — ĐỎ (luật sư khác nhận thư thứ hai); bỏ kiểm
 * `alreadyAlerted()` — ĐỎ (người hỏng nhận chuông thứ hai); bỏ try/catch quanh `send()` — ĐỎ (người
 * sau không nhận thư ở lần đầu).
 */
it('một người nhận hỏng không chặn người sau, và lần thử lại không báo trùng ai', function () {
    $requester = User::factory()->withRole(Role::Manager)->create();
    $this->archive->update(['handover_requested_by' => $requester->id]);
    $this->lawyer->update(['email' => 'luat-su-hong@vidu.test']);

    shprRealMailer();
    config(['mail.default' => handoverReadySelectiveFailMailer('luat-su-hong@vidu.test')]);

    expect(fn () => shprRun($this))->toThrow(TransportException::class);

    expect(shprSentRows($requester))->toBe(1)
        ->and(shprSentRows($this->lawyer))->toBe(0)
        ->and(shprNotifications($requester))->toBe(1)
        ->and(shprNotifications($this->lawyer))->toBe(1);

    // Transport lành lại; hàng đợi chạy lại job từ đầu.
    config(['mail.default' => 'array']);
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');

    shprRun($this);

    expect(shprSentRows($requester))->toBe(1)
        ->and(shprSentRows($this->lawyer))->toBe(1)
        ->and(shprNotifications($requester))->toBe(1)
        ->and(shprNotifications($this->lawyer))->toBe(1);
});

/**
 * Cặp dương của hai câu chống trùng: chúng khoá theo ĐÚNG tài liệu gói và ĐÚNG mẫu thư. Sinh lại gói
 * (version mới là một tài liệu mới) thì chuông và thư của version cũ không nuốt chuông và thư của
 * version mới; một thư mẫu khác về cùng tài liệu tới cùng người cũng không.
 *
 * Mutation probe: bỏ `related_id` khỏi `alreadyDelivered()` — ĐỎ; bỏ `template` khỏi
 * `alreadyDelivered()` — ĐỎ; đổi khoá chuông sang `viewData.matter_id` — ĐỎ.
 */
it('chuông và thư của version cũ, hay một thư mẫu khác, không nuốt lời báo cho gói mới', function () {
    shprRealMailer();

    shprRun($this);

    expect(shprSentRows($this->lawyer))->toBe(1)
        ->and(shprNotifications($this->lawyer))->toBe(1);

    $second = Document::factory()->create(['matter_id' => $this->matter->id, 'parent_document_id' => $this->package->id, 'version' => 2]);
    $this->archive->update(['handover_document_id' => $second->id]);
    OutboundMessage::factory()->sent()->create([
        'recipient' => $this->lawyer->email,
        'template' => 'staff.new_client_document',
        'related_type' => $second->getMorphClass(),
        'related_id' => $second->id,
    ]);

    shprRun($this, $second->id);

    expect(shprSentRows($this->lawyer))->toBe(2)
        ->and(shprNotifications($this->lawyer))->toBe(2);
});

class HandoverReadySelectiveFailTransport implements TransportInterface
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
        return 'handover-ready-selective-fail://';
    }
}

function handoverReadySelectiveFailMailer(string $failingAddress): string
{
    config()->set('mail.mailers.handover_ready_selective_fail', ['transport' => 'handover_ready_selective_fail']);
    Mail::extend('handover_ready_selective_fail', fn () => new HandoverReadySelectiveFailTransport($failingAddress));

    return 'handover_ready_selective_fail';
}
