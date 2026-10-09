<?php

use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterPresenter;
use App\Support\Mcp\Presenters\StageLogPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 11 — tool `list_matter_updates` (bảng tool 6 [DC:52])
|--------------------------------------------------------------------------
| Dòng tiến độ của MỘT vụ trong tập `McpMatterScope`, mới nhất trước theo ngày xảy ra (như tab "Tiến
| độ"): ngày, giai đoạn từ → tới (nhãn nội bộ, kể cả giai đoạn đã xoá mềm), ba trường viết cho khách,
| đã công bố chưa, khách xem lần đầu lúc nào, và cờ `has_internal_note` — không bao giờ nội dung ghi
| chú (R4). `StageLogPolicy::viewAny` + `view` từng dòng. Phân trang `limit`/`cursor` theo khoá
| (`occurred_at`, `id`).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{matter: array<string, mixed>, updates: list<array<string, mixed>>, next_cursor: ?string} */
function matterUpdates(string $token, Matter $matter, array $arguments = []): array
{
    return McpToolCall::structured(test(), $token, 'list_matter_updates', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), ...$arguments]);
}

/** @return list<string> */
function matterUpdateIds(array $out): array
{
    return array_column($out['updates'], 'id');
}

function muLogId(StageLog $log): string
{
    return McpIds::encode(McpIds::UPDATE, $log->id);
}

it('trả dòng tiến độ của vụ, mới nhất trước theo ngày xảy ra (không theo id), đủ trường của StageLogPresenter, nhãn giai đoạn nội bộ, khách xem lần đầu lúc nào, url về tab Tiến độ', function () {
    $matter = $this->world->matter;
    $stages = $matter->matterType->stages()->orderBy('sort_order')->get();

    // Tạo dòng MỚI hơn trước (id nhỏ hơn): thứ tự theo id sẽ ngược với thứ tự theo ngày xảy ra.
    $newer = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'from_stage' => $stages[0]->key,
        'to_stage' => $stages[1]->key,
        'occurred_at' => now()->subHour(),
        'public_content' => 'Văn phòng đã nộp đơn khởi kiện.',
        'next_step' => 'Chờ toà thụ lý.',
        'client_action' => 'Chuẩn bị bản sao CCCD.',
        'expected_next_update_at' => today()->addDays(10)->toDateString(),
        'is_published' => true,
        'published_at' => now()->subMinutes(30),
        'internal_note' => null,
    ]);
    $older = StageLog::factory()->create([
        'matter_id' => $matter->id,
        'from_stage' => null,
        'to_stage' => $stages[0]->key,
        'occurred_at' => now()->subDays(3),
        'is_published' => false,
        'published_at' => null,
    ]);
    $firstViewedAt = now()->subMinutes(20)->startOfSecond();
    StageLogView::factory()->create(['stage_log_id' => $newer->id, 'viewed_at' => $firstViewedAt->copy()->addMinutes(15)]);
    StageLogView::factory()->create(['stage_log_id' => $newer->id, 'viewed_at' => $firstViewedAt]);
    // Dòng của một vụ KHÁC cũng trong tập MCP của người gọi: không thuộc kết quả của vụ này.
    StageLog::factory()->create([
        'matter_id' => Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id])->id,
        'occurred_at' => now(),
    ]);

    $out = matterUpdates($this->token, $matter);

    expect(array_keys($out))->toBe(['matter', 'updates', 'next_cursor'])
        ->and($out['matter'])->toBe(MatterPresenter::reference($matter))
        ->and(matterUpdateIds($out))->toBe([muLogId($newer), muLogId($older)])
        ->and($out['next_cursor'])->toBeNull();

    [$first, $second] = $out['updates'];

    expect(array_keys($first))->toBe(StageLogPresenter::FIELDS)
        ->and($first['matter_id'])->toBe(McpIds::encode(McpIds::MATTER, $matter->id))
        ->and($first['from_stage'])->toBe($stages[0]->key)
        ->and($first['from_stage_label'])->toBe($stages[0]->label)
        ->and($first['to_stage'])->toBe($stages[1]->key)
        ->and($first['to_stage_label'])->toBe($stages[1]->label)
        ->and($first['public_content'])->toBe('Văn phòng đã nộp đơn khởi kiện.')
        ->and($first['next_step'])->toBe('Chờ toà thụ lý.')
        ->and($first['client_action'])->toBe('Chuẩn bị bản sao CCCD.')
        ->and($first['expected_next_update_at'])->toBe(today()->addDays(10)->toDateString())
        ->and($first['is_published'])->toBeTrue()
        ->and($first['client_viewed_at'])->toBe($firstViewedAt->toIso8601String())
        ->and($first['has_internal_note'])->toBeFalse()
        ->and($first['url'])->toBe(AdminUrls::stageLog($newer))
        ->and($first['url'])->toStartWith('http');

    // Dòng chưa công bố vẫn có (nhân sự thấy nó trên web); khách chưa xem thì null.
    expect($second['is_published'])->toBeFalse()
        ->and($second['from_stage'])->toBeNull()
        ->and($second['from_stage_label'])->toBeNull()
        ->and($second['client_viewed_at'])->toBeNull();
});

it('R4: dòng có internal_note trả has_internal_note true và không có nội dung ghi chú ở đâu trong phản hồi; cặp dương: dòng không có ghi chú thì false', function () {
    $matter = $this->world->matter;
    $withNote = StageLog::factory()->create(['matter_id' => $matter->id, 'internal_note' => 'SECRET-INTERNAL-NOTE-Ocelotra', 'occurred_at' => now()->subHour()]);
    $withoutNote = StageLog::factory()->create(['matter_id' => $matter->id, 'internal_note' => null, 'occurred_at' => now()->subHours(2)]);
    $blankNote = StageLog::factory()->create(['matter_id' => $matter->id, 'internal_note' => '', 'occurred_at' => now()->subHours(3)]);

    $response = McpToolCall::call($this, $this->token, 'list_matter_updates', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]);
    $flags = collect(matterUpdates($this->token, $matter)['updates'])->pluck('has_internal_note', 'id')->all();

    expect($flags)->toBe([
        muLogId($withNote) => true,
        muLogId($withoutNote) => false,
        muLogId($blankNote) => false,
    ])
        ->and($response->getContent())->not->toContain('SECRET-INTERNAL-NOTE-Ocelotra')
        ->and($response->getContent())->not->toContain('\"internal_note\"')
        ->and($response->getContent())->not->toContain('"internal_note"');
});

it('nhãn giai đoạn của lịch sử đọc cả giai đoạn đã xoá mềm (như tab Tiến độ); khoá không còn cấu hình nào thì nhãn null, khoá vẫn trả', function () {
    $matter = $this->world->matter;
    $retired = MatterTypeStage::factory()->create(['matter_type_id' => $matter->matter_type_id, 'key' => 'giai_doan_cu', 'label' => 'Giai đoạn đã bỏ']);
    $retired->delete();

    StageLog::factory()->create(['matter_id' => $matter->id, 'from_stage' => 'khoa_khong_ton_tai', 'to_stage' => 'giai_doan_cu']);

    $row = matterUpdates($this->token, $matter)['updates'][0];

    expect($row['to_stage'])->toBe('giai_doan_cu')
        ->and($row['to_stage_label'])->toBe('Giai đoạn đã bỏ')
        ->and($row['from_stage'])->toBe('khoa_khong_ton_tai')
        ->and($row['from_stage_label'])->toBeNull();

    // Giai đoạn còn sống cùng khoá thắng dòng đã xoá (như MatterType::stageIncludingTrashed()).
    MatterTypeStage::factory()->create(['matter_type_id' => $matter->matter_type_id, 'key' => 'giai_doan_cu', 'label' => 'Giai đoạn tạo lại']);

    expect(matterUpdates($this->token, $matter)['updates'][0]['to_stage_label'])->toBe('Giai đoạn tạo lại');
});

it('R3: vụ đội khác, vụ hạn chế của chính mình, vụ denied và id không tồn tại cho CÙNG một phản hồi; id sai loại hay sai định dạng cũng vậy', function () {
    $call = fn (string $id) => McpToolCall::call($this, $this->token, 'list_matter_updates', ['matter_id' => $id])->json('result');

    $baseline = $call(McpIds::encode(McpIds::MATTER, 999999));

    expect($baseline['content'])->toBe([['type' => 'text', 'text' => __('mcp.tool_errors.not_found')]])
        ->and($baseline['isError'])->toBeTrue()
        ->and($baseline)->not->toHaveKey('structuredContent');

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        StageLog::factory()->create(['matter_id' => $matter->id]);

        expect($call(McpIds::encode(McpIds::MATTER, $matter->id)))->toBe($baseline, $case);
    }

    foreach (['matter_0', 'request_'.$this->world->matter->id, (string) $this->world->matter->id, 'Matter_'.$this->world->matter->id] as $bad) {
        expect($call($bad))->toBe($baseline, $bad);
    }

    // Cặp dương: người thấy vụ đội khác qua MCP đọc được tiến độ của nó.
    expect(matterUpdates(McpOAuth::accessToken($this, $this->world->outsider), $this->world->otherTeam)['updates'])->toHaveCount(1);
});

it('thành viên đội (trợ lý) đọc được; kế toán bị EnsureMcpAccess từ chối (401, không phản hồi tool)', function () {
    StageLog::factory()->create(['matter_id' => $this->world->matter->id]);

    expect(matterUpdates(McpOAuth::accessToken($this, $this->world->assistant), $this->world->matter)['updates'])->toHaveCount(1);

    McpToolCall::refused($this, McpOAuth::accessToken($this, $this->world->accountant), 'list_matter_updates', [
        'matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id),
    ]);
});

it('phân trang: limit mặc định 10; limit quá 25 bị kẹp về 25; cursor dẫn sang trang kế theo (ngày xảy ra, id) kể cả khi hai dòng cùng giờ đứng hai bên ranh giới trang', function () {
    $matter = $this->world->matter;
    $base = now()->subDay()->startOfMinute();

    // Từng cặp dòng cùng một thời điểm: dòng 24 và 25 (đếm từ 0) cùng giờ, đứng đúng hai bên ranh giới 25.
    foreach (range(0, 29) as $i) {
        StageLog::factory()->create(['matter_id' => $matter->id, 'occurred_at' => $base->copy()->subMinutes(intdiv($i, 2))]);
    }

    $all = StageLog::query()->where('matter_id', $matter->id)->orderByDesc('occurred_at')->orderByDesc('id')->get()->map(fn (StageLog $log) => muLogId($log))->all();

    $default = matterUpdates($this->token, $matter);
    expect(matterUpdateIds($default))->toBe(array_slice($all, 0, 10))
        ->and($default['next_cursor'])->toBeString();

    $first = matterUpdates($this->token, $matter, ['limit' => 100]);
    expect(matterUpdateIds($first))->toBe(array_slice($all, 0, 25));

    $second = matterUpdates($this->token, $matter, ['limit' => 100, 'cursor' => $first['next_cursor']]);
    expect(matterUpdateIds($second))->toBe(array_slice($all, 25))
        ->and($second['next_cursor'])->toBeNull();

    // Đi hết bằng trang 7 dòng: không trùng, không sót.
    $seen = [];
    $cursor = null;
    do {
        $page = matterUpdates($this->token, $matter, ['limit' => 7, ...($cursor === null ? [] : ['cursor' => $cursor])]);
        $seen = [...$seen, ...matterUpdateIds($page)];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($seen) <= count($all));

    expect($seen)->toBe($all);
});

it('cursor của người A không mở trang của người B (B thấy đúng vụ đó); cursor gắn với vụ: dùng cho vụ khác thì không được', function () {
    $matter = $this->world->matter;
    StageLog::factory()->count(12)->create(['matter_id' => $matter->id]);
    $secondMatter = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);
    StageLog::factory()->count(12)->create(['matter_id' => $secondMatter->id]);

    $cursorOfA = matterUpdates($this->token, $matter, ['limit' => 5])['next_cursor'];

    expect(McpToolCall::error($this, McpOAuth::accessToken($this, $this->world->admin), 'list_matter_updates', [
        'matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), 'limit' => 5, 'cursor' => $cursorOfA,
    ]))->toBe(__('mcp.tool_errors.invalid_cursor'))
        ->and(McpToolCall::error($this, $this->token, 'list_matter_updates', [
            'matter_id' => McpIds::encode(McpIds::MATTER, $secondMatter->id), 'limit' => 5, 'cursor' => $cursorOfA,
        ]))->toBe(__('mcp.tool_errors.invalid_cursor'))
        ->and(McpToolCall::error($this, $this->token, 'list_matter_updates', [
            'matter_id' => McpIds::encode(McpIds::MATTER, $matter->id), 'limit' => 5, 'cursor' => 'khong-phai-cursor',
        ]))->toBe(__('mcp.tool_errors.invalid_cursor'));

    // Cặp dương: A dùng lại chính cursor đó thì nhận trang kế.
    expect(matterUpdates($this->token, $matter, ['limit' => 5, 'cursor' => $cursorOfA])['updates'])->toHaveCount(5);
});

it('kế thừa policy web: Gate view từ chối một dòng thì dòng đó vắng mặt; Gate view từ chối vụ thì "Không tìm thấy"; cặp dương trước', function () {
    $matter = $this->world->matter;
    $hidden = StageLog::factory()->create(['matter_id' => $matter->id, 'occurred_at' => now()->subHour()]);
    $shown = StageLog::factory()->create(['matter_id' => $matter->id, 'occurred_at' => now()->subHours(2)]);

    expect(matterUpdateIds(matterUpdates($this->token, $matter)))->toBe([muLogId($hidden), muLogId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof StageLog
        && $arguments[0]->is($hidden) ? false : null);

    expect(matterUpdateIds(matterUpdates($this->token, $matter)))->toBe([muLogId($shown)]);

    // Trang mà dòng duy nhất bị policy bỏ vẫn mang cursor: trang kế bắt đầu SAU dòng đó, không dừng ở đây.
    $first = matterUpdates($this->token, $matter, ['limit' => 1]);

    expect($first['updates'])->toBe([])
        ->and($first['next_cursor'])->toBeString()
        ->and(matterUpdateIds(matterUpdates($this->token, $matter, ['limit' => 1, 'cursor' => $first['next_cursor']])))->toBe([muLogId($shown)]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Matter
        && $arguments[0]->is($matter) ? false : null);

    expect(McpToolCall::error($this, $this->token, 'list_matter_updates', ['matter_id' => McpIds::encode(McpIds::MATTER, $matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('kế thừa policy web: StageLogPolicy::viewAny từ chối thì "Không tìm thấy"', function () {
    StageLog::factory()->create(['matter_id' => $this->world->matter->id]);

    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'viewAny'
        && ($arguments[0] ?? null) === StageLog::class ? false : null);

    expect(McpToolCall::error($this, $this->token, 'list_matter_updates', ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id)]))
        ->toBe(__('mcp.tool_errors.not_found'));
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt dòng chưa công bố hay lượt xem của khách', function () {
    $matter = $this->world->matter;
    $unpublished = StageLog::factory()->create(['matter_id' => $matter->id, 'is_published' => false, 'published_at' => null, 'occurred_at' => now()->subHour()]);
    $published = StageLog::factory()->create(['matter_id' => $matter->id, 'occurred_at' => now()->subHours(2)]);
    StageLogView::factory()->create(['stage_log_id' => $published->id]);

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $out = matterUpdates($this->token, $matter);

    expect(matterUpdateIds($out))->toBe([muLogId($unpublished), muLogId($published)])
        ->and($out['updates'][1]['client_viewed_at'])->not->toBeNull();
});

it('matter_id là tham số bắt buộc; tham số sai kiểu bị từ chối bằng thông điệp kiểm tra, không phải "Không tìm thấy"', function () {
    expect(McpToolCall::error($this, $this->token, 'list_matter_updates'))->not->toBe(__('mcp.tool_errors.not_found'));

    foreach ([['limit' => 'nhieu'], ['cursor' => str_repeat('x', 1025)], ['matter_id' => str_repeat('9', 33)]] as $arguments) {
        expect(McpToolCall::error($this, $this->token, 'list_matter_updates', ['matter_id' => McpIds::encode(McpIds::MATTER, $this->world->matter->id), ...$arguments]))
            ->not->toBe(__('mcp.tool_errors.not_found'));
    }
});
