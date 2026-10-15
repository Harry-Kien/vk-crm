<?php

use App\Enums\FollowUpChannel;
use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\IntakeRequests\Pages\EditIntakeRequest;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn fi, A2 — gộp hai lần tiếp nhận không được làm mất phần chăm sóc lead (làn kd): các lần chăm sóc của
 * bản bị gộp sang bản đích (giữ người ghi, thời điểm), luật sư tư vấn sang bản đích khi bản đích chưa có,
 * hẹn liên hệ lại là hẹn SỚM NHẤT còn ở tương lai của hai bản. Nhật ký gộp ghi những gì đã mang sang.
 * Đi qua nút "Gộp vào bản khác" trên trang sửa lead. Hàm toàn cục mang tiền tố `lmc…`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    Carbon::setTestNow('2026-10-15 09:00:00');

    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->sales = User::factory()->withRole(Role::Sales)->create();
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create();
});

afterEach(fn () => Carbon::setTestNow());

function lmcLead(array $attributes = []): IntakeRequest
{
    return IntakeRequest::factory()->status(IntakeStatus::Contacted)->create([
        'assigned_to' => test()->sales->id,
        ...$attributes,
    ]);
}

function lmcFollowUp(IntakeRequest $lead, User $author, string $at): IntakeFollowUp
{
    $followUp = IntakeFollowUp::factory()->for($lead)->make(['channel' => FollowUpChannel::Zalo, 'occurred_at' => $at]);
    $followUp->blameOn($author)->save();

    return $followUp;
}

function lmcMerge(IntakeRequest $source, IntakeRequest $target): void
{
    test()->actingAs(test()->manager, 'web');

    test()->livewire(EditIntakeRequest::class, ['record' => $source->getRouteKey()])
        ->callAction('merge', data: ['merge_target' => $target->id])
        ->assertHasNoErrors();
}

function lmcReceivedAudit(IntakeRequest $target): Activity
{
    return Activity::query()->where('event', 'intake_merge_received')->where('subject_id', $target->id)->sole();
}

it('moves the follow-ups of the merged lead to the target, keeping who logged them and when', function () {
    $source = lmcLead();
    $target = lmcLead();
    $first = lmcFollowUp($source, $this->sales, '2026-10-10 08:30:00');
    $second = lmcFollowUp($source, $this->lawyer, '2026-10-12 14:00:00');
    lmcFollowUp($target, $this->sales, '2026-10-13 10:00:00');

    lmcMerge($source, $target);

    expect(IntakeFollowUp::query()->where('intake_request_id', $source->id)->count())->toBe(0)
        ->and(IntakeFollowUp::query()->where('intake_request_id', $target->id)->count())->toBe(3)
        ->and($first->fresh()->intake_request_id)->toBe($target->id)
        ->and($first->fresh()->created_by)->toBe($this->sales->id)
        ->and($first->fresh()->occurred_at->toDateTimeString())->toBe('2026-10-10 08:30:00')
        ->and($second->fresh()->created_by)->toBe($this->lawyer->id)
        ->and($second->fresh()->occurred_at->toDateTimeString())->toBe('2026-10-12 14:00:00')
        ->and(lmcReceivedAudit($target)->properties['follow_ups_moved'])->toBe(2);

    // Trang lead đích hiện các lần chăm sóc vừa sang.
    $this->livewire(EditIntakeRequest::class, ['record' => $target->getRouteKey()])
        ->assertSee('10/10/2026');
});

it('hands the target to the lawyer the merged lead was handed to, when the target had none', function () {
    $source = lmcLead(['consulting_lawyer_id' => $this->lawyer->id, 'handed_to_lawyer_at' => '2026-10-11 16:00:00']);
    $target = lmcLead();

    lmcMerge($source, $target);

    $target->refresh();

    expect($target->consulting_lawyer_id)->toBe($this->lawyer->id)
        ->and($target->handed_to_lawyer_at->toDateTimeString())->toBe('2026-10-11 16:00:00')
        ->and($target->isVisibleTo($this->lawyer))->toBeTrue()
        ->and(lmcReceivedAudit($target)->properties['consulting_lawyer_carried'])->toBeTrue();
});

it('keeps the target lawyer when both leads were handed to different lawyers, and logs the other one', function () {
    $other = User::factory()->withRole(Role::Lawyer)->create();
    $source = lmcLead(['consulting_lawyer_id' => $other->id, 'handed_to_lawyer_at' => '2026-10-11 16:00:00']);
    $target = lmcLead(['consulting_lawyer_id' => $this->lawyer->id, 'handed_to_lawyer_at' => '2026-10-12 09:00:00']);

    lmcMerge($source, $target);

    $target->refresh();
    $properties = lmcReceivedAudit($target)->properties;

    expect($target->consulting_lawyer_id)->toBe($this->lawyer->id)
        ->and($target->handed_to_lawyer_at->toDateTimeString())->toBe('2026-10-12 09:00:00')
        ->and($properties['source_consulting_lawyer_id'])->toBe($other->id)
        ->and($properties->has('consulting_lawyer_carried'))->toBeFalse();
});

it('takes the earliest follow-up appointment still in the future from either lead', function (?string $sourceAt, ?string $targetAt, ?string $expected, bool $fromSource) {
    $source = lmcLead(['next_follow_up_at' => $sourceAt]);
    $target = lmcLead(['next_follow_up_at' => $targetAt]);

    lmcMerge($source, $target);

    expect($target->fresh()->next_follow_up_at?->toDateTimeString())->toBe($expected)
        ->and(lmcReceivedAudit($target)->properties->has('next_follow_up_from_source'))->toBe($fromSource);
})->with([
    'target overdue, source ahead' => ['2026-10-18 09:00:00', '2026-10-14 09:00:00', '2026-10-18 09:00:00', true],
    'source sooner' => ['2026-10-17 09:00:00', '2026-10-20 09:00:00', '2026-10-17 09:00:00', true],
    'target sooner' => ['2026-10-20 09:00:00', '2026-10-17 09:00:00', '2026-10-17 09:00:00', false],
    'only the source has one' => ['2026-10-17 09:00:00', null, '2026-10-17 09:00:00', true],
    'source only in the past' => ['2026-10-14 09:00:00', null, null, false],
    'both in the past keeps the target' => ['2026-10-13 09:00:00', '2026-10-14 09:00:00', '2026-10-14 09:00:00', false],
]);

/*
 * Làn fi, vòng sửa 1 (I2): bản nguồn đã đóng (từ chối, khách không theo) không nằm trong `OpenLeads`, nên luật
 * sư tư vấn của nó có thể đã nghỉ việc hay đổi sang vai không nhận lead mà không bị chặn. Bản đích (đang mở)
 * chỉ nhận luật sư đó khi người đó còn nhận được lead (`HandIntakeToLawyer::canReceive()`); không thì id vào
 * nhật ký gộp, bản đích không có luật sư tư vấn.
 */
it('does not hand the target to the consulting lawyer of a closed source who can no longer receive leads', function (string $status, string $who) {
    $gone = match ($who) {
        'deactivated' => User::factory()->withRole(Role::Lawyer)->create(['is_active' => false]),
        'moved to sales' => User::factory()->withRole(Role::Sales)->create(),
    };
    $source = lmcLead([
        'status' => IntakeStatus::from($status),
        'consulting_lawyer_id' => $gone->id,
        'handed_to_lawyer_at' => '2026-10-11 16:00:00',
    ]);
    $target = lmcLead(['assigned_to' => User::factory()->withRole(Role::Sales)->create()->id]);

    lmcMerge($source, $target);

    $target->refresh();
    $properties = lmcReceivedAudit($target)->properties;

    expect($target->consulting_lawyer_id)->toBeNull()
        ->and($target->handed_to_lawyer_at)->toBeNull()
        ->and($properties['source_consulting_lawyer_id'])->toBe($gone->id)
        ->and($properties->has('consulting_lawyer_carried'))->toBeFalse()
        // Người đã sang vai kinh doanh không thấy lead của một nhân viên kinh doanh khác qua vế "luật sư tư vấn".
        ->and($target->isVisibleTo($gone->fresh()))->toBeFalse();
})->with(['lost', 'declined'])->with(['deactivated', 'moved to sales']);
