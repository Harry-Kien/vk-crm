<?php

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Pages\ActivityLogPage;
use App\Http\Middleware\Mcp\AuditToolCall;
use App\Http\Middleware\Mcp\EnsureMcpAccess;
use App\Http\Middleware\Mcp\ThrottleMcp;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Models\User;
use App\Notifications\Staff\McpReadVolumeAlert;
use App\Support\Audit;
use App\Support\Mcp\ToolCallContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Middleware\CheckToken;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpToolCalls;

/*
|--------------------------------------------------------------------------
| M11 Task 8 — audit mọi lần gọi tool (R8)
|--------------------------------------------------------------------------
| Mỗi `tools/call` mang token hợp lệ (đã qua bước 4–7 của `routes/ai.php`) sinh ĐÚNG MỘT dòng
| `mcp_tool_called`, kể cả lần bị từ chối — cả ở `EnsureMcpAccess`: causer là người sở hữu token,
| truyền TƯỜNG MINH; tham số theo allowlist quyết theo KIỂU tham số khai (id, enum, ngày ở tham số
| khai `format`, số, cờ; văn bản tự do và giá trị sai kiểu chỉ còn độ dài); id và TÊN các trường đã trả,
| không giá trị; outcome, thời gian, correlation id, IP.
|
| Đây cũng là bằng chứng cho câu của màn hình đồng ý OAuth (Task 4, `lang/vi/mcp_consent.php`,
| `acts_as_you_detail`): "mọi lần nó dùng một chức năng (tool) … đều được ghi nhật ký". Test đầu tiên dưới đây đi qua HTTP
| thật với token thật và đọc dòng nhật ký mà lần gọi đó để lại (rà soát Task 4, m1).
|
| Tool ở đây là tool thử kế thừa `CrmTool`, đúng cách tool thật của Task 10/11/13 làm: audit gắn ở
| bước gọi tool chung (`CallCrmTool` + middleware `AuditToolCall`), không ở từng tool.
*/

const AUDIT_MARKER = 'MARKER-7f3a-khong-duoc-ghi';

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    McpOAuth::openServer(write: true);

    app('translator')->addLines([
        'mcp.tools.aud_doc.title' => 'Thử đọc (Task 8)',
        'mcp.tools.aud_doc.description' => 'Dùng khi thử đọc. Không dùng để ghi.',
        'mcp.tools.aud_many.title' => 'Thử đọc nhiều (Task 8)',
        'mcp.tools.aud_many.description' => 'Dùng khi thử đọc nhiều bản ghi. Không dùng để ghi.',
        'mcp.tools.aud_ghi.title' => 'Thử ghi (Task 8)',
        'mcp.tools.aud_ghi.description' => 'Dùng khi thử ghi. Không dùng để gửi gì.',
        // Khoá của Task 10 (làn m11b): thông điệp "Không tìm thấy" duy nhất của mọi tool (R3).
        'mcp.tool_errors.not_found' => 'Không tìm thấy.',
    ], 'vi');

    app()->instance('audit.tool-calls', new ArrayObject);

    McpToolCalls::serve([auditReadTool(), auditManyTool(), auditWriteTool()]);
});

// Trang Nhật ký hệ thống ở cuối tệp render component Livewire; cờ tĩnh "đã render một component" của
// Livewire sống sang test kế tiếp trong cùng tiến trình và khiến Livewire chèn `<script>` vào trang
// HTML của test đó (màn hình đồng ý OAuth khẳng định không có `<script>`). Máy chủ thật mỗi request
// một tiến trình, nên chỉ test cần dọn.
afterEach(function () {
    Livewire::flushState();
});

function auditStaff(AiAccessMode $mode = AiAccessMode::Read): User
{
    return User::factory()
        ->position(UserPosition::Lawyer)
        ->withRole(Role::Lawyer)
        ->withAiAccess($mode)
        ->create();
}

/** @return list<Activity> */
function auditRows(): array
{
    return Activity::query()->where('event', 'mcp_tool_called')->orderBy('id')->get()->all();
}

function auditOnlyRow(): Activity
{
    $rows = auditRows();

    expect($rows)->toHaveCount(1);

    return $rows[0];
}

/** Đọc: tham số đủ các loại của allowlist (id, văn bản, enum, ngày, ngày giờ, số nguyên, số, cờ). */
function auditReadTool(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'aud_doc';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): Response|ResponseFactory
        {
            app('audit.tool-calls')[] = 'aud_doc';

            return match ($request->get('query')) {
                'throw' => throw new RuntimeException('Hỏng '.AUDIT_MARKER),
                'invalid' => throw ValidationException::withMessages(['query' => 'Sai '.AUDIT_MARKER]),
                'other-error' => Response::error('Lỗi khác'),
                default => $request->get('matter_id') === 'matter_404'
                    ? Response::error(__('mcp.tool_errors.not_found'))
                    : Response::structured([
                        'matter' => [
                            'id' => 'matter_5',
                            'code' => 'VK-0005',
                            'title' => 'Tiêu đề '.AUDIT_MARKER,
                            // `id` không có tiền tố, và một khoá dựng từ dữ liệu: không vào nhật ký.
                            'client' => ['id' => 'Khách '.AUDIT_MARKER],
                            'by_status' => ['Trạng thái '.AUDIT_MARKER => 1],
                        ],
                        'deadlines' => [
                            ['id' => 'deadline_1', 'title' => 'Hạn một', 'due_on' => '2031-02-17'],
                            ['id' => 'deadline_2', 'title' => 'Hạn hai', 'due_on' => '2026-10-10'],
                        ],
                    ]),
            };
        }

        /** @return array<string, mixed> */
        public function schema(JsonSchema $schema): array
        {
            return [
                'matter_id' => $schema->string()->max(30),
                'query' => $schema->string()->max(100),
                'severity' => $schema->string()->enum(['normal', 'critical']),
                // Tham số ngày khai `format` (rà soát Task 8, I1): chỉ ở đây chuỗi dạng ngày mới được giữ.
                'from' => $schema->string()->format('date')->max(10),
                'at' => $schema->string()->format('date-time')->max(25),
                'limit' => $schema->integer(),
                'ratio' => $schema->number(),
                'mine' => $schema->boolean(),
                'stages' => $schema->array()->items($schema->string()->enum(['intake', 'filed'])),
            ];
        }
    };
}

/** Đọc: trả đúng `count` bản ghi khác nhau, để đo ngưỡng 200 bản ghi một giờ. */
function auditManyTool(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'aud_many';

        protected function writes(): bool
        {
            return false;
        }

        public function handle(Request $request): ResponseFactory
        {
            $offset = (int) $request->get('offset', 0);

            return Response::structured([
                'results' => array_map(
                    fn (int $i): array => ['id' => 'matter_'.($offset + $i), 'title' => 'Vụ '.$i],
                    range(1, (int) $request->get('count', 1)),
                ),
            ]);
        }

        /** @return array<string, mixed> */
        public function schema(JsonSchema $schema): array
        {
            return [
                'count' => $schema->integer(),
                'offset' => $schema->integer(),
            ];
        }
    };
}

function auditWriteTool(): CrmTool
{
    return new class extends CrmTool
    {
        protected string $name = 'aud_ghi';

        protected function writes(): bool
        {
            return true;
        }

        public function handle(Request $request): ResponseFactory
        {
            app('audit.tool-calls')[] = 'aud_ghi';

            return Response::structured(['id' => 'deadline_99', 'title' => (string) $request->get('summary')]);
        }

        /** @return array<string, mixed> */
        public function schema(JsonSchema $schema): array
        {
            return [
                'matter_id' => $schema->string()->max(30),
                'summary' => $schema->string()->max(2000),
            ];
        }
    };
}

/*
|--------------------------------------------------------------------------
| Một lần gọi, một dòng, đúng người
|--------------------------------------------------------------------------
*/

it('R8 mỗi tools/call thành công sinh đúng một dòng mcp_tool_called, causer là người sở hữu token', function () {
    $staff = auditStaff();
    $client = McpOAuth::client();
    $token = McpOAuth::accessToken($this, $staff, $client);

    McpToolCalls::call($this, $token, 'aud_doc', ['matter_id' => 'matter_5'])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    $row = auditOnlyRow();

    expect($row->causer_type)->toBe($staff->getMorphClass())
        ->and((int) $row->causer_id)->toBe($staff->getKey())
        ->and($row->properties['channel'])->toBe('mcp')
        ->and($row->properties['tool'])->toBe('aud_doc')
        ->and($row->properties['outcome'])->toBe('ok')
        ->and($row->properties['oauth_client_id'])->toBe((string) $client->getKey())
        ->and($row->properties['platform'])->toBe('claude')
        ->and($row->properties['correlation_id'])->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/')
        ->and($row->properties['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($row->properties['ip'])->toBe('127.0.0.1');
});

it('R8 hai lần gọi cho hai dòng, mỗi dòng một correlation id riêng', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();
    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();

    $rows = auditRows();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->properties['correlation_id'])->not->toBe($rows[1]->properties['correlation_id']);
});

/**
 * Review Focus 4 — ngữ cảnh ambient sai guard. `Audit::record()` không có `$causer` thì lấy
 * `auth('web')` rồi `auth('client')`. Trong cùng tiến trình mà guard `web` đang mang một người KHÁC,
 * dòng nhật ký phải vẫn ghi người sở hữu token: causer được truyền tường minh.
 */
it('R8 causer là người sở hữu token kể cả khi guard web trong tiến trình đang mang người khác', function () {
    $owner = auditStaff();
    $other = User::factory()->admin()->create();
    $token = McpOAuth::accessToken($this, $owner);

    McpToolCalls::fresh();
    $this->actingAs($other, 'web');

    McpToolCalls::call($this, $token, 'aud_doc', fresh: false)->assertOk();

    $row = auditOnlyRow();

    expect((int) $row->causer_id)->toBe($owner->getKey())
        ->and($row->causer_type)->toBe($owner->getMorphClass());
});

it('R8 tools/list, ping và request không xác thực không sinh dòng mcp_tool_called', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::send($this, $token, 'tools/list')->assertOk();
    McpToolCalls::send($this, $token, 'ping')->assertOk();
    McpToolCalls::call($this, 'khong-phai-token', 'aud_doc')->assertUnauthorized();

    expect(auditRows())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Lần gọi bị từ chối cũng có dòng, với outcome đúng
|--------------------------------------------------------------------------
*/

it('R8 gọi bị từ chối vì không thấy vụ sinh một dòng outcome = not_found', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['matter_id' => 'matter_404'])
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('not_found')
        ->and($row->properties['tool'])->toBe('aud_doc')
        ->and($row->properties['arguments'])->toBe(['matter_id' => 'matter_404'])
        ->and($row->properties['returned_ids'])->toBe([])
        ->and($row->properties['returned_count'])->toBe(0);
});

it('R8 tham số không qua kiểm tra: outcome = invalid; lỗi khác của tool cũng là invalid', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['query' => 'invalid'])->assertOk()->assertJsonPath('result.isError', true);
    McpToolCalls::call($this, $token, 'aud_doc', ['query' => 'other-error'])->assertOk()->assertJsonPath('result.isError', true);

    expect(array_map(fn (Activity $row) => $row->properties['outcome'], auditRows()))->toBe(['invalid', 'invalid']);
});

it('R8 tool ném lỗi không lường trước: outcome = error, thông điệp lỗi không vào nhật ký', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['query' => 'throw'])->assertOk()->assertJsonPath('result.isError', true);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('error')
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER);
});

it('R8 người read gọi thẳng tên tool ghi (R13): một dòng outcome = denied, tool không chạy', function () {
    $token = McpOAuth::accessToken($this, auditStaff(AiAccessMode::Read));

    McpToolCalls::call($this, $token, 'aud_ghi', ['summary' => 'x']);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('denied')
        ->and($row->properties['tool'])->toBe('aud_ghi')
        ->and(app('audit.tool-calls')->getArrayCopy())->toBe([]);
});

/*
 * Rà soát Task 8, I3: token hợp lệ, người sở hữu đã biết, nhưng `EnsureMcpAccess` từ chối (công tắc
 * `mcp.enabled` tắt, phiên bản chính sách đổi mà chưa cam kết lại, `ai_access` về off…). R8: "mỗi
 * tools/call, kể cả lần bị từ chối, sinh đúng một dòng" — lần gọi này có người, nên có dòng.
 */
it('R8 tools/call bị EnsureMcpAccess từ chối (công tắc mcp.enabled tắt): 401 và đúng một dòng outcome = denied, causer là người sở hữu token', function () {
    $staff = auditStaff();
    $client = McpOAuth::client();
    $token = McpOAuth::accessToken($this, $staff, $client);

    McpOAuth::openServer(enabled: false);

    McpToolCalls::call($this, $token, 'aud_doc', ['matter_id' => 'matter_5'])->assertUnauthorized();

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('denied')
        ->and($row->causer_type)->toBe($staff->getMorphClass())
        ->and((int) $row->causer_id)->toBe($staff->getKey())
        ->and($row->properties['oauth_client_id'])->toBe((string) $client->getKey())
        ->and($row->properties['channel'])->toBe('mcp')
        ->and(app('audit.tool-calls')->getArrayCopy())->toBe([]);
});

it('R8 tools/call bị từ chối vì đổi phiên bản chính sách (chưa cam kết lại): một dòng denied mỗi lần gọi', function () {
    $staff = auditStaff();
    $token = McpOAuth::accessToken($this, $staff);

    config(['vkcrm.mcp.policy_version' => 'thu-nghiem-2']);

    McpToolCalls::call($this, $token, 'aud_doc')->assertUnauthorized();
    McpToolCalls::call($this, $token, 'aud_doc')->assertUnauthorized();

    $rows = auditRows();

    expect($rows)->toHaveCount(2)
        ->and(array_map(fn (Activity $row): string => $row->properties['outcome'], $rows))->toBe(['denied', 'denied'])
        ->and(array_map(fn (Activity $row): int => (int) $row->causer_id, $rows))->toBe([$staff->getKey(), $staff->getKey()]);
});

it('R8 cặp dương và âm của lần từ chối ở EnsureMcpAccess: bật lại công tắc thì outcome = ok; initialize bị từ chối không sinh dòng', function () {
    $staff = auditStaff();
    $token = McpOAuth::accessToken($this, $staff);

    McpOAuth::openServer(enabled: false);
    McpToolCalls::send($this, $token, 'tools/list')->assertUnauthorized();

    expect(auditRows())->toBe([]);

    McpOAuth::openServer();
    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();

    expect(auditOnlyRow()->properties['outcome'])->toBe('ok');
});

it('R8 AuditToolCall đứng ngay sau CheckToken mcp:use và TRƯỚC EnsureMcpAccess, ThrottleMcp ngay sau nó', function () {
    $middleware = Route::getRoutes()->match(request()->create('/mcp', 'POST'))->gatherMiddleware();

    $scopeAt = array_search(CheckToken::using('mcp:use'), $middleware, true);

    expect($scopeAt)->not->toBeFalse()
        ->and(array_search(AuditToolCall::class, $middleware, true))->toBe($scopeAt + 1)
        ->and(array_search(ThrottleMcp::class, $middleware, true))->toBe($scopeAt + 2)
        ->and(array_search(EnsureMcpAccess::class, $middleware, true))->toBe($scopeAt + 3);
});

it('R8 kết cục suy từ mã HTTP khi bước gọi tool chưa đặt: 401 và 403 là denied, 5xx là error, còn lại invalid', function (int $status, string $outcome) {
    $call = new ToolCallContext;
    $call->settleStatus($status);

    expect($call->currentOutcome()?->value)->toBe($outcome);
})->with([
    [401, 'denied'],
    [403, 'denied'],
    [500, 'error'],
    [503, 'error'],
    [400, 'invalid'],
    [200, 'invalid'],
]);

it('R8 tên tool không có trên máy chủ: một dòng outcome = invalid, tên lạ không vào nhật ký (chỉ độ dài)', function () {
    $token = McpOAuth::accessToken($this, auditStaff());
    $name = 'khong_co_'.strtolower(str_replace('-', '_', AUDIT_MARKER));

    McpToolCalls::call($this, $token, $name);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('invalid')
        ->and($row->properties['tool'])->toBeNull()
        ->and($row->properties['tool_name_length'])->toBe(mb_strlen($name))
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(strtolower(str_replace('-', '_', AUDIT_MARKER)));
});

it('R8 tools/call thiếu name: một dòng outcome = invalid', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::fresh();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => (object) []], [
        'Authorization' => 'Bearer '.$token,
    ]);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('invalid')
        ->and($row->properties['tool'])->toBeNull();
});

it('R8 tool ghi thành công: một dòng outcome = ok, id đã tạo, văn bản tự do chỉ còn độ dài', function () {
    $token = McpOAuth::accessToken($this, auditStaff(AiAccessMode::ReadWrite));
    $summary = 'Khách gọi hỏi tiến độ '.AUDIT_MARKER;

    McpToolCalls::call($this, $token, 'aud_ghi', ['matter_id' => 'matter_5', 'summary' => $summary])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    $row = auditOnlyRow();

    expect($row->properties['outcome'])->toBe('ok')
        ->and($row->properties['arguments'])->toBe(['matter_id' => 'matter_5', 'summary' => ['length' => mb_strlen($summary)]])
        ->and($row->properties['returned_ids'])->toBe(['deadline_99'])
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER);
});

/*
|--------------------------------------------------------------------------
| Tham số theo allowlist; kết quả chỉ còn id, số bản ghi và TÊN trường
|--------------------------------------------------------------------------
*/

it('R8 dòng audit không chứa chuỗi đánh dấu đặt trong tham số tự do: có độ dài thay vào', function () {
    $token = McpOAuth::accessToken($this, auditStaff());
    $query = 'tìm '.AUDIT_MARKER;

    McpToolCalls::call($this, $token, 'aud_doc', ['query' => $query])->assertOk();

    $row = auditOnlyRow();

    expect($row->properties['arguments'])->toBe(['query' => ['length' => mb_strlen($query)]])
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER)
        ->and((string) $row->description)->not->toContain(AUDIT_MARKER);
});

it('R8 id có tiền tố, enum, ngày, số và cờ được ghi nguyên giá trị', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', [
        'matter_id' => 'matter_7',
        'severity' => 'critical',
        'from' => '2026-10-01',
        'limit' => 5,
        'mine' => true,
        'stages' => ['intake', 'filed'],
    ])->assertOk();

    expect(auditOnlyRow()->properties['arguments'])->toBe([
        'matter_id' => 'matter_7',
        'severity' => 'critical',
        'from' => '2026-10-01',
        'limit' => 5,
        'mine' => true,
        'stages' => ['intake', 'filed'],
    ]);
});

it('R8 chuỗi không đúng hình dạng id/enum/ngày thì chỉ còn độ dài, kể cả ở tham số mang tên id hay enum', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', [
        'matter_id' => 'matter_5'.AUDIT_MARKER,
        'severity' => AUDIT_MARKER,
        'from' => '2026-10-01'.AUDIT_MARKER,
        'stages' => ['intake', AUDIT_MARKER],
    ]);

    $arguments = auditOnlyRow()->properties['arguments'];

    expect($arguments['matter_id'])->toBe(['length' => mb_strlen('matter_5'.AUDIT_MARKER)])
        ->and($arguments['severity'])->toBe(['length' => mb_strlen(AUDIT_MARKER)])
        ->and($arguments['from'])->toBe(['length' => mb_strlen('2026-10-01'.AUDIT_MARKER)])
        ->and($arguments['stages'])->toBe(['intake', ['length' => mb_strlen(AUDIT_MARKER)]])
        ->and(json_encode($arguments, JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER);
});

/*
 * Rà soát Task 8, I1: allowlist quyết theo KIỂU mà tool khai, không theo hình dạng giá trị. Model hay
 * gửi một dãy chữ số dưới dạng số JSON (`{"query": 912345678}`), và một ngày sinh gõ vào `query` có
 * đúng hình dạng ngày ISO. Cả hai phải chỉ còn độ dài — R8 "không ghi CCCD, số điện thoại".
 */
it('R8 số, cờ hay ngày gửi vào tham số văn bản tự do (query) chỉ còn độ dài: số điện thoại, CCCD, ngày sinh không vào nhật ký', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['query' => 912345678]);
    McpToolCalls::call($this, $token, 'aud_doc', ['query' => 1234567890.5]);
    McpToolCalls::call($this, $token, 'aud_doc', ['query' => true]);
    McpToolCalls::call($this, $token, 'aud_doc', ['query' => '1990-05-12']);

    $rows = auditRows();

    expect($rows)->toHaveCount(4)
        ->and($rows[0]->properties['arguments'])->toBe(['query' => ['length' => 9]])
        ->and($rows[1]->properties['arguments'])->toBe(['query' => ['length' => mb_strlen((string) 1234567890.5)]])
        ->and($rows[2]->properties['arguments'])->toBe(['query' => ['length' => 4]])
        ->and($rows[3]->properties['arguments'])->toBe(['query' => ['length' => 10]]);

    $logged = (string) json_encode(array_map(fn (Activity $row): array => $row->toArray(), $rows), JSON_UNESCAPED_UNICODE);

    expect($logged)->not->toContain('912345678')
        ->and($logged)->not->toContain('1234567890')
        ->and($logged)->not->toContain('1990-05-12');
});

it('R8 giá trị sai kiểu khai báo chỉ còn độ dài: số ở tham số chuỗi hay enum, số ở tham số cờ, cờ hay số lẻ ở tham số số nguyên', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', [
        'matter_id' => 912345678,
        'severity' => 1,
        'mine' => 1,
        'limit' => true,
        'from' => 20261001,
        'stages' => [912345678],
    ]);
    McpToolCalls::call($this, $token, 'aud_doc', ['limit' => 2.5]);
    // Chuỗi có hình dạng id hay ngày cũng chỉ được giữ ở tham số khai kiểu `string`.
    McpToolCalls::call($this, $token, 'aud_doc', ['limit' => 'matter_5', 'mine' => '2026-10-01']);

    $rows = auditRows();

    expect($rows[0]->properties['arguments'])->toBe([
        'matter_id' => ['length' => 9],
        'severity' => ['length' => 1],
        'mine' => ['length' => 1],
        'limit' => ['length' => 4],
        'from' => ['length' => 8],
        'stages' => [['length' => 9]],
    ])->and($rows[1]->properties['arguments'])->toBe(['limit' => ['length' => 3]])
        ->and($rows[2]->properties['arguments'])->toBe(['limit' => ['length' => 8], 'mine' => ['length' => 10]]);
});

it('R8 cặp dương của kiểu khai báo: số nguyên và số lẻ ở tham số number, ngày giờ ở tham số date-time được giữ nguyên', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', [
        'ratio' => 2.5,
        'at' => '2026-10-01T09:30:00+07:00',
    ]);
    McpToolCalls::call($this, $token, 'aud_doc', ['ratio' => 3]);

    $rows = auditRows();

    expect($rows[0]->properties['arguments'])->toBe(['ratio' => 2.5, 'at' => '2026-10-01T09:30:00+07:00'])
        ->and($rows[1]->properties['arguments'])->toBe(['ratio' => 3]);
});

it('R8 danh sách dài hơn 25 phần tử và object chỉ còn số phần tử', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['stages' => array_fill(0, 26, 'intake')])->assertOk();
    McpToolCalls::call($this, $token, 'aud_doc', ['stages' => ['ghi_chu' => AUDIT_MARKER]])->assertOk();

    $rows = auditRows();

    expect($rows[0]->properties['arguments'])->toBe(['stages' => ['count' => 26]])
        ->and($rows[1]->properties['arguments'])->toBe(['stages' => ['count' => 1]])
        ->and(json_encode($rows[1]->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER);
});

it('R8 tham số ngoài inputSchema không vào nhật ký, kể cả tên của nó: chỉ số lượng', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['matter_id' => 'matter_5', AUDIT_MARKER => 'x', 'khac' => AUDIT_MARKER]);

    $row = auditOnlyRow();

    expect($row->properties['arguments'])->toBe(['matter_id' => 'matter_5'])
        ->and($row->properties['unknown_argument_count'])->toBe(2)
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain(AUDIT_MARKER);
});

it('R8 kết quả: id các đối tượng trả về, số bản ghi, tên các trường — không giá trị nào', function () {
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc', ['matter_id' => 'matter_5'])->assertOk();

    $row = auditOnlyRow();

    expect($row->properties['returned_ids'])->toBe(['matter_5', 'deadline_1', 'deadline_2'])
        ->and($row->properties['returned_count'])->toBe(3)
        ->and($row->properties['returned_fields'])->toBe([
            'deadlines[].due_on',
            'deadlines[].id',
            'deadlines[].title',
            'matter.by_status.*',
            'matter.client.id',
            'matter.code',
            'matter.id',
            'matter.title',
        ])
        ->and(json_encode($row->toArray(), JSON_UNESCAPED_UNICODE))
        ->not->toContain(AUDIT_MARKER)
        ->not->toContain('VK-0005')
        ->not->toContain('Hạn một')
        ->not->toContain('2031-02-17');
});

/*
|--------------------------------------------------------------------------
| IP dưới TRUSTED_PROXIES (M8 R1)
|--------------------------------------------------------------------------
*/

it('R8 IP ghi theo request()->ip(): sau proxy được tin là IP gốc trong X-Forwarded-For', function () {
    config(['trustedproxy.proxies' => '10.0.0.1']);
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::fresh();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.9');

    McpToolCalls::call($this, $token, 'aud_doc', fresh: false)->assertOk();

    expect(auditOnlyRow()->properties['ip'])->toBe('203.0.113.9');
});

it('R8 IP: không có proxy được tin thì X-Forwarded-For bị bỏ qua, ghi REMOTE_ADDR', function () {
    config(['trustedproxy.proxies' => null]);
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::fresh();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->withHeader('X-Forwarded-For', '203.0.113.9');

    McpToolCalls::call($this, $token, 'aud_doc', fresh: false)->assertOk();

    expect(auditOnlyRow()->properties['ip'])->toBe('198.51.100.4');
});

it('R8 nền tảng suy từ redirect của client: ChatGPT', function () {
    $client = McpOAuth::client(['https://chatgpt.com/connector_platform_oauth_redirect']);
    $token = McpOAuth::accessToken($this, auditStaff(), $client);

    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();

    expect(auditOnlyRow()->properties['platform'])->toBe('chatgpt');
});

/*
|--------------------------------------------------------------------------
| Giữ nhật ký ≥ 400 ngày (R8, [PL:349])
|--------------------------------------------------------------------------
*/

it('R8 dòng channel = mcp không bị activitylog:clean dọn trước 400 ngày', function () {
    $token = McpOAuth::accessToken($this, auditStaff());
    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();

    $row = auditOnlyRow();
    Activity::query()->whereKey($row->getKey())->update(['created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400)]);

    Artisan::call('activitylog:clean', ['--force' => true]);

    expect(Activity::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Cảnh báo admin: một người đọc quá 200 bản ghi trong một giờ
|--------------------------------------------------------------------------
*/

it('R8 đọc 201 bản ghi trong một giờ: mỗi admin nhận đúng một thông báo, không nhận hai', function () {
    $staff = auditStaff();
    $admin = User::factory()->admin()->create();
    $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
    $lawyer = User::factory()->withRole(Role::Lawyer)->create();
    $token = McpOAuth::accessToken($this, $staff);

    McpToolCalls::call($this, $token, 'aud_many', ['count' => 200])->assertOk();

    expect($admin->notifications()->count())->toBe(0);

    McpToolCalls::call($this, $token, 'aud_many', ['count' => 1, 'offset' => 500])->assertOk();

    expect($admin->notifications()->where('type', McpReadVolumeAlert::class)->count())->toBe(1)
        ->and($inactiveAdmin->notifications()->count())->toBe(0)
        ->and($lawyer->notifications()->count())->toBe(0)
        ->and($staff->notifications()->count())->toBe(0);

    McpToolCalls::call($this, $token, 'aud_many', ['count' => 50])->assertOk();

    expect($admin->notifications()->where('type', McpReadVolumeAlert::class)->count())->toBe(1);

    $alert = $admin->notifications()->first();

    expect($alert->data['body'])->toContain($staff->name)
        ->and($alert->data['viewData'])->toBe(['mcp_read_volume_user_id' => $staff->getKey()]);
});

it('R8 200 bản ghi trong một giờ chưa phải là quá ngưỡng', function () {
    $staff = auditStaff();
    $admin = User::factory()->admin()->create();
    $token = McpOAuth::accessToken($this, $staff);

    McpToolCalls::call($this, $token, 'aud_many', ['count' => 150])->assertOk();
    McpToolCalls::call($this, $token, 'aud_many', ['count' => 50])->assertOk();

    expect($admin->notifications()->count())->toBe(0);
});

it('R8 số bản ghi đếm theo từng người: hai người mỗi người 150 bản ghi không ai bị cảnh báo', function () {
    $admin = User::factory()->admin()->create();

    foreach ([auditStaff(), auditStaff()] as $staff) {
        McpToolCalls::call($this, McpOAuth::accessToken($this, $staff), 'aud_many', ['count' => 150])->assertOk();
    }

    expect($admin->notifications()->count())->toBe(0);
});

it('R8 cảnh báo tối đa một lần mỗi giờ cho một người, kể cả khi cửa sổ đếm đã sang lượt mới', function () {
    $staff = auditStaff();
    $admin = User::factory()->admin()->create();
    $token = McpOAuth::accessToken($this, $staff);

    // Phút 0 mở cửa sổ đếm một giờ; phút 50 vượt ngưỡng: cảnh báo thứ nhất.
    McpToolCalls::call($this, $token, 'aud_many', ['count' => 10])->assertOk();
    $this->travel(50)->minutes();
    McpToolCalls::call($this, $token, 'aud_many', ['count' => 191, 'offset' => 10])->assertOk();

    expect($admin->notifications()->count())->toBe(1);

    // Phút 61: cửa sổ đếm cũ đã hết và cửa sổ mới vượt ngưỡng lần nữa, nhưng cảnh báo trước mới
    // được 11 phút.
    $this->travel(11)->minutes();
    McpToolCalls::call($this, $token, 'aud_many', ['count' => 201])->assertOk();

    expect($admin->notifications()->count())->toBe(1);

    // Phút 122: cả cửa sổ đếm lẫn một giờ của cảnh báo trước đều đã hết.
    $this->travel(61)->minutes();
    McpToolCalls::call($this, $token, 'aud_many', ['count' => 201])->assertOk();

    expect($admin->notifications()->count())->toBe(2);
});

it('R8 bản ghi do tool ghi trả về không tính vào ngưỡng đọc', function () {
    $staff = auditStaff(AiAccessMode::ReadWrite);
    $admin = User::factory()->admin()->create();
    $token = McpOAuth::accessToken($this, $staff);

    McpToolCalls::call($this, $token, 'aud_many', ['count' => 200])->assertOk();
    McpToolCalls::call($this, $token, 'aud_ghi', ['summary' => 'x'])->assertOk();

    expect($admin->notifications()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Trang Nhật ký hệ thống (M6.5 Task 20)
|--------------------------------------------------------------------------
*/

it('R8 trang Nhật ký hệ thống lọc được theo kênh AI (channel = mcp), nhãn tiếng Việt, không khoá dịch thô', function () {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->admin()->create();
    $token = McpOAuth::accessToken($this, auditStaff());

    McpToolCalls::call($this, $token, 'aud_doc')->assertOk();
    $mcpRow = auditOnlyRow();
    $otherRow = Audit::record('ai_policy_acknowledged', $admin, [], $admin);

    McpToolCalls::fresh();
    $this->actingAs($admin, 'web');

    Livewire::test(ActivityLogPage::class)
        ->assertCanSeeTableRecords([$mcpRow, $otherRow])
        ->filterTable('channel', 'mcp')
        ->assertCanSeeTableRecords([$mcpRow])
        ->assertCanNotSeeTableRecords([$otherRow])
        ->assertSee(__('activity.events.mcp_tool_called'))
        ->assertDontSee('activity.events.mcp_tool_called')
        ->assertDontSee('mcp_audit.');

    $this->get(ActivityLogPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('mcp_audit.page.channel_filter'))
        ->assertSee(__('mcp_audit.page.ip_note'))
        ->assertDontSee('mcp_audit.page');
});

it('R8 mcp_tool_called có nhãn tiếng Việt trong lang/vi/activity.php', function () {
    expect(__('activity.events.mcp_tool_called'))->not->toBe('activity.events.mcp_tool_called')
        ->and(__('mcp_audit.page.ip_note'))->not->toBe('mcp_audit.page.ip_note');
});

it('R8 oauth_client_id là client của chính token đó', function () {
    $staff = auditStaff();
    $first = McpOAuth::client();
    $second = McpOAuth::client(['http://127.0.0.1/callback']);

    McpToolCalls::call($this, McpOAuth::accessToken($this, $staff, $second), 'aud_doc')->assertOk();

    expect(auditOnlyRow()->properties['oauth_client_id'])->toBe((string) $second->getKey())
        ->and(auditOnlyRow()->properties['platform'])->toBe('local_app')
        ->and(Client::query()->whereKey($first->getKey())->exists())->toBeTrue();
});
