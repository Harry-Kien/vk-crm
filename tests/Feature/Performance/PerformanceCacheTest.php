<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Admin\Pages\Performance;
use App\Filament\Admin\Pages\TeamMember;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\Performance\PerformanceCache;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| R11 — lối thoát cuối: số của "Hiệu suất theo kỳ" và đầu trang của một người giữ tạm theo người xem
|--------------------------------------------------------------------------
|
| Rà soát cuối làn M13, I1: ngân sách R11 vẫn vỡ sau index (quý của trưởng phòng ~1,0 s / 500 ms, trang
| một người 233–246 ms / 200 ms), nên kế hoạch dùng đúng lối thoát nó đã viết sẵn: `Cache::remember`, khoá
| gồm id người xem và bộ lọc, TTL ≤ 5 phút, và test hai người xem không bao giờ dùng chung một mục.
|
| Cả bộ test tắt bộ nhớ tạm (`tests/Pest.php` đặt `vkcrm.performance.cache_seconds` = 0), để mọi test
| "đọc, đổi dữ liệu, đọc lại" — R19, các lượt quét rò rỉ — vẫn đo phép tính chứ không đo bộ nhớ tạm. Tệp
| này bật lại nó với ĐÚNG kho của production (`database`, `cache.serializable_classes`), nên một lớp giá
| trị chưa được phép giải tuần tự hoá thì đỏ ở đây chứ không phải trên máy chủ.
|
| Hai người xem ở test đầu cùng vai trò (hai trưởng phòng), cùng tập người, cùng kỳ: chỉ id người xem
| tách hai mục — bỏ id khỏi khoá thì trưởng phòng thứ nhất đọc số có vụ `restricted` của người thứ hai.
| Hàm toàn cục mang tiền tố `m13fr1Cache`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    config(['vkcrm.performance.cache_seconds' => 300, 'cache.default' => 'database']);

    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    $this->managerOne = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Một']);
    $this->managerTwo = User::factory()->withRole(Role::Manager)->create(['name' => 'Trưởng Phòng Hai']);
    $this->lawyer = User::factory()->withRole(Role::Lawyer)->create(['name' => 'Luật Sư A']);

    // Vụ thường của luật sư A: một mốc lỡ của tháng 9 (kỳ mặc định "tháng trước"), còn quá hạn hôm nay.
    $this->matter = Matter::factory()->create(['lead_lawyer_id' => $this->lawyer->id]);
    m13fr1CacheMissed($this->matter, $this->lawyer, '2026-09-10');

    // Vụ `restricted` của trưởng phòng Hai: chỉ chính người đó (và admin) thấy mốc lỡ này.
    $secret = Matter::factory()->restricted()->create(['lead_lawyer_id' => $this->managerTwo->id]);
    m13fr1CacheMissed($secret, $this->managerTwo, '2026-09-12');
});

function m13fr1CacheMissed(Matter $matter, User $holder, string $due): Deadline
{
    return Deadline::factory()->for($matter)->create([
        'due_date' => $due,
        'responsible_user_id' => $holder->id,
        'is_completed' => false,
        'completed_at' => null,
        'created_at' => Carbon::parse($due)->subWeek()->setTime(9, 0),
    ]);
}

/** @return array<int|string, array<string, mixed>> các dòng "Hiệu suất theo kỳ" (kỳ mặc định) như `$viewer` đọc */
function m13fr1CacheRecords(User $viewer): array
{
    test()->actingAs($viewer->fresh(), 'web');

    return Livewire::test(Performance::class)->instance()->getTableRecords()->all();
}

/** Con số N5 (mốc quá hạn) ở đầu trang của `$subject`, như `$viewer` đọc. */
function m13fr1CacheOverdue(User $viewer, User $subject): string
{
    return m13fr1CacheMetric($viewer, $subject, 'overdueDeadlines');
}

/** Giá trị in dưới ô `$metric` ở đầu trang của `$subject`, như `$viewer` đọc. */
function m13fr1CacheMetric(User $viewer, User $subject, string $metric): string
{
    test()->actingAs($viewer->fresh(), 'web');

    $html = Livewire::test(TeamMember::class, ['user' => $subject->getKey()])->html();

    preg_match('/<div data-vk-metric="'.$metric.'"[^>]*>.*?<dd[^>]*>(.*?)<\/dd>/s', $html, $match);
    expect($match)->toHaveCount(2);

    return trim(strip_tags($match[1]));
}

it('never lets two viewers share an entry, even two managers looking at the same people and period', function () {
    // Trưởng phòng Hai mở trước: mục của họ có mốc lỡ trên vụ `restricted` của chính họ.
    expect(m13fr1CacheRecords($this->managerTwo)[$this->managerTwo->id]['deadlinesMissed'])->toBe(1)
        ->and(m13fr1CacheOverdue($this->managerTwo, $this->managerTwo))->toBe('1');

    // Trưởng phòng Một, cùng vai trò, cùng tập người, cùng kỳ, trong TTL: không thấy vụ đó.
    $one = m13fr1CacheRecords($this->managerOne);

    expect($one[$this->managerTwo->id]['deadlinesMissed'])->toBe(0)
        ->and($one[Performance::REFERENCE_KEY]['deadlinesMissed'])->toBe(1)
        ->and(m13fr1CacheOverdue($this->managerOne, $this->managerTwo))->toBe('0');
});

it('keeps one entry per period on the period page and one per person on the one-person page', function () {
    $this->actingAs($this->managerOne->fresh(), 'web');

    $page = Livewire::test(Performance::class);
    expect($page->instance()->getTableRecords()[$this->lawyer->id]['deadlinesMissed'])->toBe(1);

    // Tháng này (10/2026): mốc lỡ của tháng 9 không thuộc kỳ — không phải số đã giữ của tháng trước.
    $page->fillForm(['period' => 'this_month'])->call('applyPeriod');
    expect($page->instance()->getTableRecords()[$this->lawyer->id]['deadlinesMissed'])->toBe(0);

    // Cùng người xem, hai người được xem: hai mục (trưởng phòng Hai giữ thêm một mốc quá hạn của tháng 10).
    m13fr1CacheMissed(Matter::factory()->create(['lead_lawyer_id' => $this->managerTwo->id]), $this->managerTwo, '2026-10-05');

    expect(m13fr1CacheOverdue($this->managerTwo, $this->lawyer))->toBe('1')
        ->and(m13fr1CacheOverdue($this->managerTwo, $this->managerTwo))->toBe('2');
});

it('serves the numbers of both pages from the cache for five minutes, then recomputes them', function () {
    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(1)
        ->and(m13fr1CacheOverdue($this->managerOne, $this->lawyer))->toBe('1');

    // Một mốc lỡ thứ hai ghi thẳng vào CSDL (không qua Action, không nhật ký).
    m13fr1CacheMissed($this->matter, $this->lawyer, '2026-09-20');

    $this->travel(PerformanceCache::MAX_SECONDS - 1)->seconds();

    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(1)
        ->and(m13fr1CacheOverdue($this->managerOne, $this->lawyer))->toBe('1');

    $this->travel(2)->seconds();

    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(2)
        ->and(m13fr1CacheOverdue($this->managerOne, $this->lawyer))->toBe('2');
});

/*
 * N11 là `CarbonImmutable` bên trong `TeamWorkloadRow`: lần mở thứ hai đọc nó TỪ kho `database`, nên lớp đó
 * phải có trong `cache.serializable_classes` — thiếu thì giải tuần tự hoá không dựng lại được thời điểm và trang
 * hỏng ngay ở lần mở thứ hai trong 5 phút.
 */
it('reads the last-activity time of the one-person page back from the cache store intact', function () {
    $this->travelTo(Carbon::parse('2026-10-13 16:45:00'));
    Audit::record('matter_reassigned', $this->matter, [], causer: $this->lawyer);
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));

    expect(m13fr1CacheMetric($this->managerOne, $this->lawyer, 'lastMatterActivityAt'))->toBe('16:45 13/10/2026')
        ->and(DB::table('cache')->where('key', 'like', '%performance:team-member:'.$this->managerOne->id.':%')->count())->toBe(1)
        ->and(m13fr1CacheMetric($this->managerOne, $this->lawyer, 'lastMatterActivityAt'))->toBe('16:45 13/10/2026');
});

it('never keeps an entry longer than five minutes, whatever the configuration says', function () {
    config(['vkcrm.performance.cache_seconds' => 3600]);

    expect(PerformanceCache::seconds())->toBe(PerformanceCache::MAX_SECONDS)
        ->and(PerformanceCache::MAX_SECONDS)->toBe(300)
        ->and(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(1);

    m13fr1CacheMissed($this->matter, $this->lawyer, '2026-09-20');
    $this->travel(PerformanceCache::MAX_SECONDS + 1)->seconds();

    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(2);
});

it('drops the cached rows of other people the moment the viewer stops being allowed to see them', function () {
    $before = m13fr1CacheRecords($this->managerOne);

    expect($before)->toHaveKeys([Performance::REFERENCE_KEY, $this->lawyer->id, $this->managerTwo->id]);

    // Trưởng phòng Một bị đổi thành luật sư, trong TTL: chỉ còn dòng của chính mình.
    $this->managerOne->syncRoles([Role::Lawyer->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(array_keys(m13fr1CacheRecords($this->managerOne)))->toBe([$this->managerOne->id]);
});

it('drops the cached revenue the moment the viewer may no longer read it', function () {
    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['revenueCollected'])->toBe(0);

    RoleModel::findByName(Role::Manager->value, 'web')->revokePermissionTo(Permission::RevenueViewAny->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['revenueCollected'])->toBeNull();
});

it('tells the reader on both pages that the numbers may be up to five minutes old, and only while the cache is on', function () {
    $note = __('performance.cache_note', ['minutes' => 5]);

    $this->actingAs($this->managerOne->fresh(), 'web');

    expect(Livewire::test(Performance::class)->html())->toContain(e($note))
        ->and(Livewire::test(TeamMember::class, ['user' => $this->lawyer->getKey()])->html())->toContain(e($note));

    config(['vkcrm.performance.cache_seconds' => 0]);
    $label = e(__('performance.cache_note_label'));

    expect(Livewire::test(Performance::class)->html())->not->toContain($label)
        ->and(Livewire::test(TeamMember::class, ['user' => $this->lawyer->getKey()])->html())->not->toContain($label);

    // Tắt (như cả bộ test): đổi dữ liệu là đọc lại thấy ngay.
    m13fr1CacheMissed($this->matter, $this->lawyer, '2026-09-20');

    expect(m13fr1CacheRecords($this->managerOne)[$this->lawyer->id]['deadlinesMissed'])->toBe(2);
});
