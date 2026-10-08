<?php

use App\Enums\MatterAiAccess;
use App\Models\ClientRequest;
use App\Models\ClientRequestReplyDraft;
use App\Models\CommunicationLog;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\StageLogDraft;
use App\Support\Mcp\McpIds;
use App\Support\Scopes\ClientPortalScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\McpOAuth;
use Tests\Support\McpSweep;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 14 — các lượt quét xuyên suốt của máy chủ MCP
|--------------------------------------------------------------------------
| Ba câu hỏi, mỗi câu chạy trên MỌI tool của `CrmServer` (tập tool đọc từ server, không chép tay —
| tool thêm sau này tự vào lượt quét; `Tests\Support\McpSweep`):
|
| 1. **Dữ liệu nhạy cảm** [DC:117]: một chuỗi đánh dấu ở từng chỗ R4/R3/R10 cấm (ghi chú nội bộ, CCCD,
|    số điện thoại đủ số, ghi chú khách, các bên thứ ba, tài liệu nhóm D, tên tệp gốc, dòng nhật ký
|    `conflict_*`, người nhận thư, tiền của vụ (hợp đồng, đợt, khoản thu, phụ lục, giờ làm — SPEC §5, M9),
|    và tiêu đề/ghi chú/con của vụ hạn chế, vụ `denied`, vụ đã xoá).
|    Gọi mọi tool với mọi tham số hợp lệ trỏ vào các vụ đó, dưới admin `read_write` (luật sư phụ trách
|    của cả bốn vụ). Không kim nào xuất hiện trong THÂN HTTP THÔ của bất kỳ phản hồi nào — thân thô gồm
|    cả `_meta`, vì `_meta` vẫn tới host [DC:673].
| 2. **Mọi tool đều kiểm policy**: người `read_write` hợp lệ nhưng KHÔNG thấy vụ gọi mọi tool có tham số
|    id trỏ vào vụ đó: "Không tìm thấy", và một dòng `mcp_tool_called` kết cục `not_found`.
| 3. **Không dùng ngữ cảnh ambient** (Review Focus 4, họ lỗi M4/M5): một phiên cổng khách mở song song
|    trong cùng tiến trình không đổi một phản hồi nào, và mọi dòng nhật ký, mọi bản ghi tạo ra vẫn mang
|    người sở hữu token.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    config(['vkcrm.mcp.party_names' => 'pseudonym']);

    $this->sweep = McpSweep::build();
    $this->token = McpOAuth::accessToken($this, $this->sweep->admin);
});

/** @param  array{body: string}  $entry */
function sweepResult(array $entry): ?array
{
    $result = json_decode($entry['body'], true)['result'] ?? null;

    return is_array($result) ? $result : null;
}

it('lượt quét dữ liệu nhạy cảm: không kim nào trong thân HTTP thô của mọi phản hồi, khi gọi mọi tool với mọi tham số hợp lệ trỏ vào các vụ đó', function () {
    $plan = $this->sweep->plan();
    $declared = array_map(fn (string $class): string => app($class)->name(), McpToolCall::registeredTools());

    // Tiền đề: kế hoạch gọi đủ mọi tool server khai, và bộ dữ liệu có kim ở mọi chỗ kế hoạch M11 Task 14
    // liệt kê.
    expect(array_values(array_unique(array_column($plan, 'tool'))))->toBe($declared)
        ->and(array_keys($this->sweep->secrets))->toContain(
            'stage_logs.internal_note', 'matters.description_internal', 'clients.id_number', 'clients.note',
            'matter_parties.note', 'matter_parties.phone_normalized', 'matter_parties.name (bên thứ ba, pseudonym)',
            'documents.title nhóm D', 'tên tệp gốc của tài liệu', 'activity_log conflict_*: properties',
            'outbound_messages.recipient', 'tiền của vụ: contracts, instalments, payments, contract_amendments, time_entries',
            'vụ restricted: tiêu đề, mã, khách, ghi chú nội bộ, nội dung con',
            'vụ denied: tiêu đề, mã, khách, ghi chú nội bộ, nội dung con',
        );

    $transcript = McpSweep::run($this, $this->token, $plan);

    expect($this->sweep->leaks($transcript))->toBe([]);

    // Tiền đề (sau, để một lần lộ được báo bằng chính tên của nó): mọi tool có ít nhất một lần gọi
    // THÀNH CÔNG (lượt quét nhìn dữ liệu thật đi ra, không chỉ những lời từ chối), và kim DƯƠNG — tiêu
    // đề vụ mở, số điện thoại đã che — có mặt trong phản hồi. Thiếu chúng thì "không lộ gì" ở trên không
    // đo gì.
    $succeeded = collect($transcript)
        ->filter(fn (array $entry): bool => $entry['status'] === 200 && (sweepResult($entry)['isError'] ?? true) === false)
        ->pluck('tool')->unique()->values()->all();
    $bodies = implode("\n", array_column($transcript, 'body'));

    expect(array_values(array_diff($declared, $succeeded)))->toBe([], 'tool chưa lần nào thành công trong lượt quét')
        ->and($bodies)->toContain(McpSweep::VISIBLE)
        ->and($bodies)->toContain('***888');

    // Tool ghi đã GHI thật (cặp dương của "ghi được nhưng không trả lại"): ghi chú nội bộ gửi vào
    // draft_progress_update nằm trong nháp, không trong phản hồi nào ở trên.
    expect(StageLogDraft::query()->where('internal_note', McpSweep::WRITTEN_INTERNAL_NOTE)->exists())->toBeTrue()
        ->and(Deadline::query()->where('created_via', 'mcp')->exists())->toBeTrue()
        ->and(CommunicationLog::query()->where('created_via', 'mcp')->exists())->toBeTrue()
        ->and(ClientRequestReplyDraft::query()->exists())->toBeTrue();
});

it('máy dò rò rỉ thấy một kim ở dạng thô, dạng thoát \\u và dạng thoát \\/, không phân biệt hoa thường (cặp dương/âm)', function () {
    $entry = fn (string $body): array => ['tool' => 'whoami', 'arguments' => [], 'status' => 200, 'body' => $body];
    $needle = $this->sweep->secrets['clients.note'][0];

    foreach ([
        json_encode(['result' => ['text' => $needle]]),
        json_encode(['result' => ['text' => strtolower($needle)]]),
        json_encode(['result' => ['text' => 'x/'.$needle]]),
        '{"result":{"text":"'.implode('', array_map(fn (string $c): string => sprintf('\\u%04x', ord($c)), str_split($needle))).'"}}',
    ] as $body) {
        expect($this->sweep->leaks([$entry($body)]))->toHaveCount(1, $body);
    }

    expect($this->sweep->leaks([$entry(json_encode(['result' => ['text' => 'không có gì '.McpSweep::VISIBLE]]))]))->toBe([]);
});

it('mọi tool có tham số id: người read_write hợp lệ KHÔNG thấy vụ nhận "Không tìm thấy" và đúng một dòng audit not_found; cặp dương: cùng lời gọi trên vụ của chính người đó thì không', function () {
    $outsider = $this->sweep->outsider;
    $token = McpOAuth::accessToken($this, $outsider);
    // Ba loại bản ghi ẩn của Review Focus 2 (rà soát Task 14, m1): vụ của đội khác (vụ mở của admin),
    // vụ HẠN CHẾ của chính người gọi (họ là luật sư phụ trách — web cho họ xem), và vụ `denied` của
    // chính người gọi — mỗi vụ một yêu cầu. Cả ba phải giống hệt id không tồn tại.
    $ownRestricted = Matter::factory()->aiAccessAllowed()->restricted()->create(['lead_lawyer_id' => $outsider->id]);
    $ownDenied = Matter::factory()->create(['lead_lawyer_id' => $outsider->id]);
    $hiddenOwn = [$ownRestricted, $ownDenied];

    expect($ownDenied->fresh()->ai_access)->toBe(MatterAiAccess::Denied);

    foreach ($hiddenOwn as $matter) {
        expect(Gate::forUser($outsider)->allows('view', $matter))->toBeTrue();
    }

    $foreign = [
        'matter' => [
            McpIds::encode(McpIds::MATTER, $this->sweep->open->id),
            ...array_map(fn (Matter $matter): string => McpIds::encode(McpIds::MATTER, $matter->id), $hiddenOwn),
        ],
        'request' => [
            McpIds::encode(McpIds::REQUEST, $this->sweep->requests['open']->id),
            ...array_map(fn (Matter $matter): string => McpIds::encode(McpIds::REQUEST, ClientRequest::factory()->create(['matter_id' => $matter->id])->id), $hiddenOwn),
        ],
    ];
    $own = [
        'matter' => [McpIds::encode(McpIds::MATTER, $this->sweep->outsiderMatter->id)],
        'request' => [McpIds::encode(McpIds::REQUEST, $this->sweep->requests['outsider']->id)],
    ];
    $foreign['record'] = [...$foreign['matter'], ...$foreign['request']];
    $own['record'] = [...$own['matter'], ...$own['request']];

    $checked = [];
    $failures = [];

    foreach (McpToolCall::registeredTools() as $class) {
        $described = $this->sweep->describe($class);
        $name = $described['name'];

        foreach ($described['ids'] as $param => $id) {
            if ($id['kind'] === 'user') {
                continue;
            }

            foreach ($foreign[$id['kind']] as $value) {
                Cache::flush();
                $before = (int) Activity::query()->max('id');
                $response = McpToolCall::call($this, $token, $name, $this->sweep->arguments($class, [$param => $value]));
                $rows = Activity::query()->where('id', '>', $before)->where('event', 'mcp_tool_called')->get();
                $label = "{$name}.{$param}={$value}";
                $checked[] = $label;

                if ($response->json('result.isError') !== true || $response->json('result.content.0.text') !== __('mcp.tool_errors.not_found')) {
                    $failures[] = "{$label}: không phải \"Không tìm thấy\" — ".$response->getContent();
                }

                if ($rows->count() !== 1
                    || $rows->first()->properties['tool'] !== $name
                    || $rows->first()->properties['outcome'] !== 'not_found'
                    || (int) $rows->first()->causer_id !== $outsider->id) {
                    $failures[] = "{$label}: dòng audit sai — ".$rows->map(fn (Activity $row): string => json_encode($row->properties))->implode(' | ');
                }
            }

            // Cặp dương: cùng tool, cùng tham số, trên bản ghi của chính người đó — ít nhất một giá trị
            // không bị "Không tìm thấy" (với `id`, một trong hai loại là đúng loại của tool).
            $positive = false;

            foreach ($own[$id['kind']] as $value) {
                Cache::flush();
                $response = McpToolCall::call($this, $token, $name, $this->sweep->arguments($class, [$param => $value]));
                $positive = $positive || $response->json('result.content.0.text') !== __('mcp.tool_errors.not_found');
            }

            if (! $positive) {
                $failures[] = "{$name}.{$param}: cặp dương cũng \"Không tìm thấy\" — tập dữ liệu không đo gì";
            }
        }
    }

    // Tiền đề: đủ mười hai tool có tham số id trỏ vào vụ/yêu cầu (mọi tool trừ whoami, search, search_matters).
    expect(collect($checked)->map(fn (string $label): string => explode('.', $label)[0])->unique()->values()->all())->toBe([
        'fetch', 'get_matter', 'list_matter_updates', 'list_deadlines', 'get_checklist', 'list_documents',
        'list_client_requests', 'get_client_request', 'draft_progress_update', 'draft_request_reply', 'create_deadline', 'log_communication',
    ]);

    expect($failures)->toBe([]);
});

/**
 * Chuẩn hoá một phản hồi để so hai lượt: giá trị ngẫu nhiên theo lần gọi (mã xác nhận, cursor, hạn của
 * mã) luôn bị che; với tool GHI, cả `id`, `url`, `created_at` của bản ghi vừa tạo (trên MariaDB,
 * rollback không trả lại số tự tăng, nên lượt sau mang id khác cho cùng một bản ghi).
 */
function sweepNormalized(array $entry, bool $writes): array
{
    $masked = ['confirmation_token', 'next_cursor', 'expires_at', ...($writes ? ['id', 'url', 'created_at'] : [])];

    $walk = function (mixed $value) use (&$walk, $masked): mixed {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = in_array($key, $masked, true) && $child !== null ? '<masked>' : $walk($child);
        }

        return $value;
    };

    $decoded = json_decode($entry['body'], true);
    $result = $decoded['result'] ?? null;

    if (is_array($result)) {
        // content[0].text là đúng JSON của structuredContent — so phần có cấu trúc đã che là đủ.
        if (isset($result['structuredContent'])) {
            unset($result['content']);
        }

        $decoded['result'] = $walk($result);
    }

    return ['tool' => $entry['tool'], 'status' => $entry['status'], 'body' => $decoded];
}

it('không dùng ngữ cảnh ambient: một phiên cổng khách mở song song không đổi phản hồi nào; mọi dòng nhật ký và mọi bản ghi tạo ra mang người sở hữu token', function () {
    $plan = $this->sweep->plan(variations: false);
    $writes = collect(McpToolCall::registeredTools())->mapWithKeys(fn (string $class): array => [app($class)->name() => app($class)->isWriteTool()])->all();
    $admin = $this->sweep->admin;

    // Lượt 1: không phiên cổng khách. Rollback để lượt 2 bắt đầu từ đúng cùng dữ liệu.
    $this->freezeTime();
    DB::beginTransaction();
    $plain = McpSweep::run($this, $this->token, $plan);
    DB::rollBack();

    // Lượt 2: cùng tiến trình mang một phiên cổng khách — của CHÍNH khách của vụ mở, người cổng thấy
    // được vụ đó (nên một scope cổng bật nhầm cắt dòng tiến độ, mốc chưa công bố… thay vì cắt hết).
    $this->actingAs($this->sweep->portalUser, 'client');

    expect(ClientPortalScope::isActive())->toBeTrue();

    // Đọc bằng DB::table, không qua model: phiên cổng vừa mở BẬT ClientPortalScope trên Deadline… nên
    // `Deadline::max('id')` lúc này chỉ thấy mốc đã công bố của khách — chính hiện tượng test này canh.
    $before = collect(['activity_log', 'deadlines', 'communication_logs', 'stage_log_drafts', 'client_request_reply_drafts'])
        ->mapWithKeys(fn (string $table): array => [$table => (int) DB::table($table)->max('id')])
        ->all();
    $before['activity'] = $before['activity_log'];

    DB::beginTransaction();
    $portal = McpSweep::run($this, $this->token, $plan);

    $foreignCausers = Activity::query()->where('id', '>', $before['activity'])->get()
        ->reject(fn (Activity $row): bool => $row->causer_type === $admin->getMorphClass() && (int) $row->causer_id === $admin->id)
        ->map(fn (Activity $row): string => "{$row->event}: causer {$row->causer_type}#{$row->causer_id}")
        ->values()->all();

    $created = [];
    $foreignCreators = [];

    foreach (['deadlines', 'communication_logs', 'stage_log_drafts', 'client_request_reply_drafts'] as $table) {
        $rows = DB::table($table)->where('id', '>', $before[$table])->get(['id', 'created_by']);
        $created[$table] = $rows->count();

        foreach ($rows as $row) {
            if ((int) $row->created_by !== $admin->id) {
                $foreignCreators[] = "{$table}#{$row->id}: created_by {$row->created_by}";
            }
        }
    }

    $toolRows = Activity::query()->where('id', '>', $before['activity'])->where('event', 'mcp_tool_called')->count();
    DB::rollBack();

    // Tiền đề: lượt 2 có ghi thật (bốn loại bản ghi) và có nhật ký — nếu không, hai khẳng định trên rỗng.
    expect($created)->each->toBeGreaterThan(0)
        ->and($toolRows)->toBe(count($portal));

    expect($foreignCausers)->toBe([])
        ->and($foreignCreators)->toBe([]);

    expect(count($portal))->toBe(count($plain));

    $differences = [];

    foreach ($plain as $i => $entry) {
        $a = sweepNormalized($entry, $writes[$entry['tool']]);
        $b = sweepNormalized($portal[$i], $writes[$entry['tool']]);

        if ($a !== $b) {
            $differences[] = $entry['tool'].'('.json_encode($entry['arguments']).'): '.json_encode($a['body'], JSON_UNESCAPED_UNICODE).' ≠ '.json_encode($b['body'], JSON_UNESCAPED_UNICODE);
        }
    }

    expect($differences)->toBe([]);
});
