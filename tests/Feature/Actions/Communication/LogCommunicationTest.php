<?php

use App\Actions\Communication\LogCommunication;
use App\Enums\CommunicationType;
use App\Enums\Role;
use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Luật của chính Action ghi nhật ký liên lạc (M7 Task 8) — tầng mà M11 (MCP) sẽ gọi thẳng, không
 * qua màn hình nào. Hành vi màn hình nằm ở `CommunicationLogsRelationManagerTest`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->client = Client::factory()->create(['name' => 'Công ty TNHH Hoa Sen']);
    $this->matter = Matter::factory()->for($this->client)->create(['lead_lawyer_id' => $this->lawyer->id]);
});

function logCall(Matter $matter, User $actor, array $overrides = []): CommunicationLog
{
    return app(LogCommunication::class)->handle(...[
        'matter' => $matter,
        'actor' => $actor,
        'type' => CommunicationType::CallOut,
        'summary' => 'Báo khách lịch phiên toà.',
        ...$overrides,
    ]);
}

it('writes an internal entry blamed on the actor and records an audit line without the summary', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');

    $log = logCall($this->matter, $this->lawyer, ['summary' => '  Báo khách lịch phiên toà.  ']);

    expect($log->summary)->toBe('Báo khách lịch phiên toà.')
        ->and($log->counterpart)->toBe('Công ty TNHH Hoa Sen')
        ->and($log->occurred_at->toDateTimeString())->toBe('2026-10-02 10:00:00')
        ->and($log->is_visible_to_client)->toBeFalse()
        ->and($log->created_by)->toBe($this->lawyer->id);

    $audit = Activity::query()->where('event', 'communication_logged')->sole();

    expect($audit->subject_type)->toBe('communication_log')
        ->and($audit->causer_id)->toBe($this->lawyer->id)
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id)
        ->and($audit->properties->get('type'))->toBe('call_out')
        ->and($audit->properties->has('summary'))->toBeFalse();
});

it('falls back to the client name when the counterpart is blank', function () {
    expect(logCall($this->matter, $this->lawyer, ['counterpart' => '   '])->counterpart)->toBe('Công ty TNHH Hoa Sen')
        ->and(logCall($this->matter, $this->lawyer, ['counterpart' => ' Thẩm phán Lê Văn A '])->counterpart)->toBe('Thẩm phán Lê Văn A');
});

it('refuses a counterpart longer than the column', function () {
    expect(fn () => logCall($this->matter, $this->lawyer, ['counterpart' => str_repeat('ằ', 201)]))
        ->toThrow(ValidationException::class);

    expect(logCall($this->matter, $this->lawyer, ['counterpart' => str_repeat('ằ', 200)])->counterpart)
        ->toBe(str_repeat('ằ', 200));
});

/** `summary` là TEXT (65.535 byte); 16.383 ký tự 4 byte là trần bảo đảm vừa. */
it('refuses an empty summary and one longer than the guaranteed limit', function () {
    expect(fn () => logCall($this->matter, $this->lawyer, ['summary' => '   ']))
        ->toThrow(ValidationException::class);

    expect(fn () => logCall($this->matter, $this->lawyer, ['summary' => str_repeat('ằ', LogCommunication::SUMMARY_MAX_LENGTH + 1)]))
        ->toThrow(ValidationException::class);

    expect(mb_strlen(logCall($this->matter, $this->lawyer, ['summary' => str_repeat('ằ', LogCommunication::SUMMARY_MAX_LENGTH)])->summary))
        ->toBe(LogCommunication::SUMMARY_MAX_LENGTH);
});

/** Nhật ký ghi điều ĐÃ xảy ra: một mốc giờ ở tương lai là một dòng bằng chứng tự mâu thuẫn. */
it('refuses a time in the future, but accepts the past and a few seconds of clock drift', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');

    expect(fn () => logCall($this->matter, $this->lawyer, ['occurredAt' => '2026-10-02 11:00:00']))
        ->toThrow(ValidationException::class);

    expect(logCall($this->matter, $this->lawyer, ['occurredAt' => '2026-09-01 08:30:00'])->occurred_at->toDateTimeString())
        ->toBe('2026-09-01 08:30:00')
        ->and(logCall($this->matter, $this->lawyer, ['occurredAt' => '2026-10-02 10:00:30'])->occurred_at->toDateTimeString())
        ->toBe('2026-10-02 10:00:30');
});

it('refuses an unreadable time', function () {
    expect(fn () => logCall($this->matter, $this->lawyer, ['occurredAt' => 'không phải ngày']))
        ->toThrow(ValidationException::class);
});

it('keeps the duration within the unsigned small integer column', function () {
    expect(fn () => logCall($this->matter, $this->lawyer, ['durationMinutes' => -1]))->toThrow(ValidationException::class)
        ->and(fn () => logCall($this->matter, $this->lawyer, ['durationMinutes' => 65536]))->toThrow(ValidationException::class)
        ->and(logCall($this->matter, $this->lawyer, ['durationMinutes' => 0])->duration_minutes)->toBe(0)
        ->and(logCall($this->matter, $this->lawyer, ['durationMinutes' => 65535])->duration_minutes)->toBe(65535);
});

it('refuses a deactivated account, a soft-deleted matter, and someone outside the team, with one sentence', function () {
    $retired = User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]);
    $retiredMatter = Matter::factory()->create(['lead_lawyer_id' => $retired->id]);
    expect(fn () => logCall($retiredMatter, $retired))
        ->toThrow(AuthorizationException::class, __('communications.unavailable'));

    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    expect(fn () => logCall($this->matter, $outsider))
        ->toThrow(AuthorizationException::class, __('communications.unavailable'));

    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();
    expect(fn () => logCall($this->matter, $admin))
        ->toThrow(AuthorizationException::class, __('communications.unavailable'));

    expect(CommunicationLog::withTrashed()->count())->toBe(0);
});

/** Hỏi lại quyền trên vụ ĐỌC LẠI dưới khoá, không trên đối tượng caller cầm trong tay. */
it('asks the gate about the matter as it is in the database, not as the caller holds it', function () {
    // Đội ngũ đã nạp trong bộ nhớ của đối tượng caller có thêm người ngoài — `MatterPolicy::view`
    // đọc quan hệ đã nạp (`isListableBy`) thay vì truy vấn, nên hỏi trên đối tượng này sẽ cho qua.
    $outsider = User::factory()->withRole(Role::Lawyer)->create();
    $stale = Matter::query()->with('team')->find($this->matter->id);
    $stale->setRelation('team', $stale->team->push($outsider));

    expect($outsider->can('create', [CommunicationLog::class, $stale]))->toBeTrue();

    expect(fn () => logCall($stale, $outsider))
        ->toThrow(AuthorizationException::class);
});
