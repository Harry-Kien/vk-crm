<?php

use App\Actions\Notification\ResendOutboundMessage;
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

/** Mutation probe: bỏ nhánh `status !== Failed` (trước transaction) VÀ bản khoá trong transaction → ĐỎ. */
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
