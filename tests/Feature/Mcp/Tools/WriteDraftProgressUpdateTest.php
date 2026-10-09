<?php

use App\Enums\AiAccessMode;
use App\Enums\MatterAiAccess;
use App\Enums\McpDraftState;
use App\Models\Matter;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 13 — tool `draft_progress_update` (nháp, một bước, R5/R6)
|--------------------------------------------------------------------------
| Ghi một NHÁP dòng cập nhật không đổi giai đoạn vào `stage_log_drafts`, không bao giờ vào
| `stage_logs`. Cổng: `MatterPolicy::transitionStage` — cùng cổng với nút "Thêm cập nhật" (trợ lý
| không có). Bắt buộc `idempotency_key` (8–64 ký tự, theo người, không phân biệt hoa thường): gọi lại
| cùng khoá và cùng nội dung trả nháp đã có; cùng khoá cho một nháp khác là lỗi, không phải nháp cũ.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();

    foreach ([$this->world->lead, $this->world->assistant] as $user) {
        $user->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    }

    $this->token = McpOAuth::accessToken($this, $this->world->lead);
    $this->arguments = [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
        'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
        'next_step' => 'Chờ toà thụ lý đơn.',
        'client_action' => 'Chuẩn bị bản sao CCCD công chứng.',
        'expected_next_update_at' => today()->addDays(10)->toDateString(),
        'internal_note' => 'Thẩm phán dự kiến: chưa rõ — NOI-BO-DPU-7731.',
        'idempotency_key' => 'dpu-2026-10-07-a',
    ];
});

function dpuCall(object $test, string $token, array $arguments): array
{
    return McpToolCall::structured($test, $token, 'draft_progress_update', $arguments);
}

it('tạo một nháp (không phải dòng tiến độ), người soạn là người sở hữu token, giai đoạn không đổi; ghi chú nội bộ được ghi nhưng không đọc ra', function () {
    $stage = $this->world->matter->stage;

    $response = McpToolCall::call($this, $this->token, 'draft_progress_update', $this->arguments);
    $out = dpuCall($this, $this->token, [...$this->arguments]);

    $draft = StageLogDraft::query()->withoutGlobalScopes()->sole();

    expect($out['status'])->toBe('existing')
        ->and($response->json('result.structuredContent.status'))->toBe('created')
        ->and($out['draft'])->toBe([
            'id' => McpIds::encode(McpIds::PROGRESS_DRAFT, $draft->id),
            'matter' => [
                'id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
                'code' => $this->world->matter->code,
                'title' => $this->world->matter->title,
                'url' => AdminUrls::matter($this->world->matter),
            ],
            'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
            'next_step' => 'Chờ toà thụ lý đơn.',
            'client_action' => 'Chuẩn bị bản sao CCCD công chứng.',
            'expected_next_update_at' => today()->addDays(10)->toDateString(),
            'has_internal_note' => true,
            'state' => 'pending',
            'state_label' => McpDraftState::Pending->label(),
            'created_at' => $draft->created_at->toIso8601String(),
            'url' => AdminUrls::stageLogDraft($draft),
        ])
        ->and($draft->created_by)->toBe($this->world->lead->id)
        ->and($draft->matter_id)->toBe($this->world->matter->id)
        ->and($draft->internal_note)->toBe('Thẩm phán dự kiến: chưa rõ — NOI-BO-DPU-7731.')
        ->and($draft->idempotency_key)->toBe('dpu-2026-10-07-a')
        ->and(StageLog::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->world->matter->refresh()->stage)->toBe($stage)
        ->and($response->getContent())->not->toContain('NOI-BO-DPU-7731')
        ->and($out['message'])->toContain('Chưa có gì tới khách');
});

it('gọi lại cùng idempotency_key và cùng nội dung trả CÙNG nháp, không tạo thêm', function () {
    $first = dpuCall($this, $this->token, $this->arguments);
    $second = dpuCall($this, $this->token, $this->arguments);

    expect($second['draft'])->toBe($first['draft'])
        ->and($first['status'])->toBe('created')
        ->and($second['status'])->toBe('existing')
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('Task 7 m4: idempotency_key không phân biệt hoa thường — giống nhau trên SQLite và MariaDB', function () {
    $first = dpuCall($this, $this->token, [...$this->arguments, 'idempotency_key' => 'DPU-ABC-12345']);
    $same = dpuCall($this, $this->token, [...$this->arguments, 'idempotency_key' => 'dpu-abc-12345']);

    expect($same['draft']['id'])->toBe($first['draft']['id'])
        ->and(StageLogDraft::query()->withoutGlobalScopes()->sole()->idempotency_key)->toBe('dpu-abc-12345');

    // Cùng khoá (khác hoa thường) cho một nội dung KHÁC: lỗi, không nháp thứ hai, không nháp cũ.
    $message = McpToolCall::error($this, $this->token, 'draft_progress_update', [
        ...$this->arguments, 'idempotency_key' => 'Dpu-Abc-12345', 'public_content' => 'Một cập nhật khác hẳn, đủ dài để công bố cho khách.',
    ]);

    expect($message)->toBe(__('mcp.tool_errors.idempotency_conflict'))
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('Task 7 m5: cùng khoá cho vụ KHÁC — lỗi khoá đã dùng, không trả nội dung nháp của vụ cũ, kể cả khi vụ cũ đã rời tập MCP', function () {
    dpuCall($this, $this->token, $this->arguments);

    $other = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);
    $this->world->matter->forceFill(['ai_access' => MatterAiAccess::Denied])->save();

    $response = McpToolCall::call($this, $this->token, 'draft_progress_update', [
        ...$this->arguments, 'matter_id' => McpIds::encode(McpIds::MATTER, $other->id),
    ]);

    $response->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('mcp.tool_errors.idempotency_conflict'));

    expect($response->getContent())->not->toContain('Ba Đình')
        ->and($response->getContent())->not->toContain($this->world->matter->code)
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('gọi lại cùng khoá khi vụ của nháp đã rời tập MCP: "Không tìm thấy", không nội dung nào', function () {
    dpuCall($this, $this->token, $this->arguments);

    $this->world->matter->forceFill(['ai_access' => MatterAiAccess::Denied])->save();

    expect(McpToolCall::error($this, $this->token, 'draft_progress_update', $this->arguments))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('khoá của người khác không đụng nhau: hai người cùng khoá thì hai nháp', function () {
    dpuCall($this, $this->token, $this->arguments);

    $admin = $this->world->admin;
    $admin->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    dpuCall($this, McpOAuth::accessToken($this, $admin), $this->arguments);

    expect(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('nháp đã dùng trên web: gọi lại cùng khoá trả nháp đó với trạng thái "đã dùng", không nháp mới', function () {
    $first = dpuCall($this, $this->token, $this->arguments);
    $log = StageLog::factory()->create(['matter_id' => $this->world->matter->id]);
    StageLogDraft::query()->withoutGlobalScopes()->sole()->forceFill(['used_stage_log_id' => $log->id])->save();

    $again = dpuCall($this, $this->token, $this->arguments);

    expect($again['draft']['id'])->toBe($first['draft']['id'])
        ->and($again['draft']['state'])->toBe('used')
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('idempotency_key bắt buộc, 8–64 ký tự, chỉ chữ không dấu, số và . _ : -', function (mixed $key) {
    $arguments = $this->arguments;

    if ($key === null) {
        unset($arguments['idempotency_key']);
    } else {
        $arguments['idempotency_key'] = $key;
    }

    expect(McpToolCall::error($this, $this->token, 'draft_progress_update', $arguments))->toContain('idempotency_key')
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'thiếu' => [null],
    '7 ký tự' => ['abcdefg'],
    '65 ký tự' => [str_repeat('a', 65)],
    'có dấu' => ['cập-nhật-01'],
    'khoảng trắng' => ['abc def 123'],
]);

it('idempotency_key đúng 8 và đúng 64 ký tự được nhận (cận)', function (string $key) {
    expect(dpuCall($this, $this->token, [...$this->arguments, 'idempotency_key' => $key])['status'])->toBe('created');
})->with(['8 ký tự' => ['abcd-123'], '64 ký tự' => [str_repeat('k', 64)]]);

it('trợ lý (không có matter.transitionStage, SPEC §5) bị từ chối — cùng cổng với nút "Thêm cập nhật"', function () {
    $response = McpToolCall::call($this, McpOAuth::accessToken($this, $this->world->assistant), 'draft_progress_update', $this->arguments);

    $response->assertOk()->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('mcp.tool_errors.forbidden'));

    expect(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(0);

    $row = Activity::query()->where('event', 'mcp_tool_called')->latest('id')->first();
    expect($row->properties['outcome'])->toBe('denied');
});

it('nội dung công khai rỗng, ngày cập nhật kế tiếp trong quá khứ: isError tiếng Việt, không ghi gì', function (array $override, string $expected) {
    expect(McpToolCall::error($this, $this->token, 'draft_progress_update', [...$this->arguments, ...$override]))->toContain($expected)
        ->and(StageLogDraft::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'nội dung rỗng' => [['public_content' => '  '], 'public_content'],
    'ngày đã qua' => [['expected_next_update_at' => '2000-01-01'], 'trở đi'],
    'ngày sai dạng' => [['expected_next_update_at' => '10/10/2026'], 'YYYY-MM-DD'],
]);

it('không nhận to_stage hay publish (không chuyển giai đoạn, không công bố)', function (string $param) {
    expect(McpToolCall::error($this, $this->token, 'draft_progress_update', [...$this->arguments, $param => 'x']))
        ->toBe(__('mcp.tool_errors.unknown_arguments', ['names' => $param]));
})->with(['to_stage', 'publish']);
