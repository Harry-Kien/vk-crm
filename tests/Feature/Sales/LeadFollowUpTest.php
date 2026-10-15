<?php

use App\Actions\Intake\AnonymiseProspect;
use App\Actions\Intake\LogIntakeFollowUp;
use App\Enums\FollowUpChannel;
use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn kd, Task 1 — ghi chú chăm sóc: mỗi lần liên hệ khách tiềm năng là MỘT dòng (`intake_follow_ups`:
 * thời gian, kênh gọi/Zalo/email/gặp, nội dung ngắn, người ghi), kèm hẹn liên hệ lại tuỳ chọn
 * (`intake_requests.next_follow_up_at`). Nhật ký liên lạc của vụ việc (`communication_logs`) bắt buộc có
 * vụ, nên không dùng lại được cho người chưa thành khách. Lịch sử hiện trên trang của lead. Nội dung là dữ
 * liệu cá nhân: không vào nhật ký hệ thống, và ẩn danh (R7) xoá nó. Hàm toàn cục mang tiền tố `lfu…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->travelTo(now()->setTime(10, 0));

    $this->sales = User::factory()->withRole(Role::Sales)->create(['name' => 'Kinh Doanh Một']);
    $this->lead = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create([
        'created_by' => $this->sales->id,
        'received_at' => now()->subDays(2),
    ]);
});

function lfuLog(): TestAction
{
    return TestAction::make('logFollowUp')->schemaComponent('followUpActions');
}

it('lets a sales person log a care note with a channel and a next contact date, and shows the history on the lead', function () {
    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $this->lead->getRouteKey()])
        ->assertSee(__('leads.follow_ups.empty'))
        ->callAction(lfuLog(), [
            'channel' => FollowUpChannel::Zalo->value,
            'occurred_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'note' => 'Đã gửi báo phí qua Zalo, khách hẹn trả lời thứ Sáu',
            'next_follow_up_at' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i:s'),
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Đã gửi báo phí qua Zalo, khách hẹn trả lời thứ Sáu')
        ->assertSee(FollowUpChannel::Zalo->label())
        ->assertSee('Kinh Doanh Một')
        ->assertSee(now()->addDays(2)->setTime(9, 0)->format('d/m/Y H:i'));

    $row = IntakeFollowUp::query()->sole();

    expect($row->intake_request_id)->toBe($this->lead->id)
        ->and($row->channel)->toBe(FollowUpChannel::Zalo)
        ->and($row->created_by)->toBe($this->sales->id)
        ->and($this->lead->fresh()->next_follow_up_at->format('Y-m-d H:i'))->toBe(now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i'));

    // Nhật ký hệ thống ghi việc đã làm, KHÔNG nội dung.
    $audit = Activity::query()->where('event', 'intake_follow_up_logged')->sole();

    expect($audit->subject_id)->toBe($this->lead->id)
        ->and($audit->properties['channel'])->toBe('zalo')
        ->and(json_encode($audit->properties, JSON_UNESCAPED_UNICODE))->not->toContain('báo phí');
});

it('clears the next contact date when a care note is logged without one', function () {
    $this->lead->forceFill(['next_follow_up_at' => now()->addDay()])->save();

    app(LogIntakeFollowUp::class)->handle($this->sales, $this->lead, FollowUpChannel::Call, now()->subMinutes(5), 'Gọi lại, khách đã chốt đi văn phòng khác', null);

    expect($this->lead->fresh()->next_follow_up_at)->toBeNull();
});

it('rejects an empty note, a note longer than the column, a contact in the future and a next date in the past', function () {
    $log = fn (string $note, $at, $next = null) => app(LogIntakeFollowUp::class)->handle($this->sales, $this->lead, FollowUpChannel::Call, $at, $note, $next);

    expect(fn () => $log('   ', now()))->toThrow(ValidationException::class)
        ->and(fn () => $log(str_repeat('a', IntakeFollowUp::NOTE_MAX_LENGTH + 1), now()))->toThrow(ValidationException::class)
        ->and(fn () => $log('Gọi lại', now()->addHour()))->toThrow(ValidationException::class, __('leads.follow_ups.errors.occurred_in_future'))
        ->and(fn () => $log('Gọi lại', now(), now()->subHour()))->toThrow(ValidationException::class, __('leads.follow_ups.errors.next_in_past'));

    expect(IntakeFollowUp::query()->count())->toBe(0);

    // Đúng trần cột thì nhận.
    $log(str_repeat('a', IntakeFollowUp::NOTE_MAX_LENGTH), now());

    expect(IntakeFollowUp::query()->count())->toBe(1);

    // Form có cùng trần với cột.
    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $this->lead->getRouteKey()])
        ->callAction(lfuLog(), [
            'channel' => FollowUpChannel::Call->value,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'note' => str_repeat('b', IntakeFollowUp::NOTE_MAX_LENGTH + 1),
        ])
        ->assertHasActionErrors(['note' => 'max']);
});

it('refuses care notes on a lead that is done, and on a lead the person cannot see', function () {
    $won = IntakeRequest::factory()->status(IntakeStatus::Won)->create(['created_by' => $this->sales->id]);

    $this->actingAs($this->sales, 'web');

    $this->livewire(EditIntakeRequest::class, ['record' => $won->getRouteKey()])
        ->assertActionDoesNotExist(lfuLog());

    expect(fn () => app(LogIntakeFollowUp::class)->handle($this->sales, $won, FollowUpChannel::Call, now(), 'Gọi lại', null))
        ->toThrow(ValidationException::class, __('leads.follow_ups.errors.closed'));

    $stranger = User::factory()->withRole(Role::Sales)->create();

    expect(fn () => app(LogIntakeFollowUp::class)->handle($stranger, $this->lead, FollowUpChannel::Call, now(), 'Gọi lại', null))
        ->toThrow(AuthorizationException::class);

    expect(IntakeFollowUp::query()->count())->toBe(0);
});

it('wipes the care notes when the prospect is anonymised', function () {
    $lost = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create(['created_by' => $this->sales->id]);
    app(LogIntakeFollowUp::class)->handle($this->sales, $lost, FollowUpChannel::Meeting, now(), 'Gặp chị Hoa ở quán cà phê Phố Huế', now()->addDay());

    $admin = User::factory()->withRole(Role::Admin)->create();
    app(AnonymiseProspect::class)->erase($admin, $lost->fresh(), 'Chủ thể yêu cầu xoá dữ liệu qua email ngày 14/10');

    $row = IntakeFollowUp::query()->sole();

    expect($row->note)->toBeNull()
        ->and($row->channel)->toBe(FollowUpChannel::Meeting)
        ->and($lost->fresh()->next_follow_up_at)->toBeNull();
});

it('re-checks the right to edit on the locked row: a lead reassigned away mid-request takes no care note', function () {
    // Làn kd, vòng sửa 1 (F4): như "Chuyển cho luật sư" — quyền đọc lại trên dòng đã khoá. Cuộc đua giữa hai
    // request không dựng được qua màn hình, nên test cầm một bản model CŨ của lead đã bị giao đi.
    $manager = User::factory()->withRole(Role::Manager)->create();
    $lead = IntakeRequest::factory()->status(IntakeStatus::Contacted)->create([
        'created_by' => $manager->id,
        'assigned_to' => $this->sales->id,
        'received_at' => now()->subDays(2),
    ]);
    $stale = $lead->fresh();
    IntakeRequest::query()->whereKey($lead->getKey())->update(['assigned_to' => User::factory()->withRole(Role::Sales)->create()->id]);

    expect(fn () => app(LogIntakeFollowUp::class)->handle($this->sales, $stale, FollowUpChannel::Call, now(), 'Gọi lại', now()->addDay()))
        ->toThrow(AuthorizationException::class);

    expect(IntakeFollowUp::query()->count())->toBe(0)
        ->and($lead->fresh()->next_follow_up_at)->toBeNull();
});
