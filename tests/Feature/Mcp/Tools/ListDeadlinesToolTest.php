<?php

use App\Enums\DeadlineSeverity;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpCursor;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\DeadlinePresenter;
use App\Support\Mcp\Presenters\MatterPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — tool `list_deadlines` (bảng tool 7 [DC:53])
|--------------------------------------------------------------------------
| Mặc định là "mốc tuần này" của người gọi: mốc CHƯA xong do chính người đó phụ trách, hạn tới hết 7
| ngày tới (cùng cửa sổ với widget "Mốc thời hạn 7 ngày tới"), quá hạn lên đầu, chỉ vụ đang mở. Lọc
| được theo vụ, khoảng ngày, mức độ, người phụ trách, kèm mốc đã xong. Mọi mốc thuộc vụ ngoài tập
| `McpMatterScope` vắng mặt — kể cả vụ hạn chế mà chính người gọi phụ trách (R3). `DeadlinePolicy::view`
| từng dòng. Phân trang theo khoá (`due_date`, `id`).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{deadlines: list<array<string, mixed>>, due_from: ?string, due_to: ?string, responsible: string, next_cursor: ?string} */
function listDeadlines(string $token, array $arguments = []): array
{
    return McpToolCall::structured(test(), $token, 'list_deadlines', $arguments);
}

/** @return list<string> */
function deadlineIds(array $out): array
{
    return array_column($out['deadlines'], 'id');
}

function dlId(Deadline $deadline): string
{
    return McpIds::encode(McpIds::DEADLINE, $deadline->id);
}

function dlDue(Matter $matter, User $responsible, int $days, array $extra = []): Deadline
{
    return Deadline::factory()->create([
        'matter_id' => $matter->id,
        'responsible_user_id' => $responsible->id,
        'due_date' => today()->addDays($days)->toDateString(),
        ...$extra,
    ]);
}

it('mặc định: mốc CỦA TÔI, chưa xong, hạn tới hết 7 ngày tới, quá hạn lên đầu; mốc người khác, mốc ngày thứ 8, mốc đã xong, mốc đã xoá thì không', function () {
    $matter = $this->world->matter;
    $lead = $this->world->lead;

    $today = dlDue($matter, $lead, 0, ['name' => 'Hôm nay']);
    $edge = dlDue($matter, $lead, 7, ['name' => 'Ngày thứ bảy']);
    $overdue = dlDue($matter, $lead, -3, ['name' => 'Quá hạn', 'severity' => DeadlineSeverity::Critical]);
    dlDue($matter, $lead, 8, ['name' => 'Ngày thứ tám']);
    dlDue($matter, $lead, -1, ['name' => 'Đã xong', 'is_completed' => true, 'completed_at' => now()]);
    dlDue($matter, $lead, 2, ['name' => 'Đã xoá'])->delete();
    dlDue($matter, $this->world->assistant, 1, ['name' => 'Của trợ lý']);

    $out = listDeadlines($this->token);

    expect(array_keys($out))->toBe(['deadlines', 'due_from', 'due_to', 'responsible', 'next_cursor'])
        ->and(deadlineIds($out))->toBe([dlId($overdue), dlId($today), dlId($edge)])
        ->and($out['due_from'])->toBeNull()
        ->and($out['due_to'])->toBe(today()->addDays(7)->toDateString())
        ->and($out['next_cursor'])->toBeNull();

    $first = $out['deadlines'][0];

    expect(array_keys($first))->toBe(DeadlinePresenter::FIELDS)
        ->and($first['matter'])->toBe(MatterPresenter::reference($matter))
        ->and($first['name'])->toBe('Quá hạn')
        ->and($first['due_date'])->toBe(today()->subDays(3)->toDateString())
        ->and($first['severity'])->toBe(DeadlineSeverity::Critical->value)
        ->and($first['severity_label'])->toBe(DeadlineSeverity::Critical->label())
        ->and($first['responsible']['name'])->toBe('Luật sư Phụ Trách')
        ->and($first['is_completed'])->toBeFalse()
        ->and($first['url'])->toBe(AdminUrls::deadline($overdue));
});

it('R3: mốc thuộc vụ hạn chế MÀ TÔI PHỤ TRÁCH vẫn vắng mặt, cũng như vụ denied và vụ đội khác dù mốc giao cho tôi; cặp dương: vụ hết hạn chế thì mốc hiện', function () {
    $lead = $this->world->lead;
    $own = dlDue($this->world->matter, $lead, 1);
    $restricted = dlDue($this->world->restricted, $lead, 1);
    dlDue($this->world->denied, $lead, 1);
    dlDue($this->world->otherTeam, $lead, 1);

    expect(deadlineIds(listDeadlines($this->token)))->toBe([dlId($own)])
        ->and(deadlineIds(listDeadlines($this->token, ['responsible' => 'any', 'include_completed' => true, 'from' => today()->subYear()->toDateString(), 'to' => today()->addYear()->toDateString()])))->toBe([dlId($own)]);

    $this->world->restricted->forceFill(['confidentiality' => 'normal'])->save();

    expect(deadlineIds(listDeadlines($this->token)))->toBe([dlId($own), dlId($restricted)]);
});

it('kế toán không nhận gì: EnsureMcpAccess từ chối (401, không phản hồi tool), kể cả khi hỏi mọi người phụ trách và mọi ngày hay lọc theo vụ', function () {
    $accountant = $this->world->accountant;
    dlDue($this->world->matter, $accountant, 1);
    dlDue($this->world->matter, $this->world->lead, 1);
    $token = McpOAuth::accessToken($this, $accountant);

    McpToolCall::refused($this, $token, 'list_deadlines');
    McpToolCall::refused($this, $token, 'list_deadlines', ['responsible' => 'any', 'from' => today()->subYear()->toDateString()]);
    McpToolCall::refused($this, $token, 'list_deadlines', ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)]);
});

it('lọc theo người phụ trách: any là mọi người, user_… là đúng người đó; responsible me là mặc định', function () {
    $matter = $this->world->matter;
    $mine = dlDue($matter, $this->world->lead, 1);
    $assistants = dlDue($matter, $this->world->assistant, 2);

    expect(deadlineIds(listDeadlines($this->token, ['responsible' => 'me'])))->toBe([dlId($mine)])
        ->and(deadlineIds(listDeadlines($this->token, ['responsible' => 'any'])))->toBe([dlId($mine), dlId($assistants)])
        ->and(deadlineIds(listDeadlines($this->token, ['responsible' => McpIds::encode(McpIds::USER, $this->world->assistant->id)])))->toBe([dlId($assistants)])
        ->and(deadlineIds(listDeadlines($this->token, ['responsible' => McpIds::encode(McpIds::USER, 999999)])))->toBe([]);
});

/**
 * Rà soát Task 11 r1: hỏi "mốc của matter_12?" mà chỉ đưa `matter_id` thì mặc định "của tôi" và cửa sổ
 * 7 ngày VẪN áp. Đầu ra nói rõ bộ lọc người phụ trách đã áp (như `due_from`/`due_to` nói khoảng hạn), để
 * AI không báo "vụ không có mốc nào" khi trợ lý đang giữ năm mốc.
 */
it('đầu ra nói rõ bộ lọc người phụ trách đã áp: me khi bỏ trống (kể cả khi chỉ lọc theo vụ), any, hay đúng user_… đã hỏi', function () {
    $matter = $this->world->matter;
    dlDue($matter, $this->world->assistant, 2);
    $assistant = McpIds::encode(McpIds::USER, $this->world->assistant->id);
    $matterId = McpIds::encode(McpIds::MATTER, $matter->id);

    $onlyMatter = listDeadlines($this->token, ['matter_id' => $matterId]);

    expect($onlyMatter['responsible'])->toBe('me')
        ->and($onlyMatter['deadlines'])->toBe([])
        ->and($onlyMatter['due_to'])->toBe(today()->addDays(7)->toDateString())
        ->and(listDeadlines($this->token)['responsible'])->toBe('me')
        ->and(listDeadlines($this->token, ['responsible' => 'me'])['responsible'])->toBe('me')
        ->and(listDeadlines($this->token, ['matter_id' => $matterId, 'responsible' => 'any'])['responsible'])->toBe('any')
        ->and(listDeadlines($this->token, ['matter_id' => $matterId, 'responsible' => 'any'])['deadlines'])->toHaveCount(1)
        ->and(listDeadlines($this->token, ['responsible' => $assistant])['responsible'])->toBe($assistant);
});

it('lọc theo khoảng ngày: chỉ from thì không có cận trên, chỉ to thì gồm cả quá hạn, có cả hai thì đúng khoảng (gồm hai đầu)', function () {
    $matter = $this->world->matter;
    $lead = $this->world->lead;
    $overdue = dlDue($matter, $lead, -5);
    $soon = dlDue($matter, $lead, 3);
    $later = dlDue($matter, $lead, 10);
    $far = dlDue($matter, $lead, 40);

    $from = today()->addDays(3)->toDateString();
    $to = today()->addDays(10)->toDateString();

    expect(listDeadlines($this->token, ['from' => $from, 'to' => $to]))->toMatchArray(['due_from' => $from, 'due_to' => $to])
        ->and(deadlineIds(listDeadlines($this->token, ['from' => $from, 'to' => $to])))->toBe([dlId($soon), dlId($later)])
        ->and(deadlineIds(listDeadlines($this->token, ['from' => $from])))->toBe([dlId($soon), dlId($later), dlId($far)])
        ->and(listDeadlines($this->token, ['from' => $from])['due_to'])->toBeNull()
        ->and(deadlineIds(listDeadlines($this->token, ['to' => $to])))->toBe([dlId($overdue), dlId($soon), dlId($later)])
        ->and(listDeadlines($this->token, ['to' => $to])['due_from'])->toBeNull();
});

it('lọc theo mức độ, và include_completed thêm mốc đã xong trong cùng cửa sổ', function () {
    $matter = $this->world->matter;
    $lead = $this->world->lead;
    $critical = dlDue($matter, $lead, 1, ['severity' => DeadlineSeverity::Critical]);
    $normal = dlDue($matter, $lead, 2);
    $done = dlDue($matter, $lead, 3, ['is_completed' => true, 'completed_at' => now()]);

    expect(deadlineIds(listDeadlines($this->token, ['severity' => 'critical'])))->toBe([dlId($critical)])
        ->and(deadlineIds(listDeadlines($this->token, ['severity' => 'normal'])))->toBe([dlId($normal)])
        ->and(deadlineIds(listDeadlines($this->token)))->toBe([dlId($critical), dlId($normal)])
        ->and(deadlineIds(listDeadlines($this->token, ['include_completed' => true])))->toBe([dlId($critical), dlId($normal), dlId($done)])
        ->and(listDeadlines($this->token, ['include_completed' => true])['deadlines'][2]['is_completed'])->toBeTrue();
});

it('vụ đã kết thúc: mốc vắng mặt khỏi danh sách chung (như widget và CheckDeadlines), nhưng có khi lọc đúng vụ đó', function () {
    $closed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id, 'closed_at' => now()->subDay()]);
    $onClosed = dlDue($closed, $this->world->lead, 1);
    $onOpen = dlDue($this->world->matter, $this->world->lead, 2);

    expect(deadlineIds(listDeadlines($this->token)))->toBe([dlId($onOpen)])
        ->and(deadlineIds(listDeadlines($this->token, ['matter_id' => McpIds::encode(McpIds::MATTER, $closed->id)])))->toBe([dlId($onClosed)])
        ->and(deadlineIds(listDeadlines($this->token, ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)])))->toBe([dlId($onOpen)]);
});

it('R3 khi lọc theo vụ: vụ đội khác, vụ hạn chế của chính mình, vụ denied và id không tồn tại cho CÙNG một phản hồi', function () {
    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'list_deadlines', ['matter_id' => $id])->json('result');

    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue();

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        dlDue($matter, $this->world->lead, 1);

        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    foreach (['matter_0', 'deadline_1', (string) $this->world->matter->id] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    // Cặp dương: người phụ trách đội kia thấy mốc của vụ đó.
    expect(listDeadlines(McpOAuth::accessToken($this, $this->world->outsider), [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->otherTeam->id), 'responsible' => 'any',
    ])['deadlines'])->toHaveCount(1);
});

it('phân trang: limit mặc định 10; limit quá 25 bị kẹp về 25; cursor đi theo (hạn, id) kể cả khi nhiều mốc cùng hạn đứng hai bên ranh giới trang', function () {
    $matter = $this->world->matter;
    // 30 mốc, mỗi hạn ba mốc (ba hạn quá khứ, bảy hạn tới): ranh giới 25 rơi giữa một nhóm cùng hạn.
    foreach (range(0, 29) as $i) {
        dlDue($matter, $this->world->lead, intdiv($i, 3) - 3);
    }

    $all = Deadline::query()->orderBy('due_date')->orderBy('id')->get()->map(fn (Deadline $deadline) => dlId($deadline))->all();

    $default = listDeadlines($this->token);
    expect(deadlineIds($default))->toBe(array_slice($all, 0, 10))
        ->and($default['next_cursor'])->toBeString();

    $first = listDeadlines($this->token, ['limit' => 100]);
    expect(deadlineIds($first))->toBe(array_slice($all, 0, 25));

    $second = listDeadlines($this->token, ['limit' => 100, 'cursor' => $first['next_cursor']]);
    expect(deadlineIds($second))->toBe(array_slice($all, 25))
        ->and($second['next_cursor'])->toBeNull();

    $seen = [];
    $cursor = null;
    do {
        $page = listDeadlines($this->token, ['limit' => 4, ...($cursor === null ? [] : ['cursor' => $cursor])]);
        $seen = [...$seen, ...deadlineIds($page)];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($seen) <= count($all));

    expect($seen)->toBe($all);
});

it('cursor của người A không mở trang của người B; cursor gắn với bộ lọc; cursor sửa, rác, của tool khác, thiếu vị trí cũng không dùng được', function () {
    $lead = $this->world->lead;
    foreach (range(1, 12) as $i) {
        dlDue($this->world->matter, $lead, $i % 7);
    }

    $cursor = listDeadlines($this->token, ['limit' => 5, 'responsible' => 'any'])['next_cursor'];

    // B là admin: tập MCP của B chứa vụ của A, và responsible=any ra đúng các mốc ấy.
    expect(McpToolCall::error($this, McpOAuth::accessToken($this, $this->world->admin), 'list_deadlines', ['limit' => 5, 'responsible' => 'any', 'cursor' => $cursor]))
        ->toBe(__('mcp.tool_errors.invalid_cursor'));

    $filters = ['matter' => null, 'from' => null, 'to' => null, 'severity' => null, 'responsible' => null, 'include_completed' => false];
    ksort($filters);
    $handBuilt = fn (array $payload): string => Crypt::encryptString((string) json_encode([
        'v' => 1, 'u' => $lead->id, 't' => 'list_deadlines', 'f' => hash('sha256', (string) json_encode($filters)), ...$payload,
    ]));
    $fifth = Deadline::query()->orderBy('due_date')->orderBy('id')->skip(4)->firstOrFail();

    // Cặp dương: đúng người, đúng tool, đúng bộ lọc, có vị trí thì dùng được — cả cursor tự dựng.
    expect(listDeadlines($this->token, ['limit' => 5, 'responsible' => 'any', 'cursor' => $cursor])['deadlines'])->toHaveCount(5)
        ->and(listDeadlines($this->token, ['limit' => 5, 'responsible' => 'any', 'cursor' => $handBuilt(['s' => $fifth->getRawOriginal('due_date'), 'a' => $fifth->id])])['deadlines'])->toHaveCount(5);

    foreach ([
        'filter changed' => ['limit' => 5, 'responsible' => 'any', 'severity' => 'normal', 'cursor' => $cursor],
        'responsible changed' => ['limit' => 5, 'cursor' => $cursor],
        'tampered' => ['limit' => 5, 'responsible' => 'any', 'cursor' => substr($cursor, 0, -4).'AAAA'],
        'garbage' => ['limit' => 5, 'responsible' => 'any', 'cursor' => 'khong-phai-cursor'],
        'other tool' => ['limit' => 5, 'responsible' => 'any', 'cursor' => McpCursor::encodePosition($lead, 'list_documents', $filters, '2026-01-01', 1)],
        'id-only cursor' => ['limit' => 5, 'responsible' => 'any', 'cursor' => McpCursor::encode($lead, 'list_deadlines', $filters, $fifth->id)],
        'sort not a string' => ['limit' => 5, 'responsible' => 'any', 'cursor' => $handBuilt(['s' => 20260101, 'a' => $fifth->id])],
        'id zero' => ['limit' => 5, 'responsible' => 'any', 'cursor' => $handBuilt(['s' => $fifth->getRawOriginal('due_date'), 'a' => 0])],
    ] as $case => $arguments) {
        expect(McpToolCall::error($this, $this->token, 'list_deadlines', $arguments))->toBe(__('mcp.tool_errors.invalid_cursor'), $case);
    }
});

it('kế thừa policy web: Gate view từ chối một mốc thì mốc đó vắng mặt; cặp dương trước', function () {
    $hidden = dlDue($this->world->matter, $this->world->lead, 1);
    $shown = dlDue($this->world->matter, $this->world->lead, 2);

    expect(deadlineIds(listDeadlines($this->token)))->toBe([dlId($hidden), dlId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Deadline
        && $arguments[0]->is($hidden) ? false : null);

    expect(deadlineIds(listDeadlines($this->token)))->toBe([dlId($shown)]);
});

it('kế thừa policy web khi lọc theo vụ: Gate view từ chối vụ thì "Không tìm thấy"', function () {
    $matter = $this->world->matter;
    dlDue($matter, $this->world->lead, 1);

    expect(listDeadlines($this->token, ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)])['deadlines'])->toHaveCount(1);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'list_deadlines', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt mốc chưa công bố hay vụ cha của mốc', function () {
    $deadline = dlDue($this->world->matter, $this->world->lead, 1, ['is_published' => false]);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $out = listDeadlines($this->token);

    expect(deadlineIds($out))->toBe([dlId($deadline)])
        ->and($out['deadlines'][0]['matter'])->toBe(MatterPresenter::reference($this->world->matter));
});

it('người phụ trách đã nghỉ việc (xoá mềm) vẫn hiện tên, như tab Mốc thời hạn và widget', function () {
    $departed = User::factory()->create(['name' => 'Luật sư Đã Nghỉ']);
    $deadline = dlDue($this->world->matter, $departed, 1);
    $departed->delete();

    $out = listDeadlines($this->token, ['responsible' => 'any']);

    expect(deadlineIds($out))->toBe([dlId($deadline)])
        ->and($out['deadlines'][0]['responsible']['name'])->toBe('Luật sư Đã Nghỉ');
});

it('tham số sai bị từ chối bằng thông điệp kiểm tra, không phải "Không tìm thấy"', function () {
    foreach ([
        ['from' => '04/10/2026'],
        ['to' => '2026-13-01'],
        ['from' => today()->toDateString(), 'to' => today()->subDay()->toDateString()],
        ['severity' => 'urgent'],
        ['responsible' => 'someone'],
        ['responsible' => 'user_0'],
        ['include_completed' => 'co'],
        ['limit' => 'nhieu'],
    ] as $arguments) {
        expect(McpToolCall::error($this, $this->token, 'list_deadlines', $arguments))->not->toBe(__('mcp.tool_errors.not_found'), json_encode($arguments));
    }
});
