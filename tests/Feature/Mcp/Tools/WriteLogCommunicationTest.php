<?php

use App\Enums\AiAccessMode;
use App\Enums\CommunicationType;
use App\Enums\CreatedVia;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CommunicationLog;
use App\Models\McpConfirmation;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 13 — tool `log_communication` (hai bước, R6)
|--------------------------------------------------------------------------
| Ghi qua ĐÚNG Action của M7 Task 8 (`LogCommunication`) và cổng của nó
| (`CommunicationLogPolicy::create($user, $matter)`). Server ép `is_visible_to_client = false` và
| `created_via = mcp`; tool không nhận hai tham số đó. Cùng mã xác nhận hai bước với `create_deadline`.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->world->lead->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
    $this->occurredAt = now()->subHours(2)->startOfMinute();
    $this->arguments = [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
        'type' => 'meeting',
        'occurred_at' => $this->occurredAt->toIso8601String(),
        'duration_minutes' => 45,
        'counterpart' => 'Ông Trần Văn Khách',
        'summary' => 'Họp với khách về phương án hoà giải.',
    ];
});

function lcRows(): array
{
    return [
        'communication_logs' => CommunicationLog::query()->withoutGlobalScopes()->withTrashed()->count(),
        'mcp_confirmations' => McpConfirmation::query()->withoutGlobalScopes()->count(),
        'other_activity' => Activity::query()->where('event', '!=', 'mcp_tool_called')->count(),
    ];
}

function lcCall(object $test, string $token, array $arguments): array
{
    return McpToolCall::structured($test, $token, 'log_communication', $arguments);
}

it('lần một không ghi gì và trả bản xem trước kèm confirmation_token', function () {
    $before = lcRows();

    $out = lcCall($this, $this->token, $this->arguments);

    expect(lcRows())->toBe($before)
        ->and($out['status'])->toBe('confirmation_required')
        ->and($out['confirmation_token'])->toBeString()
        ->and($out['communication'])->toBeNull()
        ->and($out['preview'])->toBe([
            'matter' => [
                'id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
                'code' => $this->world->matter->code,
                'title' => $this->world->matter->title,
                'url' => AdminUrls::matter($this->world->matter),
            ],
            'type' => 'meeting',
            'type_label' => CommunicationType::Meeting->label(),
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'duration_minutes' => 45,
            'counterpart' => 'Ông Trần Văn Khách',
            'summary' => 'Họp với khách về phương án hoà giải.',
            'is_visible_to_client' => false,
            'created_via' => 'mcp',
        ])
        ->and($out['message'])->toContain('Chưa ghi gì');
});

it('bỏ trống người liên lạc: bản xem trước nói trước tên khách của vụ (mặc định của Action M7)', function () {
    $arguments = $this->arguments;
    unset($arguments['counterpart']);

    expect(lcCall($this, $this->token, $arguments)['preview']['counterpart'])
        ->toBe($this->world->matter->client->name);
});

it('lần hai ghi đúng một dòng qua LogCommunication: ép không hiện cho khách, created_via = mcp, người ghi là người sở hữu token', function () {
    $token = lcCall($this, $this->token, $this->arguments)['confirmation_token'];

    $out = lcCall($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);
    $log = CommunicationLog::query()->withoutGlobalScopes()->sole();

    expect($out['status'])->toBe('created')
        ->and($out['communication']['id'])->toBe(McpIds::encode(McpIds::COMMUNICATION, $log->id))
        ->and($out['communication']['url'])->toBe(AdminUrls::communicationLog($log))
        ->and($out['communication']['created_via'])->toBe('mcp')
        ->and($out['communication']['is_visible_to_client'])->toBeFalse()
        ->and($log->type)->toBe(CommunicationType::Meeting)
        ->and($log->occurred_at->toIso8601String())->toBe($this->occurredAt->toIso8601String())
        ->and($log->duration_minutes)->toBe(45)
        ->and($log->counterpart)->toBe('Ông Trần Văn Khách')
        ->and($log->is_visible_to_client)->toBeFalse()
        ->and($log->created_via)->toBe(CreatedVia::Mcp)
        ->and($log->created_by)->toBe($this->world->lead->id)
        ->and(McpConfirmation::query()->sole()->result_type)->toBe('communication_log');

    $audit = Activity::query()->where('event', 'communication_logged')->sole();

    expect($audit->causer?->is($this->world->lead))->toBeTrue()
        ->and($audit->properties->get('created_via'))->toBe('mcp');
});

it('lần ba cùng mã trả đúng dòng đã ghi, không ghi thêm', function () {
    $token = lcCall($this, $this->token, $this->arguments)['confirmation_token'];

    $second = lcCall($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);
    $third = lcCall($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);

    expect($third['communication'])->toBe($second['communication'])
        ->and(CommunicationLog::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'communication_logged')->count())->toBe(1);
});

it('mã của người khác, tham số đã sửa, mã quá hạn, mã bị sửa: từ chối, không ghi gì', function (string $case) {
    $token = lcCall($this, $this->token, $this->arguments)['confirmation_token'];
    $bearer = $this->token;
    $arguments = [...$this->arguments, 'confirmation_token' => $token];

    match ($case) {
        'người khác' => (function () use (&$bearer) {
            $colleague = User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::ReadWrite)->create();
            $this->world->matter->addTeamMember($colleague, MatterRole::Associate);
            $bearer = McpOAuth::accessToken($this, $colleague);
        })->call($this),
        'tóm tắt khác' => $arguments['summary'] = 'Họp với khách về phương án khởi kiện.',
        'thời lượng khác' => $arguments['duration_minutes'] = 46,
        'kênh khác' => $arguments['type'] = 'call_out',
        'quá hạn' => $this->travel(ConfirmationToken::TTL_SECONDS + 1)->seconds(),
        'sửa một ký tự' => $arguments['confirmation_token'] = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A'),
    };

    $before = lcRows();

    expect(McpToolCall::error($this, $bearer, 'log_communication', $arguments))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(lcRows())->toBe($before);
})->with(['người khác', 'tóm tắt khác', 'thời lượng khác', 'kênh khác', 'quá hạn', 'sửa một ký tự']);

it('lỗi nghiệp vụ trả isError tiếng Việt: kênh sai liệt kê kênh hợp lệ, thời điểm ở tương lai, thời lượng âm, tóm tắt rỗng', function (array $override, string $expected) {
    $message = McpToolCall::error($this, $this->token, 'log_communication', [...$this->arguments, ...$override]);
    $expected = Lang::has($expected) ? __($expected) : $expected;

    expect($message)->toContain($expected)
        ->and(CommunicationLog::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'kênh sai' => [['type' => 'zalo'], 'call_in, call_out, meeting, email, letter, court_visit'],
    'tương lai' => [['occurred_at' => '2099-01-01T09:00:00+07:00'], 'communications.validation.occurred_at_future'],
    'không đọc được' => [['occurred_at' => 'hôm qua'], 'ISO 8601'],
    'thời lượng âm' => [['duration_minutes' => -5], 'duration_minutes'],
    'tóm tắt rỗng' => [['summary' => '   '], 'communications.validation.summary_required'],
]);

it('thiếu quyền web tương ứng (CommunicationLogPolicy::create với vụ, cùng cổng tab Liên lạc): từ chối ở lần một, không cấp mã', function () {
    $viewer = User::factory()->withAiAccess(AiAccessMode::ReadWrite)->create();
    $viewer->givePermissionTo(Permission::MatterView->value);
    $this->world->matter->addTeamMember($viewer, MatterRole::Observer);

    $response = McpToolCall::call($this, McpOAuth::accessToken($this, $viewer), 'log_communication', $this->arguments);

    $response->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('mcp.tool_errors.forbidden'));
    expect($response->getContent())->not->toContain('confirmation_token')
        ->and(CommunicationLog::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('không nhận is_visible_to_client hay created_via từ tham số', function () {
    expect(McpToolCall::error($this, $this->token, 'log_communication', [...$this->arguments, 'is_visible_to_client' => true]))
        ->toBe(__('mcp.tool_errors.unknown_arguments', ['names' => 'is_visible_to_client']));
});

it('occurred_at gửi theo giờ UTC (Z) được quy về múi giờ của văn phòng trước khi ghi — không lệch 7 giờ', function () {
    $arguments = [...$this->arguments, 'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s\Z')];

    $token = lcCall($this, $this->token, $arguments)['confirmation_token'];
    lcCall($this, $this->token, [...$arguments, 'confirmation_token' => $token]);

    expect(CommunicationLog::query()->withoutGlobalScopes()->sole()->occurred_at->toIso8601String())
        ->toBe($this->occurredAt->setTimezone(config('app.timezone'))->toIso8601String());
});
