<?php

use App\Actions\Intake\DeclineIntake;
use App\Actions\Intake\RecordIntake;
use App\Enums\IntakeSource;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

/*
 * M10 Task 7 (R7b) — tác vụ hằng ngày ẩn danh người KHÔNG thành khách quá hạn lưu. Một tác vụ RIÊNG,
 * tên riêng (`prospects.anonymise`), không gộp với tác vụ cảnh báo hồ sơ của M7 (`FlagRetentionExpiry`
 * chỉ cảnh báo và không bao giờ xoá hồ sơ vụ việc — M7 R5): gộp hai thứ là làm mờ đúng ranh giới đó.
 *
 * Hàm toàn cục mang tiền tố `aps…`.
 */
function apsEvent(): Event
{
    $matches = collect(Schedule::events())
        ->filter(fn (Event $event) => $event->description === 'prospects.anonymise')
        ->values();

    expect($matches)->toHaveCount(1, 'phải có đúng một tác vụ lịch tên prospects.anonymise');

    return $matches->first();
}

it('runs every day at 03:30 office time, never twice at once, with a lock that expires after an hour', function () {
    $event = apsEvent();

    // 03:30: sau lượt sao lưu 02:00 (bản sao đêm đó còn dữ liệu cũ thêm một ngày — xem PROGRESS) và xa
    // giờ làm việc, khi không ai đang ghi tiếp nhận để phải chờ khoá `conflict-check`.
    expect(config('app.timezone'))->toBe('Asia/Ho_Chi_Minh')
        ->and($event->getExpression())->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

it('anonymises an expired prospect when the scheduler runs it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(15, 0));

    $assistant = User::factory()->withRole(Role::Assistant)->create();
    $intake = app(RecordIntake::class)->handle($assistant, [
        'contact_name' => 'Người Đã Hết Hạn', 'contact_phone' => '0901000001', 'contact_role' => PartyRole::Plaintiff, 'source' => IntakeSource::Phone,
    ])->intake;
    app(DeclineIntake::class)->handle($assistant, $intake, 'Ngoài lĩnh vực của văn phòng');

    $this->travelTo(now()->setDate(2028, 10, 4)->setTime(3, 30));
    apsEvent()->run(app());

    expect($intake->fresh()->contact_name)->toBeNull()
        ->and($intake->fresh()->anonymised_at)->not->toBeNull();
});
