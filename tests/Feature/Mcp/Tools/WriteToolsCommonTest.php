<?php

use App\Enums\AiAccessMode;
use App\Enums\ClientRequestStatus;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Events\ClientDocumentSubmitted;
use App\Events\ClientRequestAnswered;
use App\Events\ClientRequestOpened;
use App\Events\DocumentPublished;
use App\Events\MatterStageChanged;
use App\Events\StageLogPublished;
use App\Models\ClientRequest;
use App\Models\ClientRequestReply;
use App\Models\ClientRequestReplyDraft;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\McpConfirmation;
use App\Models\OutboundMessage;
use App\Models\StageLog;
use App\Models\StageLogDraft;
use App\Models\User;
use App\Support\Mcp\McpAccess;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 13 — bốn tool ghi: luật chung (R5, R6, R13, R14, Review Focus 2 và 5)
|--------------------------------------------------------------------------
| Mọi lời gọi qua HTTP thật (`McpToolCall`, token Passport thật). Luật riêng của từng tool ở bốn tệp
| `Write<Tool>Test.php`; ở đây là những gì CẢ BỐN tool phải giữ giống nhau:
|  - danh sách tool và annotation (R13, R14);
|  - người `read`, công tắc ghi tắt: bị từ chối bằng câu tiếng Việt, không thử lại (R13, [DC:191]);
|  - vụ ngoài tập R3: "Không tìm thấy" giống hệt id không tồn tại (Review Focus 2);
|  - không gì tới khách, ở cả nhánh thành công lẫn nhánh lỗi (R5, Review Focus 5).
*/

const WRITE_TOOLS = ['draft_progress_update', 'draft_request_reply', 'create_deadline', 'log_communication'];

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();

    foreach ([$this->world->lead, $this->world->assistant, $this->world->admin] as $user) {
        $user->forceFill(['ai_access' => AiAccessMode::ReadWrite])->save();
    }

    $this->request = wtRequestOn($this->world->matter);
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

function wtRequestOn(Matter $matter, ClientRequestStatus $status = ClientRequestStatus::InProgress): ClientRequest
{
    return ClientRequest::factory()->create([
        'matter_id' => $matter->id,
        'status' => $status,
        'subject' => 'Hỏi về lịch hoà giải',
        'content' => 'Khi nào văn phòng đi hoà giải?',
    ]);
}

/**
 * Tham số HỢP LỆ của từng tool trên vụ (hay yêu cầu) đưa vào. Tool hai bước gọi lần một (không
 * `confirmation_token`) với đúng các tham số này.
 *
 * @return array<string, mixed>
 */
function wtArguments(string $tool, Matter $matter, ?ClientRequest $request = null): array
{
    $matterId = McpIds::encode(McpIds::MATTER, $matter->id);

    return match ($tool) {
        'draft_progress_update' => [
            'matter_id' => $matterId,
            'public_content' => 'Văn phòng đã nộp đơn khởi kiện tới toà án nhân dân quận Ba Đình hôm nay.',
            'idempotency_key' => 'wt-progress-0001',
        ],
        'draft_request_reply' => [
            'request_id' => McpIds::encode(McpIds::REQUEST, ($request ?? wtRequestOn($matter))->id),
            'content' => 'Văn phòng sẽ đi hoà giải vào thứ Năm tuần sau.',
            'idempotency_key' => 'wt-reply-0001',
        ],
        'create_deadline' => [
            'matter_id' => $matterId,
            'name' => 'Nộp bản tự khai',
            'due_date' => today()->addDays(5)->toDateString(),
        ],
        'log_communication' => [
            'matter_id' => $matterId,
            'type' => 'call_in',
            'occurred_at' => now()->subHour()->toIso8601String(),
            'summary' => 'Khách gọi hỏi lịch hoà giải.',
        ],
    };
}

/** Số dòng của mọi bảng mà một tool ghi có thể chạm tới, trừ nhật ký `mcp_tool_called`. */
function wtWrittenRows(): array
{
    return [
        'stage_log_drafts' => StageLogDraft::query()->withoutGlobalScopes()->count(),
        'client_request_reply_drafts' => ClientRequestReplyDraft::query()->withoutGlobalScopes()->count(),
        'deadlines' => Deadline::query()->withoutGlobalScopes()->withTrashed()->count(),
        'communication_logs' => CommunicationLog::query()->withoutGlobalScopes()->withTrashed()->count(),
        'mcp_confirmations' => McpConfirmation::query()->withoutGlobalScopes()->count(),
        'stage_logs' => StageLog::query()->withoutGlobalScopes()->count(),
        'client_request_replies' => ClientRequestReply::query()->withoutGlobalScopes()->count(),
        'other_activity' => Activity::query()->where('event', '!=', 'mcp_tool_called')->count(),
    ];
}

/** Lần gọi đầu của tool (lần duy nhất của tool nháp; lần xem trước của tool hai bước) thành công. */
function wtCallOk(object $test, string $token, string $tool, array $arguments): array
{
    return McpToolCall::structured($test, $token, $tool, $arguments);
}

it('tools/list của người read_write khi công tắc ghi bật: mười một tool đọc rồi bốn tool ghi, đúng thứ tự cố định', function () {
    $names = collect(McpToolCall::listTools($this, $this->token)->assertOk()->json('result.tools'))->pluck('name')->all();

    expect($names)->toBe([
        'whoami', 'search', 'fetch', 'search_matters', 'get_matter',
        'list_matter_updates', 'list_deadlines', 'get_checklist', 'list_documents', 'list_client_requests', 'get_client_request',
        ...WRITE_TOOLS,
    ]);
});

it('bốn tool ghi: annotation trung thực (không đọc, không phá, gọi lại an toàn, không ra ngoài), mô tả tiếng Việt "Dùng khi… / Không dùng để…", inputSchema và outputSchema đóng', function () {
    $tools = collect(McpToolCall::listTools($this, $this->token)->json('result.tools'))->keyBy('name');

    foreach (WRITE_TOOLS as $name) {
        $tool = $tools[$name];

        expect($tool['annotations'])->toBe([
            'title' => $tool['title'],
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $name)
            ->and(Lang::has("mcp.tools.{$name}.description"))->toBeTrue()
            ->and($tool['description'])->toStartWith('Dùng khi')
            ->and($tool['description'])->toContain('Không dùng để')
            ->and($tool['inputSchema']['additionalProperties'])->toBeFalse($name)
            ->and($tool['outputSchema']['additionalProperties'])->toBeFalse($name);

        foreach ($tool['inputSchema']['properties'] as $param => $definition) {
            expect($definition['description'] ?? '')->not->toBe('', "{$name}.{$param}");

            if ($definition['type'] === 'string') {
                expect($definition['maxLength'] ?? null)->toBeInt("{$name}.{$param}");
            }
        }
    }
});

it('R5 không tool nào nhận người nhận, công bố, đổi giai đoạn hay đổi trạng thái luồng: không có tham số như vậy', function () {
    $tools = collect(McpToolCall::listTools($this, $this->token)->json('result.tools'))->keyBy('name');

    foreach (WRITE_TOOLS as $name) {
        expect(array_keys($tools[$name]['inputSchema']['properties']))
            ->not->toContain('to_stage')
            ->not->toContain('publish')
            ->not->toContain('is_published')
            ->not->toContain('is_visible_to_client')
            ->not->toContain('recipient')
            ->not->toContain('email')
            ->not->toContain('status')
            ->not->toContain('created_by')
            ->not->toContain('created_via');
    }
});

it('người read không thấy tool ghi trong tools/list; gọi thẳng thì bị từ chối bằng câu tiếng Việt nói không thử lại, HTTP 200, không ghi gì', function (string $tool) {
    $reader = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();
    $this->world->matter->addTeamMember($reader, MatterRole::Associate);
    $token = McpOAuth::accessToken($this, $reader);

    $names = collect(McpToolCall::listTools($this, $token)->json('result.tools'))->pluck('name')->all();
    expect($names)->not->toContain($tool);

    $before = wtWrittenRows();
    $message = McpToolCall::error($this, $token, $tool, wtArguments($tool, $this->world->matter, $this->request));

    expect($message)->toBe(__('ai_access.tools.write_refused'))
        ->and($message)->toContain('Quản trị chưa bật quyền ghi cho anh/chị')
        ->and($message)->toContain('không thử lại')
        ->and(wtWrittenRows())->toBe($before);

    $row = Activity::query()->where('event', 'mcp_tool_called')->latest('id')->first();
    expect($row->properties['outcome'])->toBe('denied')
        ->and($row->properties['tool'])->toBe($tool);
})->with(WRITE_TOOLS);

it('công tắc mcp.write_enabled tắt: người read_write cũng bị từ chối như người read, không ghi gì', function (string $tool) {
    McpOAuth::openServer(write: false);

    $names = collect(McpToolCall::listTools($this, $this->token)->json('result.tools'))->pluck('name')->all();
    expect($names)->not->toContain($tool);

    $before = wtWrittenRows();

    expect(McpToolCall::error($this, $this->token, $tool, wtArguments($tool, $this->world->matter, $this->request)))
        ->toBe(__('ai_access.tools.write_refused'))
        ->and(wtWrittenRows())->toBe($before);

    // Cặp dương: bật lại công tắc thì cùng lời gọi chạy.
    McpOAuth::openServer(write: true);
    wtCallOk($this, $this->token, $tool, wtArguments($tool, $this->world->matter, $this->request));
})->with(WRITE_TOOLS);

it('Review Focus 2: vụ đội khác, vụ hạn chế của chính mình, vụ chưa bật AI trả ĐÚNG phản hồi của một id không tồn tại, không ghi gì', function (string $tool, string $hidden) {
    $matter = $this->world->hiddenFromLead()[$hidden];
    $hiddenArguments = wtArguments($tool, $matter, wtRequestOn($matter));

    $missingArguments = $hiddenArguments;
    $key = $tool === 'draft_request_reply' ? 'request_id' : 'matter_id';
    $missingArguments[$key] = $tool === 'draft_request_reply' ? 'request_999999' : 'matter_999999';

    $before = wtWrittenRows();

    $hiddenResult = McpToolCall::call($this, $this->token, $tool, $hiddenArguments)->assertOk()->json('result');
    $missingResult = McpToolCall::call($this, $this->token, $tool, $missingArguments)->assertOk()->json('result');

    expect($hiddenResult)->toBe($missingResult)
        ->and($hiddenResult['isError'])->toBeTrue()
        ->and($hiddenResult['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($hiddenResult)->not->toHaveKey('structuredContent')
        ->and(wtWrittenRows())->toBe($before);

    // Cặp dương: cùng tham số trên vụ trong tập R3 thì chạy.
    wtCallOk($this, $this->token, $tool, wtArguments($tool, $this->world->matter, $this->request));
})->with(WRITE_TOOLS)->with(['other team', 'restricted', 'denied']);

it('id sai định dạng hay sai loại cũng là "Không tìm thấy", không lỗi validation để dò', function (string $tool) {
    $arguments = wtArguments($tool, $this->world->matter, $this->request);
    $key = $tool === 'draft_request_reply' ? 'request_id' : 'matter_id';

    foreach (['deadline_1', 'matter_01', 'MATTER_1', ' matter_1'] as $bad) {
        expect(McpToolCall::error($this, $this->token, $tool, [...$arguments, $key => $bad]))
            ->toBe(__('mcp.tool_errors.not_found'));
    }
})->with(WRITE_TOOLS);

it('R5, Review Focus 5: không dòng outbound_messages, không công bố, không sự kiện tới khách, không thư, không thông báo — ở nhánh thành công và nhánh lỗi', function (string $tool) {
    Event::fake([
        StageLogPublished::class, ClientRequestAnswered::class, ClientRequestOpened::class,
        DocumentPublished::class, ClientDocumentSubmitted::class, MatterStageChanged::class,
    ]);
    Mail::fake();
    Notification::fake();

    $publishedLogs = StageLog::query()->withoutGlobalScopes()->where('is_published', true)->count();
    $publishedDeadlines = Deadline::query()->withoutGlobalScopes()->where('is_published', true)->count();
    $outbound = OutboundMessage::query()->withoutGlobalScopes()->count();
    $requestStatus = $this->request->status;
    $matterStage = $this->world->matter->stage;

    $arguments = wtArguments($tool, $this->world->matter, $this->request);

    // Nhánh thành công (tool hai bước: cả hai bước).
    $first = wtCallOk($this, $this->token, $tool, $arguments);

    if (isset($first['confirmation_token'])) {
        wtCallOk($this, $this->token, $tool, [...$arguments, 'confirmation_token' => $first['confirmation_token']]);
    }

    // Nhánh lỗi: id không tồn tại, và một tham số hỏng.
    $key = $tool === 'draft_request_reply' ? 'request_id' : 'matter_id';
    McpToolCall::error($this, $this->token, $tool, [...$arguments, $key => $tool === 'draft_request_reply' ? 'request_999999' : 'matter_999999']);
    McpToolCall::error($this, $this->token, $tool, [...$arguments, 'khong_co' => 'x']);

    expect(OutboundMessage::query()->withoutGlobalScopes()->count())->toBe($outbound)
        ->and(StageLog::query()->withoutGlobalScopes()->where('is_published', true)->count())->toBe($publishedLogs)
        ->and(Deadline::query()->withoutGlobalScopes()->where('is_published', true)->count())->toBe($publishedDeadlines)
        ->and($this->request->refresh()->status)->toBe($requestStatus)
        ->and($this->world->matter->refresh()->stage)->toBe($matterStage);

    Event::assertNotDispatched(StageLogPublished::class);
    Event::assertNotDispatched(ClientRequestAnswered::class);
    Event::assertNotDispatched(DocumentPublished::class);
    Event::assertNotDispatched(MatterStageChanged::class);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
})->with(WRITE_TOOLS);

it('R8: mỗi lần gọi tool ghi một dòng mcp_tool_called; văn bản tự do chỉ còn độ dài, không giá trị', function (string $tool) {
    $arguments = wtArguments($tool, $this->world->matter, $this->request);

    wtCallOk($this, $this->token, $tool, $arguments);

    $row = Activity::query()->where('event', 'mcp_tool_called')->sole();
    $logged = json_encode($row->properties, JSON_UNESCAPED_UNICODE);

    expect($row->properties['outcome'])->toBe('ok')
        ->and($row->properties['tool'])->toBe($tool)
        ->and($row->causer?->is($this->world->lead))->toBeTrue();

    foreach (['Văn phòng đã nộp đơn', 'Văn phòng sẽ đi hoà giải', 'Nộp bản tự khai', 'Khách gọi hỏi', 'wt-progress-0001', 'wt-reply-0001'] as $text) {
        expect($logged)->not->toContain($text);
    }
})->with(WRITE_TOOLS);

it('rate limit của tool ghi (R8): 10 lần một phút, lần thứ 11 nhận 429 và tool không chạy', function () {
    $arguments = wtArguments('create_deadline', $this->world->matter);

    foreach (range(1, 10) as $_) {
        McpToolCall::call($this, $this->token, 'create_deadline', $arguments)->assertOk();
    }

    McpToolCall::call($this, $this->token, 'create_deadline', $arguments)->assertStatus(429);

    $this->travel(61)->seconds();

    McpToolCall::call($this, $this->token, 'create_deadline', $arguments)->assertOk();
});

it('Task 6 m7: quyền ghi tính MỘT lần mỗi request — tools/list với bốn tool ghi không hỏi lại công tắc cho từng tool', function () {
    DB::enableQueryLog();

    McpToolCall::listTools($this, $this->token)->assertOk();

    $settingsQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], '"settings"') || str_contains($query['query'], '`settings`'))
        ->count();

    // EnsureMcpAccess đọc công tắc đúng một lần (refusal) và một lần cho quyền ghi; không lần nào
    // cho từng tool ghi.
    expect($settingsQueries)->toBeLessThanOrEqual(2);
});

it('Task 6 m7: câu "ghi được không" đã nhớ trên request chỉ dùng cho ĐÚNG người đã được tính', function () {
    $request = Request::create('/mcp', 'POST');
    $reader = User::factory()->withRole(Role::Lawyer)->withAiAccess()->create();

    McpAccess::rememberWriteAccess($request, $this->world->lead);

    expect(McpAccess::canWriteInRequest($this->world->lead, $request))->toBeTrue()
        ->and(McpAccess::canWriteInRequest($reader, $request))->toBeFalse();

    // Không có gì được nhớ (ngoài request /mcp): hỏi lại như canWrite().
    expect(McpAccess::canWriteInRequest($this->world->lead, Request::create('/khac')))->toBeTrue()
        ->and(McpAccess::canWriteInRequest($reader, Request::create('/khac')))->toBeFalse();
});
