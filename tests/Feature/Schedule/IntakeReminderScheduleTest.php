<?php

use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\OutboundStatus;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Mail\Staff\IntakeUnanswered;
use App\Models\OutboundMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Schedule;

/*
 * M10 Task 5 (R5) — tác vụ `intakes.remind-unanswered` trong `routes/console.php`: mỗi 15 phút, khoá
 * chống chồng lấn có hạn, và đúng là nó gọi `RemindUnansweredIntakes`. "Trong giờ làm việc" là cổng
 * của chính Action (đo ở `RemindUnansweredIntakesTest`), không phải của cron: giờ làm việc có MỘT
 * định nghĩa (`config('vkcrm.business_hours')`), và một cron gõ tay `8-17 * * 1-5` là định nghĩa thứ
 * hai sẽ lệch khi văn phòng làm thêm Thứ Bảy.
 */
function iriEvent(): CallbackEvent
{
    // Đúng MỘT tác vụ tên này (gộp làn m10-t7: tệp lịch có hai đoạn nối thêm của hai làn — không đoạn nào được lặp).
    $matches = collect(Schedule::events())->filter(fn ($e) => $e->description === 'intakes.remind-unanswered')->values();

    expect($matches)->toHaveCount(1, 'phải có đúng một tác vụ lịch tên intakes.remind-unanswered');

    $event = $matches->first();

    expect($event)->toBeInstanceOf(CallbackEvent::class);

    return $event;
}

it('registers the reminder every fifteen minutes under its stable id', function () {
    $event = iriEvent();

    expect($event->expression)->toBe('*/15 * * * *');

    foreach (['2026-10-07 08:00', '2026-10-07 13:15', '2026-10-07 17:30', '2026-10-10 10:45'] as $due) {
        test()->travelTo(CarbonImmutable::parse($due, 'Asia/Ho_Chi_Minh'));
        expect($event->isDue(app()))->toBeTrue("phải tới hạn lúc {$due}");
    }

    test()->travelTo(CarbonImmutable::parse('2026-10-07 13:05', 'Asia/Ho_Chi_Minh'));
    expect($event->isDue(app()))->toBeFalse();
});

it('lets a killed run hold its overlap lock for fifteen minutes at most', function () {
    $event = iriEvent();

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});

it('runs RemindUnansweredIntakes when the scheduler fires it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00', 'Asia/Ho_Chi_Minh'));
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    app(RecordIntake::class)->handle($lawyer, [
        'contact_name' => 'Người Gọi Mẫu',
        'contact_phone' => '0832270898',
        'contact_role' => PartyRole::Plaintiff,
        'source' => IntakeSource::Phone,
        'assigned_to' => $lawyer->id,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'Asia/Ho_Chi_Minh'));
    iriEvent()->run(app());

    expect(OutboundMessage::query()->withoutGlobalScopes()
        ->where('template', IntakeUnanswered::TEMPLATE)
        ->where('recipient', $lawyer->email)
        ->where('status', OutboundStatus::Sent)
        ->count())->toBe(1);
});
