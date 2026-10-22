<?php

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\ContractEntry;
use App\Filament\Admin\Pages\DocumentLibrary;
use App\Filament\Admin\Pages\Receivables;
use App\Filament\Admin\Pages\Search;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Matters\Pages\ListMatters;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\ClientRequest;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\MatterParty;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Rà soát toàn hệ thống 2026-10-22 — làn security: lượt quét vụ `restricted` trên panel admin
|--------------------------------------------------------------------------
| SPEC §4.6/§5: vụ `restricted` chỉ luật sư phụ trách và quản trị viên thấy — kể cả khi người khác có
| tên trong đội ngũ của nó (luật sư cộng sự, trợ lý), kể cả trưởng phòng (`matter.viewAny`) và kế toán.
| Vụ mang "kim" ở mọi ô nội dung (tiêu đề, bên đối lập, tài liệu, mốc, yêu cầu của khách, nhật ký liên
| lạc, danh mục giấy tờ, việc nội bộ). Với từng người không được thấy: MỌI route GET không tham số của
| panel, MỌI widget (dựng thẳng bằng Livewire — trang chủ nạp widget lười), và ô tìm của các danh sách
| chính cùng trang Tìm kiếm — không kim nào được ra. Một trang/widget mới thêm sau tự vào lượt quét.
| Lượt dương: luật sư phụ trách thấy kim (lượt quét không xanh vì không dựng được gì).
| Hàm toàn cục mang tiền tố `rasw`.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->lead = User::factory()->withRole(Role::Lawyer)->create();
    $this->manager = User::factory()->withRole(Role::Manager)->create();
    $this->teamLawyer = User::factory()->withRole(Role::Lawyer)->create();
    $this->teamAssistant = User::factory()->withRole(Role::Assistant)->create();
    $this->accountant = User::factory()->withRole(Role::Accountant)->create();

    // Cũ hơn 14 ngày không cập nhật cho khách: vụ cũng vào widget "Hồ sơ lâu chưa cập nhật" (§6.4).
    $matter = Matter::factory()->restricted()->create([
        'lead_lawyer_id' => $this->lead->id, 'title' => 'KIMTIEUDE',
        'stage_entered_at' => now()->subDays(30), 'last_client_update_at' => now()->subDays(30),
    ]);
    $matter->addTeamMember($this->teamLawyer, MatterRole::Associate);
    $matter->addTeamMember($this->teamAssistant, MatterRole::Assistant);
    $this->restricted = $matter;

    MatterParty::factory()->for($matter)->defendant()->create(['name' => 'Kimbendoilap']);
    Document::factory()->for($matter)->create(['title' => 'KIMTAILIEU']);
    Deadline::factory()->for($matter)->create(['name' => 'KIMMOCHAN', 'responsible_user_id' => $this->teamLawyer->id, 'due_date' => now()->addDays(2)]);
    ClientRequest::factory()->for($matter)->create(['subject' => 'KIMYEUCAU']);
    CommunicationLog::factory()->for($matter)->create(['counterpart' => 'KIMLIENLAC']);
    MatterChecklistItem::factory()->for($matter)->create(['name' => 'KIMGIAYTO']);
    Task::factory()->forMatter($matter)->create(['title' => 'KIMVIEC', 'assignee_id' => $this->teamAssistant->id, 'assigned_by' => $this->lead->id]);
    Audit::record('matter_details_updated', $matter, ['matter_id' => $matter->id], $this->lead);
});

/** @return list<string> */
function raswNeedles(Matter $matter): array
{
    return ['KIMTIEUDE', 'Kimbendoilap', 'KIMTAILIEU', 'KIMMOCHAN', 'KIMYEUCAU', 'KIMLIENLAC', 'KIMGIAYTO', 'KIMVIEC', $matter->code];
}

/** @return list<class-string<Widget>> */
function raswWidgets(): array
{
    $classes = [];

    foreach ((new Finder)->files()->in(app_path('Filament/Admin/Widgets'))->name('*.php') as $file) {
        $class = 'App\\Filament\\Admin\\Widgets\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Widget::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/** @return list<string> mọi kim (trừ chính chuỗi vừa gõ vào ô tìm) có mặt trong `$html` */
function raswFound(string $html, array $needles, ?string $typed = null): array
{
    return array_values(array_filter($needles, fn (string $needle): bool => $needle !== $typed && str_contains($html, $needle)));
}

/** @return list<string> "nơi: kim" cho mọi chỗ `$viewer` thấy kim */
function raswSweep(User $viewer, Matter $matter): array
{
    test()->actingAs($viewer);
    $needles = raswNeedles($matter);
    $leaks = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'filament.admin.') || ! in_array('GET', $route->methods(), true)
            || preg_match('/\{[^}?]+\}/', $route->uri())) {
            continue;
        }

        foreach (raswFound((string) test()->get('/'.$route->uri())->getContent(), $needles) as $needle) {
            $leaks[] = $route->getName().": {$needle}";
        }
    }

    foreach (raswWidgets() as $widget) {
        if (method_exists($widget, 'canView') && ! $widget::canView()) {
            continue;
        }

        try {
            $html = Livewire::test($widget)->html();
        } catch (Throwable) {
            continue; // widget cần tham số (trang của một người) — có lượt quét riêng ở tests/Feature/Performance.
        }

        foreach (raswFound($html, $needles) as $needle) {
            $leaks[] = class_basename($widget).": {$needle}";
        }
    }

    $terms = ['KIM', 'Kimbendoilap', $matter->code];
    $lists = [ListMatters::class, ListTasks::class, ListOutboundMessages::class, ListClients::class, DocumentLibrary::class, ActivityLogPage::class, ContractEntry::class, Receivables::class];

    foreach ($lists as $page) {
        foreach ($terms as $term) {
            try {
                $html = Livewire::test($page)->searchTable($term)->html();
            } catch (Throwable) {
                continue; // trang không mở cho vai này (404) — đã đo ở vòng route phía trên.
            }

            foreach (raswFound($html, $needles, $term) as $needle) {
                $leaks[] = class_basename($page)." tìm '{$term}': {$needle}";
            }
        }
    }

    foreach ($terms as $term) {
        try {
            $html = Livewire::test(Search::class)->set('term', $term)->html();
        } catch (Throwable) {
            continue;
        }

        foreach (raswFound($html, $needles, $term) as $needle) {
            $leaks[] = "Search tìm '{$term}': {$needle}";
        }
    }

    return $leaks;
}

it('không lộ nội dung vụ restricted trên bất kỳ trang, widget hay ô tìm nào của panel admin', function (string $who) {
    expect(raswSweep($this->{$who}, $this->restricted))->toBe([]);
})->with(['manager', 'teamLawyer', 'teamAssistant', 'accountant']);

it('lượt dương: luật sư phụ trách thấy vụ restricted của mình qua cùng lượt quét', function () {
    $seen = raswSweep($this->lead, $this->restricted);

    expect(collect($seen)->contains(fn (string $line): bool => str_contains($line, 'KIMTIEUDE')))->toBeTrue()
        ->and(collect($seen)->contains(fn (string $line): bool => str_contains($line, 'KIMMOCHAN')))->toBeTrue();
});
