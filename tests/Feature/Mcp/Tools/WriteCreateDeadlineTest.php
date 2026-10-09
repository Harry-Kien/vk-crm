<?php

use App\Enums\AiAccessMode;
use App\Enums\CreatedVia;
use App\Enums\DeadlineSeverity;
use App\Enums\MatterAiAccess;
use App\Enums\MatterRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\McpConfirmation;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\ConfirmationToken;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DeadlinePresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 13 — tool `create_deadline` (hai bước, R6)
|--------------------------------------------------------------------------
| Lần một (không `confirmation_token`): kiểm đủ và kiểm quyền, KHÔNG ghi gì, trả bản xem trước kèm
| mã xác nhận. Lần hai, cùng tham số và mã: ghi đúng một mốc qua `AddMatterDeadline` — ép
| `is_published = false`, `created_via = mcp`, người tạo là người sở hữu token. Lần ba cùng mã: trả
| đúng mốc đã tạo. Mã gắn người, tool, băm tham số, hạn 10 phút.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->world->lead->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
    $this->arguments = [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
        'name' => '  Nộp bản tự khai  ',
        'due_date' => today()->addDays(5)->toDateString(),
        'severity' => 'critical',
    ];
});

function cdRows(): array
{
    return [
        'deadlines' => Deadline::query()->withoutGlobalScopes()->withTrashed()->count(),
        'mcp_confirmations' => McpConfirmation::query()->withoutGlobalScopes()->count(),
        'other_activity' => Activity::query()->where('event', '!=', 'mcp_tool_called')->count(),
    ];
}

function cdPreview(object $test, string $token, array $arguments): array
{
    return McpToolCall::structured($test, $token, 'create_deadline', $arguments);
}

it('lần một không ghi gì (đếm bảng trước và sau) và trả bản xem trước kèm confirmation_token hạn 10 phút', function () {
    $this->freezeTime();
    $before = cdRows();

    $out = cdPreview($this, $this->token, $this->arguments);

    expect(cdRows())->toBe($before)
        ->and($out['status'])->toBe('confirmation_required')
        ->and($out['confirmation_token'])->toBeString()->not->toBe('')
        ->and($out['expires_at'])->toBe(now()->addSeconds(ConfirmationToken::TTL_SECONDS)->toIso8601String())
        ->and($out['deadline'])->toBeNull()
        ->and($out['preview'])->toBe([
            'matter' => [
                'id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
                'code' => $this->world->matter->code,
                'title' => $this->world->matter->title,
                'url' => AdminUrls::matter($this->world->matter),
            ],
            'name' => 'Nộp bản tự khai',
            'due_date' => today()->addDays(5)->toDateString(),
            'severity' => 'critical',
            'severity_label' => DeadlineSeverity::Critical->label(),
            'responsible' => [
                'id' => McpIds::encode(McpIds::USER, $this->world->lead->id),
                'name' => 'Luật sư Phụ Trách',
                'position' => $this->world->lead->position?->value,
                'position_label' => $this->world->lead->position?->label(),
            ],
            'is_published' => false,
            'created_via' => 'mcp',
        ])
        ->and($out['message'])->toContain('Chưa ghi gì')
        ->and($out['message'])->toContain('Nộp bản tự khai');
});

it('lần hai với mã ghi đúng một mốc: ép chưa công bố, created_via = mcp, người tạo là người sở hữu token, nhãn chờ xác nhận', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $out = cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);

    $deadline = Deadline::query()->withoutGlobalScopes()->sole();

    expect($out['status'])->toBe('created')
        ->and($out['confirmation_token'])->toBeNull()
        ->and($out['expires_at'])->toBeNull()
        ->and($out['preview'])->toBeNull()
        ->and($out['deadline']['id'])->toBe(McpIds::encode(McpIds::DEADLINE, $deadline->id))
        ->and(array_keys($out['deadline']))->toBe(DeadlinePresenter::FIELDS)
        ->and($out['deadline']['awaiting_confirmation'])->toBeTrue()
        ->and($deadline->name)->toBe('Nộp bản tự khai')
        ->and($deadline->severity)->toBe(DeadlineSeverity::Critical)
        ->and($deadline->is_published)->toBeFalse()
        ->and($deadline->created_via)->toBe(CreatedVia::Mcp)
        ->and($deadline->created_by)->toBe($this->world->lead->id)
        ->and($deadline->responsible_user_id)->toBe($this->world->lead->id)
        ->and($deadline->confirmed_at)->toBeNull()
        ->and(McpConfirmation::query()->sole()->only(['user_id', 'tool', 'result_type', 'result_id']))->toBe([
            'user_id' => $this->world->lead->id,
            'tool' => 'create_deadline',
            'result_type' => 'deadline',
            'result_id' => $deadline->id,
        ]);

    $audit = Activity::query()->where('event', 'deadline_added')->sole();
    expect($audit->causer?->is($this->world->lead))->toBeTrue()
        ->and($audit->properties->get('created_via'))->toBe('mcp')
        ->and($audit->properties->get('is_published'))->toBeFalse();
});

it('lần ba cùng mã trả đúng mốc đã tạo, không tạo thêm (idempotentHint trung thực)', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $second = cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);
    $third = cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);

    expect($third['deadline'])->toBe($second['deadline'])
        ->and($third['status'])->toBe('created')
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(McpConfirmation::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'deadline_added')->count())->toBe(1);
});

it('mã của người A dùng dưới token OAuth của người B: từ chối, không ghi gì', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $colleague = User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::ReadWrite)->create();
    $this->world->matter->addTeamMember($colleague, MatterRole::Associate);
    $colleagueToken = McpOAuth::accessToken($this, $colleague);

    $before = cdRows();

    expect(McpToolCall::error($this, $colleagueToken, 'create_deadline', [...$this->arguments, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(cdRows())->toBe($before);

    // Cặp dương: cùng mã dưới đúng người thì ghi.
    cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);
    expect(Deadline::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('mã với tham số đã sửa (hạn khác, tên khác, mức khác, vụ khác): từ chối, không ghi gì', function (Closure $change) {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $changed = $change($this->arguments, $this->world);
    $before = cdRows();

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$changed, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(cdRows())->toBe($before);
})->with([
    'hạn khác' => [fn (array $a) => [...$a, 'due_date' => today()->addDays(6)->toDateString()]],
    'tên khác' => [fn (array $a) => [...$a, 'name' => 'Nộp bản tự khai lần hai']],
    'mức khác' => [fn (array $a) => [...$a, 'severity' => 'normal']],
    'thêm người phụ trách' => [fn (array $a, McpReadWorld $w) => [...$a, 'responsible_id' => McpIds::encode(McpIds::USER, $w->assistant->id)]],
    'vụ khác' => [function (array $a, McpReadWorld $w) {
        $other = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $w->lead->id]);

        return [...$a, 'matter_id' => McpIds::encode(McpIds::MATTER, $other->id)];
    }],
]);

it('khoảng trắng hai đầu tên và mức mặc định không làm lệch băm: bản xem trước và lần ghi đọc cùng tham số đã chuẩn hoá', function () {
    $arguments = [...$this->arguments, 'name' => 'Nộp bản tự khai'];
    unset($arguments['severity']);

    $token = cdPreview($this, $this->token, $arguments)['confirmation_token'];

    $out = cdPreview($this, $this->token, [...$arguments, 'name' => ' Nộp bản tự khai ', 'severity' => 'normal', 'confirmation_token' => $token]);

    expect($out['status'])->toBe('created');
});

it('mã quá 10 phút: từ chối; ngay trước hạn: nhận', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $this->travel(ConfirmationToken::TTL_SECONDS + 1)->seconds();

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->travelBack();
    $fresh = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];
    $this->travel(ConfirmationToken::TTL_SECONDS - 5)->seconds();

    expect(cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $fresh])['status'])->toBe('created');
});

it('mã bị sửa một ký tự (ở phần dữ liệu hay phần chữ ký, kể cả ký tự cuối): từ chối', function (Closure $tamper) {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];
    $tampered = $tamper($token);

    expect($tampered)->not->toBe($token)
        ->and(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $tampered]))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'ký tự đầu' => [fn (string $t) => ($t[0] === 'e' ? 'f' : 'e').substr($t, 1)],
    'ký tự cuối' => [fn (string $t) => substr($t, 0, -1).(str_ends_with($t, 'A') ? 'B' : 'A')],
    'giữa phần chữ ký' => [function (string $t) {
        $dot = strpos($t, '.');
        $i = $dot + 5;

        return substr($t, 0, $i).($t[$i] === 'x' ? 'y' : 'x').substr($t, $i + 1);
    }],
    'không có dấu chấm' => [fn (string $t) => str_replace('.', '', $t)],
    'rỗng chữ ký' => [fn (string $t) => strstr($t, '.', true).'.'],
]);

it('mã của tool khác (log_communication) không dùng được cho create_deadline, kể cả khi băm tham số trùng', function () {
    // Đúng bộ tham số đã chuẩn hoá mà CreateAiDeadline ký: chỉ tên tool khác nhau, nên chỉ claim `t` từ chối.
    $signed = [
        'matter_id' => $this->world->matter->id,
        'name' => 'Nộp bản tự khai',
        'due_date' => $this->arguments['due_date'],
        'severity' => 'critical',
        'responsible_id' => null,
    ];
    $foreign = ConfirmationToken::issue($this->world->lead, 'log_communication', $signed)['token'];

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $foreign]))
        ->toBe(__('mcp.tool_errors.invalid_confirmation'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);

    // Cặp dương: cùng bộ tham số, ký cho đúng tool, thì ghi.
    $own = ConfirmationToken::issue($this->world->lead, 'create_deadline', $signed)['token'];
    cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $own]);

    expect(Deadline::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lớp thứ hai: dòng mcp_confirmations của jti này thuộc người khác hay tool khác thì "Không tìm thấy", không ghi, không trả bản ghi đó', function (Closure $owner) {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];
    $jti = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true)['j'];

    $other = User::factory()->withRole(Role::Lawyer)->create();
    $foreignDeadline = Deadline::factory()->for($this->world->matter)->create();
    McpConfirmation::query()->create([
        'jti' => $jti,
        'result_type' => $foreignDeadline->getMorphClass(),
        'result_id' => $foreignDeadline->id,
        ...$owner($this->world->lead, $other),
    ]);

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.not_found'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(1);
})->with([
    'người khác' => [fn (User $lead, User $other) => ['user_id' => $other->id, 'tool' => 'create_deadline']],
    'tool khác' => [fn (User $lead, User $other) => ['user_id' => $lead->id, 'tool' => 'log_communication']],
]);

it('lớp thứ hai, cặp dương: dòng mcp_confirmations đúng người, đúng tool thì trả bản ghi của nó, không tạo thêm', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];
    $jti = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true)['j'];

    $existing = Deadline::factory()->for($this->world->matter)->create();
    McpConfirmation::query()->create([
        'jti' => $jti,
        'user_id' => $this->world->lead->id,
        'tool' => 'create_deadline',
        'result_type' => $existing->getMorphClass(),
        'result_id' => $existing->id,
    ]);

    $result = cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);

    expect($result['deadline']['id'])->toBe(McpIds::encode(McpIds::DEADLINE, $existing->id))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lỗi nghiệp vụ trả isError tiếng Việt, liệt kê giá trị hợp lệ: hạn trong quá khứ, severity sai, ngày sai dạng, tên rỗng', function (array $override, string $expected) {
    $message = McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, ...$override]);

    expect($message)->toContain($expected)
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'hạn hôm qua' => [['due_date' => '2000-01-01'], 'trở đi'],
    'severity sai' => [['severity' => 'urgent'], 'normal, critical'],
    'ngày sai dạng' => [['due_date' => '07/10/2026'], 'YYYY-MM-DD'],
    'tên toàn khoảng trắng' => [['name' => '   '], 'Hãy ghi mốc này là việc gì'],
]);

it('hạn hôm nay được nhận (cận của luật "không trong quá khứ")', function () {
    $token = cdPreview($this, $this->token, [...$this->arguments, 'due_date' => today()->toDateString()])['confirmation_token'];

    expect($token)->toBeString();
});

it('người phụ trách ngoài đội ngũ hay id user không tồn tại: cùng câu từ chối của web, không ghi gì', function (Closure $responsible) {
    $message = McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'responsible_id' => $responsible($this->world)]);

    expect($message)->toBe(__('deadlines.validation.responsible_cannot_open'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'ngoài đội ngũ' => [fn (McpReadWorld $w) => McpIds::encode(McpIds::USER, $w->outsider->id)],
    'không tồn tại' => [fn () => 'user_999999'],
    'sai dạng' => [fn () => 'lead'],
]);

it('chọn người phụ trách trong đội ngũ: bản xem trước và mốc mang đúng người đó', function () {
    $arguments = [...$this->arguments, 'responsible_id' => McpIds::encode(McpIds::USER, $this->world->assistant->id)];
    $preview = cdPreview($this, $this->token, $arguments);

    expect($preview['preview']['responsible']['id'])->toBe(McpIds::encode(McpIds::USER, $this->world->assistant->id));

    cdPreview($this, $this->token, [...$arguments, 'confirmation_token' => $preview['confirmation_token']]);

    expect(Deadline::query()->withoutGlobalScopes()->sole()->responsible_user_id)->toBe($this->world->assistant->id);
});

it('thiếu quyền web tương ứng (MatterPolicy::update, cùng cổng với tab Mốc thời hạn): từ chối ở lần một, không cấp mã', function () {
    $viewer = User::factory()->withAiAccess(AiAccessMode::ReadWrite)->create();
    $viewer->givePermissionTo(Permission::MatterView->value);
    $this->world->matter->addTeamMember($viewer, MatterRole::Observer);
    $token = McpOAuth::accessToken($this, $viewer);

    $response = McpToolCall::call($this, $token, 'create_deadline', $this->arguments);

    $response->assertOk()->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe(__('mcp.tool_errors.forbidden'))
        ->and($response->getContent())->not->toContain('confirmation_token')
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);

    $row = Activity::query()->where('event', 'mcp_tool_called')->latest('id')->first();
    expect($row->properties['outcome'])->toBe('denied');
});

it('quyền bị rút giữa hai bước: lần hai với mã còn hạn vẫn bị từ chối (kiểm lại quyền), không ghi gì', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];

    $this->world->matter->forceFill(['ai_access' => MatterAiAccess::Denied])->save();

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.not_found'))
        ->and(Deadline::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('mốc tạo qua AI rồi bị xoá trên web: gọi lại cùng mã trả "Không tìm thấy", không tạo lại', function () {
    $token = cdPreview($this, $this->token, $this->arguments)['confirmation_token'];
    cdPreview($this, $this->token, [...$this->arguments, 'confirmation_token' => $token]);

    Deadline::query()->withoutGlobalScopes()->sole()->delete();

    expect(McpToolCall::error($this, $this->token, 'create_deadline', [...$this->arguments, 'confirmation_token' => $token]))
        ->toBe(__('mcp.tool_errors.not_found'))
        ->and(Deadline::query()->withoutGlobalScopes()->withTrashed()->count())->toBe(1);
});
