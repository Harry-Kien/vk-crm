<?php

use App\Actions\Settings\SendTestMailToSelf;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Filament\Admin\Pages\Settings\MailSettingsPage;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Jobs\SendTestMail;
use App\Mail\Staff\TestMail;
use App\Models\OutboundMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Làn st Task 1, mục 5 — "Thư điện tử": trạng thái gửi thư (không in mật khẩu) và nút "Gửi thư thử"
 * tới email của chính người bấm — qua hàng đợi, sau commit, ghi sổ thư như mọi thư khác, tối đa
 * {@see SendTestMailToSelf::MAX_PER_WINDOW} lần mỗi 10 phút, có nhật ký.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->withRole(Role::Admin)->create(['email' => 'quantri@vidu.vn']);

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.vidu.vn',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'guithu@vidu.vn',
        'mail.mailers.smtp.password' => 'MatKhauRatBiMat123',
        'mail.from.address' => 'no-reply@vidu.vn',
        'mail.from.name' => 'Văn phòng Mẫu',
    ]);
});

function mailPage()
{
    return Livewire::test(MailSettingsPage::class);
}

it('opens for an admin only', function (?Role $role) {
    $user = $role === null ? $this->admin : User::factory()->withRole($role)->create();

    $response = $this->actingAs($user, 'web')->get(MailSettingsPage::getUrl(panel: 'admin'));

    $role === null ? $response->assertOk()->assertSee(__('settings.mail.title')) : $response->assertNotFound();
})->with([
    'admin' => [null],
    'trưởng phòng' => [Role::Manager],
    'luật sư' => [Role::Lawyer],
    'trợ lý' => [Role::Assistant],
    'kế toán' => [Role::Accountant],
    'kinh doanh' => [Role::Sales],
]);

it('shows how mail goes out, from which address, and never the password', function () {
    $this->actingAs($this->admin, 'web');

    mailPage()
        ->assertSee(__('settings.mail.transport.smtp', ['host' => 'smtp.vidu.vn', 'port' => 587]))
        ->assertSee('no-reply@vidu.vn')
        ->assertSee('Văn phòng Mẫu')
        ->assertDontSee('MatKhauRatBiMat123')
        ->assertSee(OutboundMessageResource::getUrl(), escape: false);
});

it('says plainly when mail is not really sent anywhere', function (string $mailer) {
    config(['mail.default' => $mailer]);

    $this->actingAs($this->admin, 'web');

    mailPage()->assertSee(__('settings.mail.transport.'.$mailer))->assertSee(__('settings.mail.not_configured'));
})->with(['log', 'array']);

/** Mutation probe: bỏ `->afterCommit()` khi xếp job → `afterCommit === true` đỏ. */
it('queues a test mail to the admin\'s own address after commit, and audits it', function () {
    Queue::fake();
    $this->actingAs($this->admin, 'web');

    mailPage()
        ->callAction('sendTestMail')
        ->assertNotified(__('settings.mail.test.queued', ['email' => 'quantri@vidu.vn']));

    Queue::assertPushed(SendTestMail::class, fn (SendTestMail $job): bool => $job->userId === $this->admin->id && $job->afterCommit === true);

    $row = Activity::query()->where('event', 'test_mail_requested')->sole();

    expect($row->causer_id)->toBe($this->admin->id)
        ->and($row->properties['recipient'])->toBe('quantri@vidu.vn')
        ->and(__('activity.events.test_mail_requested'))->not->toBe('activity.events.test_mail_requested');
});

/** Mutation probe: bỏ `RateLimiter::tooManyAttempts()` → lần thứ tư vẫn xếp job. */
it('allows three test mails per ten minutes', function () {
    Queue::fake();
    $this->actingAs($this->admin, 'web');

    foreach (range(1, 3) as $attempt) {
        mailPage()->callAction('sendTestMail')->assertNotified(__('settings.mail.test.queued', ['email' => 'quantri@vidu.vn']));
    }

    mailPage()->callAction('sendTestMail')->assertNotified(__('actions.failed_title'));

    Queue::assertPushed(SendTestMail::class, 3);
    expect(Activity::query()->where('event', 'test_mail_requested')->count())->toBe(3);

    $this->travel(11)->minutes();

    mailPage()->callAction('sendTestMail')->assertNotified(__('settings.mail.test.queued', ['email' => 'quantri@vidu.vn']));
    Queue::assertPushed(SendTestMail::class, 4);
});

it('delivers the test mail through the outbound ledger like every other mail', function () {
    config(['mail.default' => 'array']);
    $this->actingAs($this->admin, 'web');

    mailPage()->callAction('sendTestMail');

    $row = OutboundMessage::query()->withoutGlobalScopes()->where('template', TestMail::TEMPLATE)->sole();

    expect($row->recipient)->toBe('quantri@vidu.vn')
        ->and($row->status)->toBe(OutboundStatus::Sent)
        ->and((array) __('outbound.templates'))->toHaveKey(TestMail::TEMPLATE);

    mailPage()->assertSee(__('settings.mail.recent.heading'))->assertSee(OutboundStatus::Sent->label());
});

/** Người bấm mất quyền (hay bị vô hiệu) trước khi job chạy: không thư nào đi. Mutation probe: bỏ lọc quyền trong `ResolveStaffRecipients::forTestMail()` → đỏ. */
it('sends nothing when the requester is no longer an active admin by the time the job runs', function (Closure $demote) {
    Mail::fake();

    $demote($this->admin);

    (new SendTestMail($this->admin->id))->handle();

    Mail::assertNothingSent();
})->with([
    'hạ xuống luật sư' => [fn (User $user) => $user->syncRoles([Role::Lawyer->value])],
    'bị vô hiệu hoá' => [fn (User $user) => $user->forceFill(['is_active' => false])->save()],
]);

it('sends to the requester when still an active admin', function () {
    Mail::fake();

    (new SendTestMail($this->admin->id))->handle();

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('quantri@vidu.vn'));
});

it('refuses a test mail from someone who lost the admin role while the page was open', function () {
    Queue::fake();
    $this->actingAs($this->admin, 'web');

    $page = mailPage();

    $this->admin->syncRoles([Role::Manager->value]);
    $this->actingAs($this->admin->fresh(), 'web');

    $page->call('mountAction', 'sendTestMail')->assertNotFound();

    Queue::assertNothingPushed();
});

/**
 * Lớp gác cuối: Action tự hỏi `settings.manage`, không tin màn hình đã gác (trang chặn trước ở `boot()`,
 * nên điều kiện này chỉ đo được khi gọi thẳng Action). Mutation probe: bỏ lần hỏi Gate đầu
 * `SendTestMailToSelf::handle()` → job được xếp, nhật ký được ghi.
 */
it('refuses a test mail from anyone without settings.manage even without the page', function (Role $role) {
    Queue::fake();

    $user = User::factory()->withRole($role)->create();

    expect(fn () => app(SendTestMailToSelf::class)->handle($user))->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
    expect(Activity::query()->where('event', 'test_mail_requested')->count())->toBe(0);
})->with(array_values(array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::Admin)));
