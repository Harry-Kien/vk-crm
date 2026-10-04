<?php

use App\Actions\Communication\DeleteCommunicationLog;
use App\Enums\Role;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Xoá một dòng nhật ký liên lạc (M7 Task 8): xoá MỀM, lý do bắt buộc, dòng audit ghi trước lệnh
 * xoá trong cùng transaction. Không bao giờ `forceDelete()` (R5).
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    $this->log = CommunicationLog::factory()->for($this->matter)->create();
});

it('soft-deletes with the reason in the audit line', function () {
    app(DeleteCommunicationLog::class)->handle($this->log, $this->lawyer, '  Nhập trùng.  ');

    expect(CommunicationLog::withTrashed()->find($this->log->id)->trashed())->toBeTrue();

    $audit = Activity::query()->where('event', 'communication_log_deleted')->sole();

    expect($audit->properties->get('reason'))->toBe('Nhập trùng.')
        ->and($audit->properties->get('matter_id'))->toBe($this->matter->id)
        ->and($audit->properties->get('client_id'))->toBe($this->matter->client_id)
        ->and($audit->causer_id)->toBe($this->lawyer->id);
});

it('refuses a blank reason and one longer than the limit', function () {
    expect(fn () => app(DeleteCommunicationLog::class)->handle($this->log, $this->lawyer, '  '))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(DeleteCommunicationLog::class)->handle(
            $this->log,
            $this->lawyer,
            str_repeat('a', DeleteCommunicationLog::REASON_MAX_LENGTH + 1),
        ))->toThrow(ValidationException::class);

    expect($this->log->fresh()->trashed())->toBeFalse()
        ->and(Activity::query()->where('event', 'communication_log_deleted')->count())->toBe(0);
});

it('refuses an entry that is already deleted, without a second audit line', function () {
    app(DeleteCommunicationLog::class)->handle($this->log, $this->lawyer, 'Nhập trùng.');

    expect(fn () => app(DeleteCommunicationLog::class)->handle($this->log, $this->lawyer, 'Lần nữa.'))
        ->toThrow(AuthorizationException::class, __('communications.unavailable'));

    expect(Activity::query()->where('event', 'communication_log_deleted')->count())->toBe(1);
});

it('refuses a deactivated account and someone without matter.update', function () {
    $this->lawyer->update(['is_active' => false]);

    expect(fn () => app(DeleteCommunicationLog::class)->handle($this->log, $this->lawyer->fresh(), 'Lý do.'))
        ->toThrow(AuthorizationException::class);

    $accountant = User::factory()->withRole(Role::Accountant)->create();

    expect(fn () => app(DeleteCommunicationLog::class)->handle($this->log, $accountant, 'Lý do.'))
        ->toThrow(AuthorizationException::class);

    expect($this->log->fresh()->trashed())->toBeFalse();
});

it('refuses on a soft-deleted matter', function () {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->matter->delete();

    expect(fn () => app(DeleteCommunicationLog::class)->handle($this->log, $admin, 'Lý do.'))
        ->toThrow(AuthorizationException::class);

    expect(CommunicationLog::withTrashed()->find($this->log->id)->trashed())->toBeFalse();
});
