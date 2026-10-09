<?php

use App\Actions\Schedule\CheckDeadlines;
use App\Enums\CreatedVia;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * M13 R20, viết khi gộp `main` (M13) vào làn M11 (rà soát cuối M11, I1 b): mốc tạo qua trợ lý AI
 * (`created_via = mcp`, tool `create_deadline`) mà chưa ai xác nhận tính như mốc thường ở mọi con số —
 * "một mốc hạn thật không được im lặng chỉ vì AI tạo" (SPEC §6.14 "Mốc tạo qua AI", kế hoạch M11
 * Task 12). Đo qua hai màn hình của M13, không qua Action:
 *
 *  - "Hiệu suất theo kỳ" (kỳ mặc định "tháng trước"): mốc đến hạn trong kỳ, chưa xong khi hết kỳ, là
 *    "lỡ" ở P1;
 *  - "Theo dõi đội ngũ": mốc đó có mặt ở N5 ("Mốc quá hạn"), và N5 bằng đúng số mốc của người đó mà
 *    `CheckDeadlines::tierFor()` coi là quá hạn (`OVERDUE_KEY`) — trang và lịch nhắc cùng một câu trả
 *    lời. Câu giải thích N5 nói rõ mốc AI chưa xác nhận được tính.
 *
 * Xem lúc 15/10/2026 10:00 (kỳ "tháng trước" là tháng 9). Hàm toàn cục mang tiền tố `m11r20`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

    $this->manager = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Trang']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư Mốc AI']);
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
});

/**
 * Một mốc của luật sư trên vụ của test. Factory không chặn thuộc tính, nên `created_via` và
 * `confirmed_at` (ngoài `$fillable`) được đặt thẳng như tool `create_deadline` để lại.
 *
 * @param  array<string, mixed>  $attributes
 */
function m11r20Deadline(array $attributes): Deadline
{
    return Deadline::factory()->for(test()->matter)->create([
        'responsible_user_id' => test()->lawyer->id,
        'created_at' => '2026-09-01 09:00:00',
        ...$attributes,
    ]);
}

/** @return array<string, string> nhãn cột => chữ trong ô của dòng mang tên `$name` */
function m11r20Cells(string $html, string $name): array
{
    preg_match('/<thead\b.*?<\/thead>/s', $html, $head);
    preg_match_all('/<th\b.*?<\/th>/s', $head[0] ?? '', $headers);
    preg_match_all('/<tr\b.*?<\/tr>/s', $html, $rows);

    $matching = array_values(array_filter($rows[0], fn (string $row): bool => str_contains($row, e($name))));
    expect($matching)->toHaveCount(1);

    preg_match_all('/<td\b.*?<\/td>/s', $matching[0], $cells);

    $text = fn (string $cell): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES)));

    $labels = array_map($text, $headers[0]);
    $values = array_map($text, $cells[0]);

    expect($labels)->toHaveCount(count($values));

    return array_combine($labels, $values);
}

it('counts an unconfirmed AI-created deadline that ran past its due date as missed in P1 of the period page', function () {
    $ai = m11r20Deadline(['due_date' => '2026-09-20', 'created_via' => CreatedVia::Mcp, 'confirmed_at' => null]);

    expect($ai->fresh()->created_via)->toBe(CreatedVia::Mcp)
        ->and($ai->fresh()->confirmed_at)->toBeNull();

    $this->actingAs($this->manager, 'web');
    $cells = m11r20Cells(Livewire::test(Performance::class)->html(), 'Luật Sư Mốc AI');

    expect($cells[__('performance.columns.p1')])
        ->toContain(__('performance.period_page.p1_breakdown', ['on_time' => 0, 'late' => 0, 'missed' => 1]));
});

it('lists an unconfirmed AI-created overdue deadline in N5, with the same count CheckDeadlines::tierFor() reminds as overdue', function () {
    $unconfirmed = m11r20Deadline(['due_date' => '2026-09-20', 'created_via' => CreatedVia::Mcp, 'confirmed_at' => null]);
    $confirmed = m11r20Deadline(['due_date' => '2026-10-12', 'created_via' => CreatedVia::Mcp, 'confirmed_at' => '2026-10-01 09:00:00']);
    $web = m11r20Deadline(['due_date' => '2026-10-10']);
    // Mốc AI chưa xác nhận chưa tới hạn: bậc nhắc d3, không quá hạn — không vào N5.
    $upcoming = m11r20Deadline(['due_date' => '2026-10-17', 'created_via' => CreatedVia::Mcp, 'confirmed_at' => null]);

    $tiers = collect([$unconfirmed, $confirmed, $web, $upcoming])
        ->map(fn (Deadline $deadline): ?string => app(CheckDeadlines::class)->tierFor($deadline->fresh()));

    expect($tiers->all())->toBe([CheckDeadlines::OVERDUE_KEY, CheckDeadlines::OVERDUE_KEY, CheckDeadlines::OVERDUE_KEY, 'd3']);

    $this->actingAs($this->manager, 'web');
    $page = Livewire::test(TeamOverview::class);
    $html = $page->html();

    $overdueByTier = $tiers->filter(fn (?string $tier): bool => $tier === CheckDeadlines::OVERDUE_KEY)->count();

    expect($page->instance()->getTableRecords()[$this->lawyer->id]['overdueDeadlines'])->toBe($overdueByTier)
        ->and(m11r20Cells($html, 'Luật Sư Mốc AI')[__('performance.columns.n5')])->toBe((string) $overdueByTier)
        // Câu giải thích N5 nói đúng điều trang làm: mốc AI chưa xác nhận được tính.
        ->and($html)->toContain(e(__('performance.explain.n5')))
        ->and(__('performance.explain.n5'))->toContain('Gồm cả mốc tạo qua trợ lý AI chưa xác nhận.');
});
