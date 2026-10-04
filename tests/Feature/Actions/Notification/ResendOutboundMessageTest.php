<?php

use App\Actions\Notification\ResendOutboundMessage;
use App\Enums\OutboundChannel;
use App\Enums\OutboundStatus;
use App\Enums\Role;
use App\Exceptions\OutboundMessageNotResendable;
use App\Jobs\ResendOutboundMessageJob;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * Lời gọi THẲNG (ép) vào `ResendOutboundMessage::handle()` — thứ Livewire cho phép (gọi tên action
 * bất kỳ) và thứ một job/lệnh tương lai có thể làm. Nút trên màn hình đã ẩn cho những trường hợp
 * dưới đây (xem `tests/Feature/Filament/ResendOutboundMessageTest.php`); ở đây đo rằng chính Action
 * cũng từ chối, không chỉ giao diện.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
});

function forcedFailedStageRow(array $overrides = []): OutboundMessage
{
    $client = Client::factory()->create();
    $account = ClientUser::factory()->activated()->create(['client_id' => $client->id, 'is_active' => true]);
    $matter = Matter::factory()->create(['client_id' => $client->id]);
    $log = StageLog::factory()->create(['matter_id' => $matter->id]);

    return OutboundMessage::factory()->create([
        'template' => 'client.stage_update',
        'related_type' => 'stage_log',
        'related_id' => $log->id,
        'recipient' => $account->email,
        'status' => OutboundStatus::Failed,
        'error' => 'TransportException',
        ...$overrides,
    ]);
}

/**
 * Mutation probe: bỏ `Gate::forUser($actor)->authorize('resend', ...)` khỏi `handle()` → ĐỎ (manager
 * xếp hàng được một lần gửi lại).
 */
it('refuses a forced call by a manager who can see the row', function () {
    $row = forcedFailedStageRow();
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(Gate::forUser($manager)->allows('view', $row))->toBeTrue();

    expect(fn () => app(ResendOutboundMessage::class)->handle($manager, $row))
        ->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
    expect(Activity::query()->where('event', 'outbound_message_resent')->count())->toBe(0);
});

/** Cặp dương của test trên: cùng dòng, admin → xếp hàng đúng một job. */
it('queues one resend job for an admin on the same row', function () {
    $row = forcedFailedStageRow();

    expect(app(ResendOutboundMessage::class)->handle(User::factory()->withRole(Role::Admin)->create(), $row))->toBe(1);

    Queue::assertPushed(ResendOutboundMessageJob::class, 1);
});

/**
 * Mutation probe: bỏ nhánh `$target === null || related_type mismatch` khỏi `handle()` → ĐỎ (Action
 * không ném; với `client.otp` nó ném lỗi khác hoặc đi tiếp).
 */
it('refuses a forced call on a template that must not be resent, naming the reason', function (string $template) {
    $row = forcedFailedStageRow(['template' => $template]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(ResendOutboundMessage::class)->handle($admin, $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.template_reasons')[$template]);

    Queue::assertNothingPushed();
})->with(['client.otp', 'staff.deadline_reminder', 'client.activation', 'undeclared']);

/**
 * Việc sau gộp M6 (làn fu, mục 2): hai họ thư của main từng rơi vào `default => null` của
 * `ResendTargets::for()` — bị chặn tình cờ, và lời từ chối là câu chung "hệ thống không biết dựng
 * lại thư này", sai với `staff.instalment_overdue` (dòng nhật ký mang đủ đợt và khoá ngày đến hạn).
 * Nay mỗi họ là một mục tường minh của `ResendTargets::NOT_RESENDABLE`, kèm câu RIÊNG; họ
 * `staff.backup_alert.*` có hậu tố động (loại sự cố) nên tra theo họ, không theo tên đầy đủ.
 *
 * Mutation probe: bỏ `staff.instalment_overdue` (hoặc `staff.backup_alert.*`) khỏi
 * `ResendTargets::NOT_RESENDABLE` → ĐỎ (lời từ chối rơi về câu `default`).
 */
it('refuses a forced resend of the instalment and backup mail families with their own reason, not the generic one', function (string $template, string $reasonKey) {
    $row = forcedFailedStageRow(['template' => $template, 'related_type' => null, 'related_id' => null]);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $reasons = __('outbound.resend.refused.template_reasons');

    expect($reasons)->toHaveKey($reasonKey)
        ->and($reasons[$reasonKey])->not->toBe($reasons['default'])
        ->and(fn () => app(ResendOutboundMessage::class)->handle($admin, $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.template', ['reason' => $reasons[$reasonKey]]));

    Queue::assertNothingPushed();
})->with([
    'staff.instalment_overdue' => ['staff.instalment_overdue', 'staff.instalment_overdue'],
    'staff.backup_alert.backup_failed' => ['staff.backup_alert.backup_failed', 'staff.backup_alert.*'],
    'staff.backup_alert.cleanup_failed' => ['staff.backup_alert.cleanup_failed', 'staff.backup_alert.*'],
    'staff.backup_alert.unhealthy' => ['staff.backup_alert.unhealthy', 'staff.backup_alert.*'],
]);

/**
 * `status` chỉ được hỏi MỘT chỗ trong `handle()`: trên bản đọc có khoá bên trong transaction (nhánh
 * hỏi trước transaction trên bản trong bộ nhớ đã bị bỏ — xem chú thích trong `handle()`).
 *
 * Mutation probe: bỏ điều kiện `$locked->status !== OutboundStatus::Failed` → ĐỎ.
 */
it('refuses a forced call on a row that is not failed', function (OutboundStatus $status) {
    $row = forcedFailedStageRow(['status' => $status]);
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(fn () => app(ResendOutboundMessage::class)->handle($admin, $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.not_failed'));

    Queue::assertNothingPushed();
})->with([
    'queued' => [OutboundStatus::Queued],
    'sent' => [OutboundStatus::Sent],
]);

/**
 * Dòng trong bộ nhớ còn `failed` (trang đã mở từ trước) nhưng trong CSDL đã đổi — bản đọc có khoá
 * trong transaction là thứ quyết định, không bản trong bộ nhớ.
 *
 * Mutation probe: bỏ điều kiện `$locked->status !== Failed` → ĐỎ.
 */
it('decides on the locked database row, not on a stale in-memory copy', function () {
    $row = forcedFailedStageRow();
    OutboundMessage::query()->withoutGlobalScopes()->whereKey($row->id)->update(['status' => OutboundStatus::Sent]);

    expect(fn () => app(ResendOutboundMessage::class)->handle(User::factory()->withRole(Role::Admin)->create(), $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.not_failed'));

    Queue::assertNothingPushed();
});

/** Bản ghi liên quan đã xoá cứng: không dựng lại được thư. */
it('refuses when the record the mail was about has been hard-deleted', function () {
    $row = forcedFailedStageRow();
    StageLog::query()->whereKey($row->related_id)->forceDelete();

    expect(fn () => app(ResendOutboundMessage::class)->handle(User::factory()->withRole(Role::Admin)->create(), $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.related_gone'));
});

/**
 * Ability `resend`: chỉ nhân sự admin. Tài khoản cổng khách không bao giờ, dù là "người nhận" của
 * dòng đó. Mutation probe: bỏ vế `$user instanceof User` → ĐỎ (hasRole trên ClientUser).
 */
it('never grants resend to a client portal account', function () {
    $row = forcedFailedStageRow();
    $account = ClientUser::query()->where('email', $row->recipient)->sole();

    expect(Gate::forUser($account)->allows('resend', $row))->toBeFalse()
        ->and(Gate::forUser(User::factory()->withRole(Role::Admin)->create())->allows('resend', $row))->toBeTrue()
        ->and(Gate::forUser(User::factory()->withRole(Role::Lawyer)->create())->allows('resend', $row))->toBeFalse();
});

/**
 * M12 R13 (Task 7) — dòng của kênh thông báo đẩy mang giá trị `PushTopic` làm mẫu, TRÙNG tên mẫu thư
 * (`client.stage_update`…), và `recipient` là `client_user:{id}`. Lời gọi ép vào `handle()` trên một
 * dòng push `failed` phải bị từ chối bằng câu riêng — không bao giờ xếp một lần gửi lại THƯ.
 *
 * Mutation probe: bỏ cổng `channel !== Email` ở đầu phần kiểm của `handle()` → ĐỎ (một job gửi lại
 * thư được xếp).
 */
it('refuses a forced resend of a failed push row, whose topic shares the mail template name', function () {
    $mail = forcedFailedStageRow();
    $account = ClientUser::query()->where('email', $mail->recipient)->sole();
    $row = forcedFailedStageRow([
        'channel' => OutboundChannel::Push,
        'recipient' => 'client_user:'.$account->id,
        'related_id' => $mail->related_id,
        'payload' => ['title' => 'Luật Vũ Khang', 'body' => 'Hồ sơ của anh/chị có cập nhật mới. Chạm để xem.'],
        'error' => 'HTTP 500 Internal Server Error',
    ]);

    expect(ResendOutboundMessage::canResend($row))->toBeFalse()
        ->and(fn () => app(ResendOutboundMessage::class)->handle(User::factory()->withRole(Role::Admin)->create(), $row))
        ->toThrow(OutboundMessageNotResendable::class, __('outbound.resend.refused.channel'));

    Queue::assertNothingPushed();
    expect(Activity::query()->where('event', 'outbound_message_resent')->count())->toBe(0);
});

/**
 * Lớp thứ hai: job gửi lại (xếp từ trước, hay do một đường tương lai) cũng không dựng lại thư từ một
 * dòng không phải email.
 *
 * Mutation probe: bỏ cổng kênh ở `ResendOutboundMessageJob::handle()` → ĐỎ (thư tiến độ được gửi).
 */
it('sends no mail when a resend job runs on a push row', function () {
    Mail::fake();
    $mail = forcedFailedStageRow();
    $account = ClientUser::query()->where('email', $mail->recipient)->sole();
    $row = forcedFailedStageRow([
        'channel' => OutboundChannel::Push,
        'recipient' => 'client_user:'.$account->id,
        'related_id' => $mail->related_id,
    ]);

    (new ResendOutboundMessageJob($row->id, User::factory()->withRole(Role::Admin)->create()->id))->handle();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});
