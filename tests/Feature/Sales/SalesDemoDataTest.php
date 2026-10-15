<?php

use App\Enums\IntakeStatus;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\IntakeRequests\Pages\ListIntakeRequests;
use App\Filament\Admin\Widgets\LeadFollowUpsDueWidget;
use App\Models\IntakeFollowUp;
use App\Models\IntakeRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
 * Làn kd, Task 1 — dữ liệu mẫu của vai nhân viên kinh doanh: tài khoản `kinhdoanh@luatvukhang.com` (mật
 * khẩu demo như các tài khoản khác, 2FA theo đúng mẫu `StaffSeeder` — secret demo chỉ ở local/testing),
 * vài lead của người này ở các trạng thái khác nhau, có ghi chú chăm sóc, một lead đến hẹn liên hệ lại và
 * một lead đã chuyển cho luật sư. Mọi lead đi qua Action thật. Hàm toàn cục mang tiền tố `sdd…`.
 */
beforeEach(function () {
    Storage::fake('private');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Ho_Chi_Minh'));
    $this->seed(DatabaseSeeder::class);
    $this->sales = User::query()->where('email', 'kinhdoanh@luatvukhang.com')->firstOrFail();
});

it('seeds the demo sales account like every other demo staff account', function () {
    expect($this->sales->position)->toBe(UserPosition::Sales)
        ->and($this->sales->hasRole(Role::Sales->value))->toBeTrue()
        ->and($this->sales->is_active)->toBeTrue()
        ->and(Hash::check(DemoAccountsSeeder::DEMO_PASSWORD, $this->sales->password))->toBeTrue()
        ->and($this->sales->two_factor_secret)->toBe(DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET);
});

it('seeds leads of the sales person in several statuses, with care notes, one due for a call and one handed to a lawyer', function () {
    $leads = IntakeRequest::query()->where('created_by', $this->sales->id)->get();

    expect($leads->count())->toBeGreaterThanOrEqual(4)
        ->and($leads->pluck('status')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($leads->pluck('status')->all())->not->toContain(IntakeStatus::Won, IntakeStatus::Merged)
        ->and(Activity::query()->where('event', 'intake_recorded')->whereIn('subject_id', $leads->modelKeys())->count())->toBe($leads->count());

    $handed = $leads->whereNotNull('consulting_lawyer_id');

    expect($handed)->toHaveCount(1)
        ->and(User::query()->findOrFail($handed->first()->consulting_lawyer_id)->hasRole(Role::Lawyer->value))->toBeTrue()
        ->and(Activity::query()->where('event', 'intake_handed_to_lawyer')->where('subject_id', $handed->first()->id)->exists())->toBeTrue();

    expect(IntakeFollowUp::query()->whereIn('intake_request_id', $leads->modelKeys())->count())->toBeGreaterThanOrEqual(3)
        ->and(IntakeFollowUp::query()->whereIn('intake_request_id', $leads->modelKeys())->pluck('created_by')->unique()->all())->toBe([$this->sales->id]);

    Filament::setCurrentPanel('admin');
    $this->actingAs($this->sales, 'web');

    $this->livewire(ListIntakeRequests::class)
        ->assertCanSeeTableRecords($leads)
        ->assertCountTableRecords($leads->count());

    $due = $leads->filter(fn (IntakeRequest $lead): bool => $lead->next_follow_up_at !== null && $lead->next_follow_up_at->lessThanOrEqualTo(now()->endOfDay()));

    expect($due)->not->toBeEmpty();

    $this->livewire(LeadFollowUpsDueWidget::class)->assertCanSeeTableRecords($due);
});
