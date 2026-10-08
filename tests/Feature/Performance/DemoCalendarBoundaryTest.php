<?php

use App\Enums\Confidentiality;
use App\Filament\Admin\Pages\Performance;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Performance\PerformancePeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TeamPerformanceSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * M13 Task 8, vòng sửa 1 (I1) — số của dữ liệu mẫu không được đổi theo ngày trong tháng. Mốc "quá hạn mấy ngày
 * nay" của vụ `restricted` từng là `today() - 3`: ngày 1–3 của mỗi tháng nó rơi vào "tháng trước", thành mốc lỡ
 * thứ hai của vụ `restricted`, và luật sư A tự xem thấy 4 mốc lỡ thay vì 3 (`DemoWalkthroughTest` bước 3 đỏ ba
 * ngày mỗi tháng — cùng loại lỗi lịch với 21 test đỏ ngày cuối tháng của M9). Ở đây dựng lại dữ liệu mẫu đúng
 * vào những ngày nguy hiểm (ngày 1 là Chủ nhật, 2, 3, ngày cuối tháng, ngày 1 sau tháng Hai) rồi đọc lại các số
 * mà luồng nghiệm thu khẳng định. Hàm toàn cục mang tiền tố `m13t8Cal`.
 */
beforeEach(function () {
    // MatterSeeder ghi tệp PDF thật (xem DemoDataSeederTest).
    Storage::fake('private');
    Filament::setCurrentPanel('admin');
});

function m13t8CalUser(string $email): User
{
    return User::query()->where('email', $email)->firstOrFail();
}

/** @return array<int|string, array<string, mixed>> dòng "Hiệu suất theo kỳ", kỳ mặc định "tháng trước" */
function m13t8CalRows(User $viewer): array
{
    test()->actingAs($viewer->fresh(), 'web');

    return Livewire::test(Performance::class)->instance()->getTableRecords()->all();
}

it('keeps the demo numbers of last month the same on any day of the month', function (string $now) {
    $this->travelTo(CarbonImmutable::parse($now));
    $this->seed(DatabaseSeeder::class);

    $lawyerA = m13t8CalUser(TeamPerformanceSeeder::LAWYER_A_EMAIL);
    $manager = m13t8CalUser('quanly@luatvukhang.com');
    $restricted = Matter::query()->where('confidentiality', Confidentiality::Restricted->value)
        ->where('lead_lawyer_id', $lawyerA->id)->where('is_published_to_portal', true)->sole();
    $restrictedDeadlines = Deadline::query()->where('matter_id', $restricted->id)->orderBy('due_date')->get();

    $byManager = m13t8CalRows($manager)[$lawyerA->id];

    // Bước 3 của luồng nghiệm thu, qua màn hình: trưởng phòng đọc A 2 đúng hạn, 1 trễ, 2 lỡ; A tự đọc 3 lỡ.
    expect([$byManager['deadlinesOnTime'], $byManager['deadlinesLate'], $byManager['deadlinesMissed']])->toBe([2, 1, 2])
        ->and(m13t8CalRows($lawyerA)[$lawyerA->id]['deadlinesMissed'])->toBe(3);

    // Mốc "quá hạn mấy ngày nay" nằm trong THÁNG NÀY (không trước ngày 1, không sau hôm nay), không bao giờ ở
    // tháng trước; vụ `restricted` có đúng một mốc của tháng trước và vẫn giữ một mốc quá hạn bây giờ.
    expect($restrictedDeadlines)->toHaveCount(2)
        ->and($restrictedDeadlines->last()->due_date->toDateString())->toBeGreaterThanOrEqual(today()->startOfMonth()->toDateString())
        ->and($restrictedDeadlines->last()->due_date->toDateString())->toBeLessThanOrEqual(today()->toDateString())
        ->and(Deadline::query()->where('matter_id', $restricted->id)
            ->whereBetween('due_date', PerformancePeriod::fromFilters(['period' => PerformancePeriod::LAST_MONTH])->bounds())->count())->toBe(1)
        ->and(Deadline::query()->overdue()->where('matter_id', $restricted->id)->exists())->toBeTrue();
})->with([
    'ngày 1, Chủ nhật' => '2026-11-01 09:00:00',
    'ngày 2' => '2026-11-02 09:00:00',
    'ngày 3' => '2026-11-03 09:00:00',
    'ngày cuối tháng' => '2026-11-30 17:00:00',
    'ngày 1 sau tháng Hai' => '2027-03-01 09:00:00',
]);
