<?php

use App\Actions\Client\IssuePortalAccess;
use App\Enums\Role;
use App\Jobs\SendPortalActivationMail;
use App\Mail\Client\Activation;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('is a queued job, not a synchronous one', function () {
    expect(app(SendPortalActivationMail::class, ['clientUserId' => 1]))->toBeInstanceOf(ShouldQueue::class);
});

/**
 * `IssuePortalAccess` chỉ ghi audit và dispatch job — không tự sinh mật khẩu, không tự gửi thư
 * (xem docblock `SendPortalActivationMail`, mục "vì sao sinh mật khẩu ở đó, không ở đây").
 *
 * `queue.default = database`: dưới hàng đợi `sync` (mặc định bộ test), `dispatch()` chạy job
 * NGAY tại chỗ gọi (cùng ghi chú `StageUpdateNotificationTest`), nên phép đo "Action tự nó không
 * gửi gì" cần một hàng đợi thật để job còn NẰM CHỜ, chưa chạy.
 */
it('records an audit line and only queues the activation job, without sending mail itself', function () {
    config(['queue.default' => 'database']);
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    app(IssuePortalAccess::class)->handle($account, $admin);

    Mail::assertNothingSent();
    expect(Activity::query()->where('event', 'client_portal_access_issued')->exists())->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);
});

/**
 * Vòng đầy đủ: job chạy, sinh mật khẩu, ghi hash, gửi thư — và mật khẩu trong thư THẬT SỰ khớp
 * hash vừa ghi (không phải hai giá trị lệch nhau).
 */
it('generates a temporary password, hashes it, and the client can log in with the exact password the email contains', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id, 'must_change_password' => false]);

    app(IssuePortalAccess::class)->handle($account, $admin);

    /** @var Activation|null $sent */
    $sent = null;
    Mail::assertSent(Activation::class, function (Activation $mail) use (&$sent): bool {
        $sent = $mail;

        return true;
    });

    expect($sent)->not->toBeNull();

    $fresh = $account->fresh();
    expect(Hash::check($sent->temporaryPassword, $fresh->password))->toBeTrue()
        ->and($fresh->must_change_password)->toBeTrue();
});

/**
 * Ngoại lệ có chủ ý của R12: gửi tới CHÍNH tài khoản vừa cấp, dù `activated_at` còn null.
 */
it('sends the activation mail to an account whose activated_at is still null', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id, 'activated_at' => null]);

    app(IssuePortalAccess::class)->handle($account, $admin);

    Mail::assertSent(Activation::class, fn ($mail) => $mail->hasTo($account->email));
});

/**
 * R12 vẫn đòi `is_active` — ngoại lệ chỉ bỏ điều kiện `activated_at`, không bỏ hết R12.
 *
 * Mutation probe: xoá điều kiện `$account->is_active` khỏi
 * `SendPortalActivationMail::stillEligibleForActivation()` — test ĐỎ.
 */
it('sends nothing when the account was deactivated before the job ran', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id, 'is_active' => false]);

    app(IssuePortalAccess::class)->handle($account, $admin);

    Mail::assertNothingSent();
});

/**
 * Mutation probe: xoá điều kiện `$account->client()->exists()` khỏi
 * `SendPortalActivationMail::stillEligibleForActivation()` — test ĐỎ.
 */
it('sends nothing when the owning client was soft deleted before the job ran', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);
    $client->delete();

    app(IssuePortalAccess::class)->handle($account, $admin);

    Mail::assertNothingSent();
});

/**
 * R6: mật khẩu tạm không bao giờ hiện ở tiêu đề — chỉ trong thân thư, nơi chỉ người nhận đọc.
 *
 * Fix round 1 (finding Important 3): bản trước chỉ đo HTML, chưa từng đo phần VĂN BẢN THUẦN — một
 * lần sửa thêm mật khẩu vào `document-text`/`activation-text` mà quên `activation` (HTML) sẽ lọt
 * qua test cũ.
 */
it('never puts the temporary password in the subject, only in the body', function () {
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    $mail = new Activation($account, 'MatKhauTamThoiBiMat123');
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($subject)->not->toContain('MatKhauTamThoiBiMat123')
        ->and($html)->toContain('MatKhauTamThoiBiMat123')
        ->and($text)->toContain('MatKhauTamThoiBiMat123');
});

/**
 * R6, ranh giới nội dung: thư này không nói gì về khách hàng chủ tài khoản (chỉ tên/email của
 * CHÍNH tài khoản cổng và mật khẩu tạm) — một cột nội bộ của Client (`note`) không bao giờ được
 * vào thư, đo bằng chuỗi đánh dấu ở cả ba nơi, không bằng mắt.
 */
it('never carries the owning clients internal note into the activation mail', function () {
    $client = Client::factory()->create(['note' => 'DAU-HIEU-NOI-BO-'.uniqid()]);
    $account = ClientUser::factory()->create(['client_id' => $client->id, 'name' => 'Nguyen Van A']);

    $mail = new Activation($account, 'MatKhauTamThoiBiMat123');
    $subject = $mail->envelope()->subject;
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();

    expect($subject)->not->toContain($client->note)
        ->and($html)->not->toContain($client->note)
        ->and($text)->not->toContain($client->note)
        // Cặp dương: tên của chính người nhận vẫn phải có mặt.
        ->and($html)->toContain('Nguyen Van A')
        ->and($text)->toContain('Nguyen Van A');
});

/**
 * Fix round 1 (finding Important 3, R6 ranh giới người nhận): dispatch cho MỘT tài khoản cụ thể
 * (`clientUserId`) không bao giờ gửi cho một tài khoản khác đang tồn tại trong CSDL, kể cả một tài
 * khoản của một khách hàng khác đang hoàn toàn đủ điều kiện theo R12 — job chỉ đọc đúng khoá chính
 * đã nhận, không truy vấn một danh sách nào có thể mở rộng.
 */
it('only emails the exact account it was issued for, never another eligible account', function () {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);
    $strangerClient = Client::factory()->create();
    $strangerAccount = ClientUser::factory()->activated()->create(['client_id' => $strangerClient->id, 'is_active' => true]);

    app(IssuePortalAccess::class)->handle($account, $admin);

    Mail::assertSent(Activation::class, fn ($mail) => $mail->hasTo($account->email));
    Mail::assertNotSent(Activation::class, fn ($mail) => $mail->hasTo($strangerAccount->email));
});

/**
 * Job hỏng hẳn (hết `$tries`): người vừa cấp quyền được báo trong hệ thống, nếu còn hoạt động.
 */
it('tells the actor in-app when the activation job fails for good', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    app(SendPortalActivationMail::class, ['clientUserId' => $account->id, 'actorId' => $admin->id])
        ->failed(new RuntimeException('SMTP'));

    $notice = $admin->fresh()->notifications()->latest()->first();

    expect($notice)->not->toBeNull()
        ->and($notice->data['title'] ?? null)->toBe(__('client_users.activation_failed_notification.title'));
});

/** Không có actor còn hoạt động: rơi xuống mọi admin đang hoạt động — "không bao giờ im lặng". */
it('falls back to every active admin when the actor is no longer active', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $inactiveActor = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    app(SendPortalActivationMail::class, ['clientUserId' => $account->id, 'actorId' => $inactiveActor->id])
        ->failed(new RuntimeException('SMTP'));

    expect($admin->fresh()->notifications()->count())->toBe(1)
        ->and($inactiveActor->fresh()->notifications()->count())->toBe(0);
});

// =========================================================================================
// Việc sau gộp M6 (làn fu, mục 6 — N1 của rà soát cuối làn m6): thư kích hoạt từng hiện mật khẩu
// tạm không nhãn, và dùng CÙNG một câu "Văn phòng đã tạo tài khoản" cho cả lần cấp lại, đổi email
// và bật lại — không nói mật khẩu trước không còn dùng được.
// =========================================================================================

/** Mutation probe: bỏ dòng nhãn khỏi `activation-text.blade.php` → ĐỎ. */
it('labels the temporary password in both the HTML and the plain-text body', function () {
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    $mail = new Activation($account, 'MatKhauTamThoiBiMat123');
    $html = $mail->render();
    $text = view($mail->content()->text, $mail->content()->with)->render();
    $label = __('portal.email.activation.password_label');

    expect($label)->not->toBe('portal.email.activation.password_label')
        ->and($html)->toContain($label)
        ->and(strpos($html, $label))->toBeLessThan(strpos($html, 'MatKhauTamThoiBiMat123'))
        ->and($text)->toContain($label.' MatKhauTamThoiBiMat123');
});

/**
 * Lần CẤP LẠI (nút "Cấp lại mật khẩu", đổi email, bật lại tài khoản) nói rõ mật khẩu trước không
 * còn dùng được và không nói "đã tạo tài khoản"; lần tạo đầu tiên thì ngược lại.
 *
 * Mutation probe: bỏ nhánh `$reissue` khỏi `Activation::content()` → ĐỎ.
 */
it('says the earlier password no longer works only when access is re-issued', function () {
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);
    $previous = __('portal.email.activation.previous_invalid');
    $created = __('portal.email.activation.line');

    $first = new Activation($account, 'MatKhauTamThoiBiMat123');
    $reissued = new Activation($account, 'MatKhauTamThoiBiMat123', reissue: true);

    $render = fn (Activation $mail): array => [
        $mail->render(),
        view($mail->content()->text, $mail->content()->with)->render(),
    ];

    [$firstHtml, $firstText] = $render($first);
    [$reissuedHtml, $reissuedText] = $render($reissued);

    expect($previous)->not->toBe('portal.email.activation.previous_invalid')
        ->and($firstHtml)->not->toContain($previous)
        ->and($firstText)->not->toContain($previous)
        ->and($firstHtml)->toContain($created)
        ->and($reissuedHtml)->toContain($previous)
        ->and($reissuedText)->toContain($previous)
        ->and($reissuedHtml)->not->toContain($created)
        ->and($reissuedText)->not->toContain($created)
        ->and($reissued->envelope()->subject)->toBe(__('portal.email.activation.subject_reissued'))
        ->and($first->envelope()->subject)->toBe(__('portal.email.activation.subject'));
});

/** Cờ "cấp lại" đi từ Action qua job hàng đợi tới thư — không đoán lại ở giữa. */
it('carries the re-issue flag from IssuePortalAccess through the queued job to the mail', function (bool $reissue) {
    Mail::fake();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $client = Client::factory()->create();
    $account = ClientUser::factory()->create(['client_id' => $client->id]);

    app(IssuePortalAccess::class)->handle($account, $admin, reissue: $reissue);

    Mail::assertSent(Activation::class, fn (Activation $mail): bool => $mail->hasTo($account->email) && $mail->reissue === $reissue);
})->with([
    'first issue' => [false],
    're-issue' => [true],
]);
