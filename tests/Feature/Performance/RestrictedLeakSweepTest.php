<?php

use App\Actions\Performance\BuildTeamWorkload;
use App\Enums\ChecklistItemStatus;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\TeamOverview;
use App\Models\ClientRequest;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\TeamRoster;
use App\Support\Performance\TeamWorkloadRow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * M13 — quét rò rỉ vụ `restricted` qua con số (Review Focus 1, R4): mọi con số về luật sư L mà
 * trưởng phòng đọc phải BẰNG ĐÚNG con số khi vụ `restricted` của L không tồn tại; L và admin thấy vụ
 * đó được tính.
 *
 * Dataset lặp qua TÊN THUỘC TÍNH của DTO (đọc bằng reflection từ hàm dựng): một chỉ số thêm sau mà
 * quên `listableBy()` thì dòng dataset của nó đỏ; một chỉ số thêm sau mà fixture dưới đây chưa có bản
 * ghi cho nó thì vế "admin thấy nó được tính" đỏ — tức fixture phải lớn lên cùng DTO.
 *
 * Task 4 quét `TeamWorkloadRow` (trang "Theo dõi đội ngũ") và trang đó qua Livewire. Task 5 và 7 thêm
 * vào tệp này (trang của một người, widget xu hướng); Task 6 (làn m13b) quét `PerformanceRow` ở tệp
 * riêng. Hàm toàn cục mang tiền tố `m13t4Leak`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
});

/** Thuộc tính định danh — giống nhau ở mọi người xem, không phải một con số. */
const M13T4_LEAK_IDENTITY = ['userId', 'name', 'isActive', 'leadsMatters'];

/** @return list<string> mọi thuộc tính của `TeamWorkloadRow`, theo thứ tự hàm dựng */
function m13t4LeakRowProperties(): array
{
    return array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(TeamWorkloadRow::class, '__construct'))->getParameters(),
    );
}

/**
 * Thế giới chung: trưởng phòng, admin, luật sư L (phụ trách), luật sư cộng sự S, mỗi người có việc
 * trên vụ THƯỜNG cho mọi chỉ số (để "bằng nhau" không phải "cùng bằng 0").
 *
 * @return array{manager: User, admin: User, lead: User, member: User}
 */
function m13t4LeakWorld(): array
{
    $world = [
        'manager' => User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Quét']),
        'admin' => User::factory()->withRole(Role::Admin)->create(['name' => 'Admin Quét']),
        'lead' => User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư L']),
        'member' => User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư S']),
    ];

    $normal = Matter::factory()->create([
        'lead_lawyer_id' => $world['lead']->id,
        'last_client_update_at' => now()->subDays(20),
    ]);
    $normal->addTeamMember($world['member'], MatterRole::Associate);
    m13t4LeakEverything($normal, $world['lead']);

    Matter::factory()->unpublished()->create(['lead_lawyer_id' => $world['lead']->id]);
    Matter::factory()->create(['lead_lawyer_id' => $world['lead']->id])->forceFill(['closed_at' => now()])->save();

    Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00'));
    Audit::record('matter_reassigned', $normal, [], causer: $world['lead']);
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));

    return $world;
}

/** Một bản ghi cho mọi chỉ số "bây giờ" trên `$matter`, do `$holder` giữ. */
function m13t4LeakEverything(Matter $matter, User $holder): void
{
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $holder->id, 'due_date' => today()->subDays(3)->toDateString()]);
    Deadline::factory()->for($matter)->create(['responsible_user_id' => $holder->id, 'due_date' => today()->addDays(2)->toDateString()]);

    $stuck = MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Missing)->create(['is_required' => true]);
    $stuck->forceFill(['created_at' => now()->subDays(20)])->save();
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::PendingReview)->create(['is_required' => true]);
    MatterChecklistItem::factory()->for($matter)->status(ChecklistItemStatus::Accepted)->create(['is_required' => true]);

    ClientRequest::factory()->for($matter)->create(['status' => ClientRequestStatus::New]);
}

/**
 * Các vụ `restricted` của L, mỗi chỉ số một bản ghi: một vụ đã bật cổng, quá hạn cập nhật, có S làm
 * luật sư cộng sự và đủ mốc, đầu mục, yêu cầu, dòng nhật ký (muộn hơn mọi dòng của vụ thường); một vụ
 * chưa bật cổng; một vụ đã kết thúc.
 */
function m13t4LeakRestricted(array $world): void
{
    $secret = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $world['lead']->id,
        'last_client_update_at' => now()->subDays(20),
    ]);
    $secret->addTeamMember($world['member'], MatterRole::Associate);
    m13t4LeakEverything($secret, $world['lead']);

    Matter::factory()->restricted()->unpublished()->create(['lead_lawyer_id' => $world['lead']->id]);
    Matter::factory()->restricted()->create(['lead_lawyer_id' => $world['lead']->id])->forceFill(['closed_at' => now()])->save();

    Carbon::setTestNow(Carbon::parse('2026-10-14 08:00:00'));
    Audit::record('matter_reassigned', $secret, [], causer: $world['lead']);
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00'));
}

/**
 * Dòng của L và S theo người xem, mỗi dòng là mảng thuộc tính. Hỏi cả N11
 * (`withLastMatterActivity`, hình dạng của trang một người — phán quyết N11 của Task 4), để mọi thuộc
 * tính của DTO có giá trị được quét.
 *
 * @return array<int, array<string, mixed>>
 */
function m13t4LeakRows(User $viewer, array $world): array
{
    $viewer = $viewer->fresh();
    $subjects = TeamRoster::subjectsFor($viewer)
        ->filter(fn (User $subject): bool => in_array($subject->getKey(), [$world['lead']->id, $world['member']->id], true))
        ->values();

    return collect(app(BuildTeamWorkload::class)->handle($viewer, $subjects, withLastMatterActivity: true))
        ->map(fn (TeamWorkloadRow $row): array => array_map(
            fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            get_object_vars($row),
        ))
        ->all();
}

it('reads the same :dataset for every tracked person whether or not the lead has restricted matters, and the lead and the admin see them counted', function (string $property) {
    $world = m13t4LeakWorld();

    $before = [
        'manager' => m13t4LeakRows($world['manager'], $world),
        'admin' => m13t4LeakRows($world['admin'], $world),
        'lead' => m13t4LeakRows($world['lead'], $world),
    ];

    m13t4LeakRestricted($world);

    $after = [
        'manager' => m13t4LeakRows($world['manager'], $world),
        'admin' => m13t4LeakRows($world['admin'], $world),
        'lead' => m13t4LeakRows($world['lead'], $world),
    ];

    $lead = $world['lead']->id;
    $member = $world['member']->id;

    // Trưởng phòng: từng người, đúng thuộc tính này, không đổi một giá trị nào — kể cả 0 ↔ "Không áp dụng".
    expect(array_keys($after['manager']))->toBe(array_keys($before['manager']));

    foreach ([$lead, $member] as $subject) {
        expect($after['manager'][$subject][$property])->toBe($before['manager'][$subject][$property]);
    }

    if (in_array($property, M13T4_LEAK_IDENTITY, true)) {
        expect($after['admin'][$lead][$property])->toBe($before['admin'][$lead][$property]);

        return;
    }

    // Admin thấy vụ `restricted` được tính: fixture có một bản ghi cho chỉ số này.
    $adminSees = $after['admin'][$lead][$property] !== $before['admin'][$lead][$property]
        || $after['admin'][$member][$property] !== $before['admin'][$member][$property];

    expect($adminSees)->toBeTrue("admin không thấy vụ restricted trong {$property}: fixture thiếu bản ghi cho chỉ số này");

    // L thấy vụ của chính mình được tính — trừ N2: vụ `restricted` chỉ hiện với người phụ trách
    // (ghế `lead`, không đếm ở N2) và admin, nên không ai có ghế phụ trên vụ đó mà thấy được nó.
    if ($property !== 'teamOpen') {
        expect($after['lead'][$lead][$property])->not->toBe($before['lead'][$lead][$property]);
    }
})->with(fn (): array => m13t4LeakRowProperties());

it('covers every property of TeamWorkloadRow in the dataset above', function () {
    expect(m13t4LeakRowProperties())->toBe(array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(TeamWorkloadRow::class))->getProperties(ReflectionProperty::IS_PUBLIC)))
        ->and(m13t4LeakRowProperties())->toContain('lastMatterActivityAt')
        ->and(count(m13t4LeakRowProperties()))->toBeGreaterThanOrEqual(18);
});

/**
 * Trên trang, qua Livewire: mọi dòng, mọi cột, cùng thứ tự — mặc định và khi sắp xếp theo từng cột
 * sắp xếp được, hai chiều — và cùng chữ trên màn hình, với trưởng phòng, có hay không có vụ
 * `restricted` của L.
 */
it('shows the manager the same team overview, row for row and in every sort order, with or without the restricted matters', function () {
    $world = m13t4LeakWorld();

    $render = function () use ($world): array {
        $this->actingAs($world['manager']->fresh(), 'web');
        $page = Livewire::test(TeamOverview::class);

        $views = ['default' => [
            'records' => $page->instance()->getTableRecords()->map(fn (array $record): array => array_map(
                fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
                $record,
            ))->all(),
            'text' => m13t4LeakVisibleText($page->html()),
        ]];

        foreach (TeamOverview::SORTABLE_COLUMNS as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $page->call('sortTable', $column, $direction);
                $views["{$column}:{$direction}"] = [
                    'order' => $page->instance()->getTableRecords()->keys()->all(),
                    'text' => m13t4LeakVisibleText($page->html()),
                ];
            }
        }

        return $views;
    };

    $before = $render();
    m13t4LeakRestricted($world);
    $after = $render();

    expect(array_keys($after))->toBe(array_keys($before));

    foreach ($before as $view => $content) {
        expect($after[$view])->toBe($content, "trang khác đi ở {$view}");
    }
});

/** Chữ người xem đọc được trên trang: bỏ thẻ và thuộc tính (snapshot Livewire, mã CSRF, id component). */
function m13t4LeakVisibleText(string $html): string
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;

    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}
