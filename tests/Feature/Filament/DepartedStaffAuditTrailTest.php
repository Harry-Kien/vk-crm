<?php

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Filament\Admin\Pages\AiConnections;
use App\Filament\Admin\Resources\Matters\Pages\ViewMatter;
use App\Filament\Admin\Resources\Matters\RelationManagers\MatterActivityRelationManager;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;

/*
|--------------------------------------------------------------------------
| Sửa sau kiểm tra nghiệp vụ toàn hệ thống (làn fb, mục A6)
|--------------------------------------------------------------------------
| Nhân sự đã nghỉ việc bị xoá MỀM (`DeleteStaffMember`). Quan hệ `causer`/`subject` của spatie là
| một `morphTo()` trần, nạp `User` qua `SoftDeletingScope` — nên dòng nhật ký của người đó hiện
| "Hệ thống" và bộ lọc "Người" không còn tên họ, đúng lúc văn phòng cần chứng minh ai đã đưa dữ liệu
| nào ra ngoài qua AI. Hai trang đọc nhật ký phải giữ đúng tên người, kèm nhãn "(đã nghỉ việc)".
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    McpOAuth::openServer();

    $this->admin = User::factory()->admin()->create(['name' => 'Quản Trị Viên']);
    $this->departed = User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)
        ->withAiAccess(AiAccessMode::Read)->create(['name' => 'Luật Sư Đã Nghỉ']);
});

afterEach(function () {
    Livewire::flushState();
});

function departedToolRow(User $user, string $ip): void
{
    Audit::record('mcp_tool_called', null, [
        'channel' => 'mcp',
        'tool' => 'search',
        'outcome' => 'ok',
        'platform' => 'claude',
        'ip' => $ip,
    ], causer: $user);
}

/** Chữ của ô "Người" (ô thứ hai) trên dòng nhật ký MCP mang IP `$ip`. */
function departedAuditPersonCell(string $html, string $ip): ?string
{
    preg_match_all('/<tr[^>]*data-mcp-audit-row[^>]*>(.*?)<\/tr>/s', $html, $rows);

    foreach ($rows[1] as $row) {
        if (str_contains($row, $ip)) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);

            return trim(html_entity_decode(strip_tags($cells[1][1] ?? '')));
        }
    }

    return null;
}

it('keeps the name of a deleted staff member on their AI audit rows, marked as departed, and still filters by them', function () {
    departedToolRow($this->departed, '34.9.0.1');
    $this->departed->delete();

    expect(User::query()->find($this->departed->getKey()))->toBeNull();

    $this->actingAs($this->admin, 'web');

    $label = __('staff_access.departed_name', ['name' => 'Luật Sư Đã Nghỉ']);

    $html = Livewire::test(AiConnections::class)
        ->assertFormFieldExists('user', 'auditFiltersForm', fn (Select $field): bool => ($field->getOptions()[$this->departed->getKey()] ?? null) === $label)
        ->set('auditFilters.user', $this->departed->getKey())
        ->assertSee('34.9.0.1')
        ->html();

    expect(departedAuditPersonCell($html, '34.9.0.1'))->toBe($label);
});

it('shows the plain name, with no departed mark, for a staff member who is still on the books', function () {
    departedToolRow($this->departed, '34.9.0.2');

    $this->actingAs($this->admin, 'web');

    $html = Livewire::test(AiConnections::class)->html();

    expect(departedAuditPersonCell($html, '34.9.0.2'))->toBe('Luật Sư Đã Nghỉ');
});

it('keeps the name of a deleted staff member in the causer column of the system activity log', function () {
    departedToolRow($this->departed, '34.9.0.3');
    $this->departed->delete();

    $row = Activity::query()->where('event', 'mcp_tool_called')->sole();

    $this->actingAs($this->admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertTableColumnStateSet('causer_name', __('staff_access.departed_name', ['name' => 'Luật Sư Đã Nghỉ']), $row);
});

it('keeps the name of a deleted staff member in the causer column of a matter\'s activity tab', function () {
    $lead = User::factory()->withRole(Role::Lawyer)->create();
    $matter = Matter::factory()->create(['lead_lawyer_id' => $lead->id]);

    Audit::record('matter_details_updated', $matter, ['changed_fields' => ['title']], $this->departed);
    $this->departed->delete();

    $row = Activity::query()->where('event', 'matter_details_updated')->sole();

    $this->actingAs($lead, 'web');

    Livewire::test(MatterActivityRelationManager::class, ['ownerRecord' => $matter, 'pageClass' => ViewMatter::class])
        ->assertTableColumnStateSet('causer_name', __('staff_access.departed_name', ['name' => 'Luật Sư Đã Nghỉ']), $row);
});

/*
| Làn fb, mục B (SPEC §16.7): khối Nhật ký MCP gồm cả bốn sự kiện pháp lý của kênh AI — cờ AI của vụ,
| dùng/bỏ nháp AI, xác nhận mốc hạn AI tạo — không chỉ các lần gọi tool.
*/
it('lists the matter AI flag, AI draft and AI deadline confirmation events in the MCP audit block', function () {
    $matter = Matter::factory()->create(['lead_lawyer_id' => $this->departed->id]);

    foreach (['matter_ai_access_changed', 'mcp_draft_used', 'mcp_draft_discarded', 'deadline_ai_confirmed'] as $event) {
        Audit::record($event, $matter, ['matter_id' => $matter->id], $this->departed);
    }

    $this->actingAs($this->admin, 'web');

    Livewire::test(AiConnections::class)
        ->assertSee(__('activity.events.matter_ai_access_changed'))
        ->assertSee(__('activity.events.mcp_draft_used'))
        ->assertSee(__('activity.events.mcp_draft_discarded'))
        ->assertSee(__('activity.events.deadline_ai_confirmed'));
});
