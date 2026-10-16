<?php

use App\Actions\Mcp\SetAiAccessForMany;
use App\Actions\Settings\WriteSettings;
use App\Enums\AiAccessMode;
use App\Enums\AiOfficeStatus;
use App\Enums\AiSetupStep;
use App\Enums\McpToolOutcome;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\AiConnections;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Models\AiAcknowledgement;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Audit;
use App\Support\Mcp\McpSwitches;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\McpOAuth;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Làn ai (2026-10-15) — trang "Kết nối AI" dễ hiểu, dễ bật, dễ theo dõi như CRM quốc tế
|--------------------------------------------------------------------------
| Chủ văn phòng hỏi trang này "hoạt động như thế nào" và muốn nó "chuẩn xịn như các CRM quốc tế".
| Tệp này đo PHẦN TRÌNH BÀY mới: thẻ trạng thái tổng, danh sách bước lấy từ trạng thái THẬT, mục
| pháp lý thu gọn, công tắc tiếng thường có hộp xác nhận, bảng nhân sự có huy hiệu / bộ lọc / thao
| tác hàng loạt, khối "Hoạt động của trợ lý AI" đọc-chỉ. Luật bảo mật của M11 không đổi; các test
| của M11 (`AiConnectionsTest`) vẫn là phép đo của luật đó. Mọi hành vi đo qua Livewire hoặc HTTP.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->admin()->create(['name' => 'Quản Trị Viên']);
});

afterEach(function () {
    Livewire::flushState();
});

function aioPage(User $actor): mixed
{
    test()->actingAs($actor, 'web');

    return Livewire::test(AiConnections::class);
}

function aioSwitches(bool $enabled, bool $write = false, ?string $filedOn = null): void
{
    app(WriteSettings::class)->handle([
        McpSwitches::ENABLED => $enabled ? McpSwitches::ON : McpSwitches::OFF,
        McpSwitches::WRITE_ENABLED => $write ? McpSwitches::ON : McpSwitches::OFF,
        McpSwitches::TRANSFER_ASSESSMENT_FILED_ON => $filedOn,
    ], null);
}

function aioLawyer(AiAccessMode $mode = AiAccessMode::Read, array $attributes = []): User
{
    return User::factory()->position(UserPosition::Lawyer)->withRole(Role::Lawyer)->withAiAccess($mode)->create($attributes);
}

function aioToolRow(User $user, string $tool, McpToolOutcome $outcome = McpToolOutcome::Ok, array $extra = []): void
{
    Audit::record('mcp_tool_called', null, [
        'channel' => 'mcp',
        'tool' => $tool,
        'outcome' => $outcome->value,
        'returned_count' => 1,
        'oauth_client_id' => 'client-thu',
        'platform' => 'claude',
        'ip' => '34.0.0.1',
        ...$extra,
    ], causer: $user);
}

/** Giá trị của một ô `data-…="$key"` trong HTML đã render (chữ đã bỏ khoảng trắng hai đầu), `null` khi vắng. */
function aioAttr(string $html, string $attribute, string $key): ?string
{
    if (preg_match('/<[^>]*\b'.preg_quote($attribute, '/').'="'.preg_quote($key, '/').'"[^>]*>(.*?)<\//s', $html, $match) !== 1) {
        return null;
    }

    return trim(html_entity_decode(strip_tags($match[1])));
}

/** Thẻ mở của phần tử mang `data-…="$key"` (để đọc các thuộc tính khác của nó). */
function aioTag(string $html, string $attribute, string $key): ?string
{
    return preg_match('/<[^>]*\b'.preg_quote($attribute, '/').'="'.preg_quote($key, '/').'"[^>]*>/s', $html, $match) === 1 ? $match[0] : null;
}

/** Trạng thái ✓/○ của từng bước: tên bước → true khi đã xong. */
function aioSteps(string $html): array
{
    preg_match_all('/<li[^>]*data-ai-step="([a-z_]+)"[^>]*data-done="([01])"/', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $m): array => [$m[1] => $m[2] === '1'])->all();
}

// ---------------------------------------------------------------------------------------------
// 1. Thẻ trạng thái tổng.
// ---------------------------------------------------------------------------------------------

it('thẻ trạng thái: công tắc đọc tắt thì trang nói "đang TẮT cho toàn văn phòng", không cảnh báo hồ sơ dù chưa ghi ngày', function () {
    aioSwitches(false);

    $html = aioPage($this->admin)->assertSee(AiOfficeStatus::Off->label())->html();

    expect(aioTag($html, 'data-ai-status', 'off'))->not->toBeNull()
        ->and($html)->not->toContain('data-ai-status-warning')
        ->and($html)->not->toContain(AiOfficeStatus::ReadOnly->label());
});

it('thẻ trạng thái: bật đọc, tắt ghi thì "đang BẬT — chỉ đọc"; chưa ghi ngày nộp hồ sơ thì có cảnh báo vàng', function () {
    aioSwitches(true);

    $html = aioPage($this->admin)
        ->assertSee(AiOfficeStatus::ReadOnly->label())
        ->assertSee(__('ai_connections.status.assessment_missing'))
        ->html();

    expect(aioTag($html, 'data-ai-status', 'read'))->not->toBeNull()
        ->and($html)->toContain('data-ai-status-warning');
});

it('cặp dương của cảnh báo: đã ghi ngày nộp hồ sơ thì thẻ trạng thái không cảnh báo', function () {
    aioSwitches(true, filedOn: '2026-10-01');

    $html = aioPage($this->admin)->assertDontSee(__('ai_connections.status.assessment_missing'))->html();

    expect($html)->not->toContain('data-ai-status-warning');
});

it('thẻ trạng thái: bật cả đọc lẫn ghi thì "đang BẬT — đọc và ghi"', function () {
    aioSwitches(true, true, '2026-10-01');

    $html = aioPage($this->admin)->assertSee(AiOfficeStatus::ReadWrite->label())->html();

    expect(aioTag($html, 'data-ai-status', 'read_write'))->not->toBeNull();
});

it('thẻ trạng thái: công tắc ghi đã lưu BẬT nhưng công tắc đọc tắt thì vẫn là TẮT (ghi chỉ có tác dụng khi đọc bật)', function () {
    aioSwitches(false, true);

    $html = aioPage($this->admin)->html();

    expect(aioTag($html, 'data-ai-status', 'off'))->not->toBeNull()
        ->and($html)->not->toContain(AiOfficeStatus::ReadWrite->label());
});

it('thẻ trạng thái đếm nhân sự được bật, đã cam kết đúng phiên bản, đang có kết nối, lượt dùng 7 ngày', function () {
    aioSwitches(true);
    $this->travelTo(now()->setDateTime(2026, 10, 15, 10, 0));

    $connected = aioLawyer(attributes: ['name' => 'Có Kết Nối']);
    McpOAuth::accessToken($this, $connected);
    aioLawyer(AiAccessMode::ReadWrite);
    $notAcknowledged = aioLawyer();
    AiAcknowledgement::query()->where('user_id', $notAcknowledged->getKey())->delete();

    // Không tính: tài khoản bị vô hiệu hoá còn sót chế độ, người ở chế độ Tắt (kể cả có token sống).
    aioLawyer(attributes: ['is_active' => false]);
    $off = aioLawyer(AiAccessMode::Off);
    McpOAuth::accessToken($this, $off);

    // Bảy ngày = hôm nay và sáu ngày trước; dòng của ngày thứ tám không tính.
    $this->travelTo(now()->setDateTime(2026, 10, 9, 0, 5));
    aioToolRow($connected, 'search');
    $this->travelTo(now()->setDateTime(2026, 10, 8, 23, 55));
    aioToolRow($connected, 'search');
    $this->travelTo(now()->setDateTime(2026, 10, 15, 9, 0));
    aioToolRow($connected, 'fetch', McpToolOutcome::Denied);
    aioToolRow($connected, 'get_matter');

    $html = aioPage($this->admin)->html();

    expect(aioAttr($html, 'data-ai-stat', 'enabled'))->toBe('3')
        ->and(aioAttr($html, 'data-ai-stat', 'acknowledged'))->toBe('2/3')
        ->and(aioAttr($html, 'data-ai-stat', 'connected'))->toBe('1/3')
        ->and(aioAttr($html, 'data-ai-stat', 'calls'))->toBe('3');
});

it('thẻ trạng thái: lời cam kết của phiên bản cũ không tính là đã cam kết', function () {
    aioSwitches(true);
    aioLawyer();

    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    expect(aioAttr(aioPage($this->admin)->html(), 'data-ai-stat', 'acknowledged'))->toBe('0/1');
});

it('thẻ trạng thái: kết nối đã thu hồi (client bị thu hồi) không tính là đang có kết nối', function () {
    aioSwitches(true);
    $lawyer = aioLawyer();
    $client = McpOAuth::client();
    McpOAuth::accessToken($this, $lawyer, $client);

    expect(aioAttr(aioPage($this->admin)->html(), 'data-ai-stat', 'connected'))->toBe('1/1');

    Passport::client()->newQuery()->whereKey($client->getKey())->update(['revoked' => true]);

    expect(aioAttr(aioPage($this->admin)->html(), 'data-ai-stat', 'connected'))->toBe('0/1');
});

// ---------------------------------------------------------------------------------------------
// 2. "Các bước để bắt đầu dùng" — lấy từ trạng thái thật.
// ---------------------------------------------------------------------------------------------

it('danh sách bước có đủ sáu bước theo thứ tự, và hệ thống mới tinh thì chưa bước nào xong', function () {
    $html = aioPage($this->admin)->assertSee(__('ai_connections.setup.heading'))->html();

    expect(aioSteps($html))->toBe([
        'legal' => false,
        'server' => false,
        'staff_enabled' => false,
        'staff_acknowledged' => false,
        'staff_connected' => false,
        'matters' => false,
    ]);

    foreach (AiSetupStep::cases() as $step) {
        expect($html)->toContain(e($step->label()));
    }
});

/** Trạng thái đủ sáu bước; `$undo` bỏ đúng một điều kiện. */
function aioFullSetup(TestCase $test): array
{
    aioSwitches(true, filedOn: '2026-10-01');
    $lawyer = aioLawyer();
    McpOAuth::accessToken($test, $lawyer);
    $matter = Matter::factory()->aiAccessAllowed()->create();

    return ['lawyer' => $lawyer, 'matter' => $matter];
}

it('đủ điều kiện thì sáu bước đều ✓', function () {
    aioFullSetup($this);

    expect(array_values(aioSteps(aioPage($this->admin)->html())))->toBe(array_fill(0, 6, true));
});

it('mỗi bước phản ánh đúng một điều kiện thật: bỏ điều kiện đó thì chỉ bước đó về ○', function (string $step, Closure $undo) {
    $world = aioFullSetup($this);

    $undo($world);

    $steps = aioSteps(aioPage($this->admin)->html());

    expect($steps[$step])->toBeFalse();

    unset($steps[$step]);

    expect(array_filter($steps, fn (bool $done): bool => ! $done))->toBe([]);
})->with([
    'pháp lý: xoá ngày nộp hồ sơ' => ['legal', fn () => app(WriteSettings::class)->handle([McpSwitches::TRANSFER_ASSESSMENT_FILED_ON => null], null)],
    'máy chủ: tắt công tắc đọc' => ['server', fn () => aioSwitches(false, filedOn: '2026-10-01')],
    'vụ việc: không vụ nào bật cho AI' => ['matters', fn (array $world) => Matter::query()->whereKey($world['matter']->getKey())->update(['ai_access' => 'denied'])],
]);

it('bước cam kết chỉ ✓ khi MỌI nhân sự được bật đã cam kết đúng phiên bản; hiện n/m', function () {
    aioFullSetup($this);
    $second = aioLawyer();
    AiAcknowledgement::query()->where('user_id', $second->getKey())->delete();

    $html = aioPage($this->admin)->html();

    expect(aioSteps($html)['staff_acknowledged'])->toBeFalse()
        ->and(aioAttr($html, 'data-ai-step-progress', 'staff_acknowledged'))->toBe(__('ai_connections.setup.progress.ratio', ['done' => 1, 'total' => 2]));

    AiAcknowledgement::factory()->for($second)->create();

    expect(aioSteps(aioPage($this->admin)->html())['staff_acknowledged'])->toBeTrue();
});

it('bước nhân sự được bật và bước kết nối về ○ khi không ai được bật / không ai có kết nối', function () {
    $world = aioFullSetup($this);

    Passport::token()->newQuery()->update(['revoked' => true]);
    Passport::refreshToken()->newQuery()->update(['revoked' => true]);

    $steps = aioSteps(aioPage($this->admin)->html());
    expect($steps['staff_connected'])->toBeFalse()->and($steps['staff_enabled'])->toBeTrue();

    $world['lawyer']->forceFill(['ai_access' => AiAccessMode::Off])->save();

    $steps = aioSteps(aioPage($this->admin)->html());
    expect($steps['staff_enabled'])->toBeFalse()
        ->and($steps['staff_acknowledged'])->toBeFalse();
});

it('mỗi bước có đường làm ngay: mục pháp lý, công tắc, bảng nhân sự, danh sách vụ việc', function () {
    $html = aioPage($this->admin)->html();

    expect(aioTag($html, 'data-ai-step-link', 'legal'))->toContain('href="#viec-phap-ly"')
        ->and(aioTag($html, 'data-ai-step-link', 'server'))->toContain('href="#cong-tac"')
        ->and(aioTag($html, 'data-ai-step-link', 'staff_enabled'))->toContain('href="#nhan-su"')
        ->and(aioTag($html, 'data-ai-step-link', 'matters'))->toContain('href="'.e(MatterResource::getUrl('index')).'"')
        ->and($html)->toContain('id="viec-phap-ly"')
        ->and($html)->toContain('id="cong-tac"')
        ->and($html)->toContain('id="nhan-su"');
});

// ---------------------------------------------------------------------------------------------
// 3. Mục pháp lý thu gọn.
// ---------------------------------------------------------------------------------------------

it('mục pháp lý: chưa ghi ngày thì MỞ sẵn, đủ bốn điều và ô ngày', function () {
    $html = aioPage($this->admin)
        ->assertSee(__('ai_connections.legal.summary'))
        ->assertSee(__('ai_connections.assessment.items.client_consent'))
        ->assertSee(__('ai_connections.assessment.filed_on'))
        ->html();

    expect(aioTag($html, 'data-transfer-assessment', 'pending'))->toMatch('/<details[^>]*\sopen[\s>]/');
});

it('mục pháp lý: đã ghi ngày thì ĐÓNG, bốn điều vẫn còn bên trong, ngày hiện dạng dd/mm/yyyy', function () {
    aioSwitches(true, filedOn: '2026-10-01');

    $html = aioPage($this->admin)
        ->assertSee(__('ai_connections.legal.summary'))
        ->assertSee(__('ai_connections.assessment.items.transfer_assessment'))
        ->assertSee(__('ai_connections.assessment.filed_note', ['date' => '01/10/2026']))
        ->html();

    expect(aioTag($html, 'data-transfer-assessment', 'filed'))->not->toBeNull()
        ->and(aioTag($html, 'data-transfer-assessment', 'filed'))->not->toMatch('/\sopen[\s>]/');
});

// ---------------------------------------------------------------------------------------------
// 4. Công tắc tiếng thường, chi tiết kỹ thuật thu gọn, hộp xác nhận nói hậu quả.
// ---------------------------------------------------------------------------------------------

it('công tắc mang nhãn tiếng thường và một dòng mô tả; tên khoá kỹ thuật chỉ còn trong mục "Chi tiết kỹ thuật"', function () {
    $html = aioPage($this->admin)
        ->assertSee(__('ai_connections.admin.switches.enabled'))
        ->assertSee(__('ai_connections.admin.switches.write_enabled'))
        ->assertSee(__('ai_connections.admin.switches.enabled_help'))
        ->assertSee(__('ai_connections.admin.switches.write_help'))
        ->html();

    expect(__('ai_connections.admin.switches.enabled'))->not->toContain('mcp.')
        ->and(__('ai_connections.admin.switches.write_enabled'))->not->toContain('mcp.');

    preg_match('/<details[^>]*data-ai-tech-details[^>]*>(.*?)<\/details>/s', $html, $details);

    expect($details[1] ?? '')->toContain('mcp.enabled')
        ->toContain('mcp.write_enabled')
        ->toContain(e(__('ai_connections.admin.switches.description')));

    // Ngoài mục thu gọn, tên khoá không còn trong khối công tắc.
    preg_match('/<section[^>]*id="cong-tac"[^>]*>(.*?)<\/section>/s', $html, $section);
    $outside = str_replace($details[0] ?? '', '', $section[1] ?? '');

    expect($section[1] ?? '')->not->toBe('')
        ->and($outside)->not->toContain('mcp.enabled');
});

it('nút lưu công tắc mở hộp xác nhận nói đúng hậu quả của TẮT công tắc đọc; xác nhận thì lưu như cũ', function () {
    aioSwitches(true);

    aioPage($this->admin)
        ->set('switches.enabled', false)
        ->mountAction('confirmSwitches')
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.server_off'))
        ->assertMountedActionModalDontSee(__('ai_connections.admin.switches.confirm.server_on'))
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(McpSwitches::enabled())->toBeFalse()
        ->and(Activity::query()->where('event', 'ai_settings_updated')->count())->toBe(1);
});

it('hộp xác nhận nói hậu quả của BẬT đọc, BẬT ghi, TẮT ghi; không đổi gì thì nói không có gì thay đổi', function () {
    aioSwitches(false);

    aioPage($this->admin)
        ->set('switches.enabled', true)
        ->set('switches.write_enabled', true)
        ->mountAction('confirmSwitches')
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.server_on'))
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.write_on'))
        ->assertMountedActionModalDontSee(__('ai_connections.admin.switches.confirm.server_off'));

    aioSwitches(true, true);

    aioPage($this->admin)
        ->set('switches.write_enabled', false)
        ->mountAction('confirmSwitches')
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.write_off'))
        ->assertMountedActionModalDontSee(__('ai_connections.admin.switches.confirm.write_on'));

    aioPage($this->admin)
        ->mountAction('confirmSwitches')
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.unchanged'))
        ->callMountedAction()
        ->assertNotified(__('ai_connections.admin.switches.unchanged'));
});

it('bật ghi khi công tắc đọc còn tắt: hộp xác nhận nói ghi chưa có tác dụng', function () {
    aioSwitches(false);

    aioPage($this->admin)
        ->set('switches.write_enabled', true)
        ->mountAction('confirmSwitches')
        ->assertMountedActionModalSee(__('ai_connections.admin.switches.confirm.write_without_server'))
        ->assertMountedActionModalDontSee(__('ai_connections.admin.switches.confirm.write_on'));
});

it('hộp xác nhận tự hỏi lại cổng: người mất settings.manage giữa chừng nhận 404, không ghi gì', function () {
    aioSwitches(true);

    $page = aioPage($this->admin)->set('switches.enabled', false);

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    $page->call('mountAction', 'confirmSwitches')->assertNotFound();

    expect(McpSwitches::enabled())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// 5. Bảng nhân sự: huy hiệu, "2 giờ trước", bộ lọc nhanh, đổi chế độ hàng loạt.
// ---------------------------------------------------------------------------------------------

it('bảng nhân sự: lần dùng cuối hiện "2 giờ trước" (giữ ngày giờ đầy đủ làm giá trị), cam kết và kết nối là huy hiệu', function () {
    aioSwitches(true);
    $this->travelTo(now()->setDateTime(2026, 10, 15, 8, 0));
    $lawyer = aioLawyer();
    McpOAuth::accessToken($this, $lawyer);
    aioToolRow($lawyer, 'search');
    $idle = aioLawyer(AiAccessMode::Off);
    AiAcknowledgement::query()->where('user_id', $idle->getKey())->delete();

    $this->travelTo(now()->setDateTime(2026, 10, 15, 10, 0));

    aioPage($this->admin)
        ->assertTableColumnStateSet('last_used_at', '15/10/2026 08:00', $lawyer)
        ->assertTableColumnFormattedStateSet('last_used_at', now()->subHours(2)->diffForHumans(), $lawyer)
        ->assertTableColumnFormattedStateSet('last_used_at', __('ai_connections.admin.never_used'), $idle)
        ->assertTableColumnExists('acknowledged', fn ($column): bool => $column->isBadge() && $column->getColor($column->getState()) === 'success', $lawyer)
        ->assertTableColumnExists('acknowledged', fn ($column): bool => $column->getColor($column->getState()) === 'gray', $idle)
        ->assertTableColumnExists('connections', fn ($column): bool => $column->isBadge() && $column->getColor($column->getState()) === 'success', $lawyer)
        ->assertTableColumnExists('connections', fn ($column): bool => $column->getColor($column->getState()) === 'gray', $idle);

    expect(now()->subHours(2)->diffForHumans())->toBe('2 giờ trước');
});

it('cam kết của phiên bản cũ là huy hiệu cảnh báo (vàng)', function () {
    $lawyer = aioLawyer();
    config(['vkcrm.mcp.policy_version' => '2099-01-01']);

    aioPage($this->admin)
        ->assertTableColumnExists('acknowledged', fn ($column): bool => $column->getColor($column->getState()) === 'warning', $lawyer);
});

it('bộ lọc nhanh "Đang bật", "Chưa cam kết", "Có kết nối" lọc đúng theo trạng thái thật', function () {
    aioSwitches(true);
    $connected = aioLawyer();
    McpOAuth::accessToken($this, $connected);
    $enabledIdle = aioLawyer();
    $notAcknowledged = aioLawyer();
    AiAcknowledgement::query()->where('user_id', $notAcknowledged->getKey())->delete();
    $off = User::factory()->withRole(Role::Assistant)->create();

    aioPage($this->admin)
        ->filterTable('enabled')
        ->assertCanSeeTableRecords([$connected, $enabledIdle, $notAcknowledged])
        ->assertCanNotSeeTableRecords([$off, $this->admin]);

    aioPage($this->admin)
        ->filterTable('not_acknowledged')
        ->assertCanSeeTableRecords([$notAcknowledged, $off, $this->admin])
        ->assertCanNotSeeTableRecords([$connected, $enabledIdle]);

    aioPage($this->admin)
        ->filterTable('connected')
        ->assertCanSeeTableRecords([$connected])
        ->assertCanNotSeeTableRecords([$enabledIdle, $notAcknowledged, $off, $this->admin]);
});

it('bộ lọc "Chưa cam kết" gồm cả người chỉ cam kết phiên bản chính sách CŨ; người đã cam kết bản hiện hành thì không', function () {
    $old = aioLawyer();
    config(['vkcrm.mcp.policy_version' => '2099-01-01']);
    $current = aioLawyer();

    aioPage($this->admin)
        ->filterTable('not_acknowledged')
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$current]);
});

it('bộ lọc "Có kết nối" dùng đúng định nghĩa kết nối: token hết hạn mà refresh còn sống vẫn tính; client bị thu hồi thì không', function () {
    $lawyer = aioLawyer();
    $client = McpOAuth::client();
    McpOAuth::accessToken($this, $lawyer, $client);
    Passport::token()->newQuery()->update(['expires_at' => now()->subMinute()]);

    aioPage($this->admin)->filterTable('connected')->assertCanSeeTableRecords([$lawyer]);

    Passport::client()->newQuery()->whereKey($client->getKey())->update(['revoked' => true]);

    aioPage($this->admin)->filterTable('connected')->assertCanNotSeeTableRecords([$lawyer]);
});

it('đổi chế độ hàng loạt: mỗi người một lần gọi Action (một dòng ai_access_changed mỗi người); người không bật được bị bỏ qua và được nêu tên', function () {
    $first = aioLawyer(AiAccessMode::Off);
    $second = aioLawyer(AiAccessMode::Off);
    $accountant = User::factory()->position(UserPosition::Accountant)->withRole(Role::Accountant)->create(['name' => 'Kế Toán Bỏ Qua']);

    aioPage($this->admin)
        ->selectTableRecords([$first, $second, $accountant])
        ->callAction(TestAction::make('setAiAccessBulk')->table()->bulk(), data: ['ai_access' => AiAccessMode::Read->value])
        ->assertHasNoFormErrors()
        ->assertNotified(__('ai_connections.staff.bulk.done', ['count' => 2]));

    expect($first->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and($second->fresh()->ai_access)->toBe(AiAccessMode::Read)
        ->and($accountant->fresh()->ai_access)->toBe(AiAccessMode::Off);

    $rows = Activity::query()->where('event', 'ai_access_changed')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('causer_id')->unique()->all())->toBe([$this->admin->getKey()])
        ->and($rows->pluck('subject_id')->sort()->values()->all())->toBe(collect([$first->getKey(), $second->getKey()])->sort()->values()->all());
});

it('đổi chế độ hàng loạt báo tên người bị bỏ qua', function () {
    $accountant = User::factory()->position(UserPosition::Accountant)->withRole(Role::Accountant)->create(['name' => 'Kế Toán Bỏ Qua']);

    aioPage($this->admin)
        ->selectTableRecords([$accountant])
        ->callAction(TestAction::make('setAiAccessBulk')->table()->bulk(), data: ['ai_access' => AiAccessMode::Read->value])
        ->assertNotified(__('ai_connections.staff.bulk.skipped', ['count' => 1, 'names' => 'Kế Toán Bỏ Qua']));
});

it('đổi chế độ hàng loạt về Tắt thu hồi kết nối của từng người (như nút từng dòng)', function () {
    aioSwitches(true);
    $first = aioLawyer();
    $second = aioLawyer();
    McpOAuth::accessToken($this, $first);
    McpOAuth::accessToken($this, $second);

    aioPage($this->admin)
        ->selectTableRecords([$first, $second])
        ->callAction(TestAction::make('setAiAccessBulk')->table()->bulk(), data: ['ai_access' => AiAccessMode::Off->value])
        ->assertHasNoFormErrors();

    expect(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'ai_connections_revoked')->count())->toBe(2);
});

it('đổi chế độ hàng loạt tự hỏi lại cổng: người mất quyền giữa chừng nhận 404, không đổi ai', function () {
    $lawyer = aioLawyer(AiAccessMode::Off);

    $page = aioPage($this->admin)->selectTableRecords([$lawyer]);

    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    $page->call('mountAction', 'setAiAccessBulk', [], ['table' => true, 'bulk' => true])->assertNotFound();

    expect($lawyer->fresh()->ai_access)->toBe(AiAccessMode::Off);
});

it('SetAiAccessForMany: người gọi thiếu settings.manage bị từ chối ngay ở người đầu tiên, không ai bị đổi (lớp phòng thủ dưới nút hàng loạt)', function () {
    $first = aioLawyer(AiAccessMode::Off);
    $second = aioLawyer(AiAccessMode::Off);
    $manager = User::factory()->withRole(Role::Manager)->create();

    expect(fn () => app(SetAiAccessForMany::class)->handle([$first, $second], AiAccessMode::Read, $manager))
        ->toThrow(AuthorizationException::class);

    expect($first->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and($second->fresh()->ai_access)->toBe(AiAccessMode::Off)
        ->and(Activity::query()->where('event', 'ai_access_changed')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// 6. Hoạt động của trợ lý AI (đọc-chỉ, từ nhật ký M11).
// ---------------------------------------------------------------------------------------------

/** Nhật ký của một tuần thử: hai người, ba chức năng, đủ các kết cục. */
function aioActivityWorld(): array
{
    test()->travelTo(now()->setDateTime(2026, 10, 15, 11, 0));

    $alice = aioLawyer(attributes: ['name' => 'Luật Sư Alice']);
    $bob = aioLawyer(attributes: ['name' => 'Luật Sư Bob']);

    test()->travelTo(now()->setDateTime(2026, 10, 15, 9, 0));
    aioToolRow($alice, 'search');
    aioToolRow($alice, 'search');
    aioToolRow($alice, 'fetch', McpToolOutcome::RateLimited);
    test()->travelTo(now()->setDateTime(2026, 10, 14, 16, 0));
    aioToolRow($alice, 'get_matter', McpToolOutcome::Denied);
    aioToolRow($bob, 'search');
    aioToolRow($bob, 'search', McpToolOutcome::NotFound);
    // Ngoài bảy ngày, trong ba mươi ngày.
    test()->travelTo(now()->setDateTime(2026, 9, 30, 8, 0));
    aioToolRow($bob, 'list_deadlines');
    // Không phải lần gọi tool: không đếm.
    Audit::record('ai_policy_acknowledged', $bob, ['policy_version' => 'x'], $bob);

    test()->travelTo(now()->setDateTime(2026, 10, 15, 11, 0));

    return ['alice' => $alice, 'bob' => $bob];
}

it('hoạt động 7 ngày: tổng theo kết cục, theo ngày (đủ bảy ngày, có ngày trống), theo chức năng, theo người', function () {
    ['alice' => $alice, 'bob' => $bob] = aioActivityWorld();

    $html = aioPage($this->admin)->assertSee(__('ai_connections.activity.heading'))->html();

    expect(aioAttr($html, 'data-ai-activity-total', 'calls'))->toBe('6')
        ->and(aioAttr($html, 'data-ai-activity-outcome', 'ok'))->toBe('3')
        ->and(aioAttr($html, 'data-ai-activity-outcome', 'denied'))->toBe('1')
        ->and(aioAttr($html, 'data-ai-activity-outcome', 'rate_limited'))->toBe('1')
        ->and(aioAttr($html, 'data-ai-activity-outcome', 'not_found'))->toBe('1');

    preg_match_all('/data-ai-activity-day="(\d{4}-\d{2}-\d{2})"[^>]*data-calls="(\d+)"[^>]*data-refused="(\d+)"/', $html, $days, PREG_SET_ORDER);
    $byDay = collect($days)->mapWithKeys(fn (array $m): array => [$m[1] => [(int) $m[2], (int) $m[3]]])->all();

    expect(array_keys($byDay))->toBe(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'])
        ->and($byDay['2026-10-15'])->toBe([3, 1])
        ->and($byDay['2026-10-14'])->toBe([3, 1])
        ->and($byDay['2026-10-12'])->toBe([0, 0]);

    preg_match_all('/data-ai-activity-tool="([a-z_]+)"[^>]*data-calls="(\d+)"[^>]*data-refused="(\d+)"/', $html, $tools, PREG_SET_ORDER);
    $byTool = collect($tools)->mapWithKeys(fn (array $m): array => [$m[1] => [(int) $m[2], (int) $m[3]]])->all();

    expect($byTool)->toBe(['search' => [4, 0], 'fetch' => [1, 1], 'get_matter' => [1, 1]]);

    preg_match_all('/data-ai-activity-person="(\d+)"[^>]*data-calls="(\d+)"[^>]*data-refused="(\d+)"/', $html, $people, PREG_SET_ORDER);
    $byPerson = collect($people)->mapWithKeys(fn (array $m): array => [(int) $m[1] => [(int) $m[2], (int) $m[3]]])->all();

    expect($byPerson)->toBe([$alice->getKey() => [4, 2], $bob->getKey() => [2, 0]])
        ->and($html)->toContain('Luật Sư Alice')
        ->and($html)->toContain('Luật Sư Bob');
});

it('hoạt động 30 ngày gồm cả dòng cũ hơn bảy ngày; giá trị kỳ ngoài 7/30 bị bỏ qua', function () {
    aioActivityWorld();

    $page = aioPage($this->admin)->call('showActivity', 30);

    expect(aioAttr($page->html(), 'data-ai-activity-total', 'calls'))->toBe('7')
        ->and(aioAttr($page->html(), 'data-ai-activity-tool', 'list_deadlines'))->not->toBeNull();

    $page->call('showActivity', 365);

    expect($page->get('activityDays'))->toBe(30)
        ->and(aioAttr($page->html(), 'data-ai-activity-total', 'calls'))->toBe('7');
});

it('kỳ của khối hoạt động không đặt được từ trình duyệt ngoài nút 7/30 (thuộc tính bị khoá)', function () {
    expect(fn () => aioPage($this->admin)->set('activityDays', 365))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('nút chọn kỳ của khối hoạt động tự hỏi lại cổng, kể cả khi vòng đời Livewire bị bỏ qua', function () {
    $this->actingAs(User::factory()->withRole(Role::Manager)->create(), 'web');

    expect(fn () => (new AiConnections)->showActivity(30))->toThrow(NotFoundHttpException::class);
});

it('hoạt động: chưa có lượt gọi nào thì nói rõ, không bảng rỗng', function () {
    aioPage($this->admin)->assertSee(__('ai_connections.activity.empty', ['days' => 7]));
});

it('hoạt động: nháp do AI soạn đang chờ người duyệt được đếm; nháp đã dùng hay đã bỏ thì không; không lộ mã/tên vụ hạn chế', function () {
    $lawyer = aioLawyer();
    $restricted = Matter::factory()->restricted()->create(['title' => 'Vụ Hạn Chế Bí Mật']);
    StageLogDraft::factory()->create(['matter_id' => $restricted->getKey(), 'created_by' => $lawyer->getKey()]);
    StageLogDraft::factory()->create(['matter_id' => $restricted->getKey(), 'created_by' => $lawyer->getKey()]);
    StageLogDraft::factory()->discarded($this->admin, 'Không dùng nháp này')->create(['created_by' => $lawyer->getKey()]);
    $used = StageLogDraft::factory()->create(['created_by' => $lawyer->getKey()]);
    $log = StageLog::factory()->create(['matter_id' => $used->matter_id]);
    $used->forceFill(['used_stage_log_id' => $log->getKey()])->save();

    aioToolRow($lawyer, 'draft_progress_update', extra: ['returned_ids' => ['matter_'.$restricted->getKey()]]);

    $html = aioPage($this->admin)->html();

    expect(aioAttr($html, 'data-ai-activity-total', 'pending_drafts'))->toBe('2')
        ->and($html)->not->toContain('Vụ Hạn Chế Bí Mật')
        ->and($html)->not->toContain(e($restricted->code));
});

it('hoạt động: số truy vấn không tăng theo số người, số chức năng (không N+1)', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        aioPage($this->admin)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $alice = aioLawyer();
    aioToolRow($alice, 'search');
    // Lần đầu nạp thêm vài thứ một lần cho cả tiến trình (bộ nhớ quyền, cấu hình): đo từ lần thứ hai.
    $count();
    $few = $count();

    foreach (range(1, 5) as $i) {
        $person = aioLawyer();
        aioToolRow($person, 'tool_'.$i);
        aioToolRow($person, 'search', McpToolOutcome::Denied);
    }

    // Bảng nhân sự thêm dòng (một trang bảng), nhưng các truy vấn của nó đã theo lô; số truy vấn đứng yên.
    expect($count())->toBe($few);
});

// ---------------------------------------------------------------------------------------------
// Ma trận vai: chỉ quản trị viên thấy trang (và các khối mới của nó).
// ---------------------------------------------------------------------------------------------

it('mọi vai không có settings.manage (kể cả Nhân viên kinh doanh) nhận 404, không thấy thẻ trạng thái hay hoạt động', function (Role $role) {
    aioSwitches(true);
    $user = User::factory()->withRole($role)->create();

    $this->actingAs($user, 'web')
        ->get(AiConnections::getUrl(panel: 'admin'))
        ->assertNotFound()
        ->assertDontSee(__('ai_connections.activity.heading'))
        ->assertDontSee(AiOfficeStatus::ReadOnly->label());
})->with(collect(Role::cases())->reject(fn (Role $role): bool => $role === Role::Admin)->values()->all());

it('cặp dương: quản trị viên mở trang qua HTTP thấy thẻ trạng thái, danh sách bước và khối hoạt động', function () {
    aioSwitches(true);

    $this->actingAs($this->admin, 'web')
        ->get(AiConnections::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(AiOfficeStatus::ReadOnly->label())
        ->assertSee(__('ai_connections.setup.heading'))
        ->assertSee(__('ai_connections.activity.heading'));
});
