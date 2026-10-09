<?php

use App\Enums\DocumentGroup;
use App\Enums\MatterRole;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\MatterType;
use App\Models\User;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpCursor;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\Presenters\MatterPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\McpOAuth;
use Tests\Support\McpReadWorld;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 10 — tool `search_matters`
|--------------------------------------------------------------------------
| Lọc theo chữ (mã, tiêu đề, tên khách, số thụ lý — bốn nguồn R10 cho phép của `SearchMatters`, giao
| với `McpMatterScope`), loại vụ, giai đoạn, "vụ tôi phụ trách", đang mở. Mỗi dòng: `MatterPresenter::row`
| cộng mốc chưa hoàn thành gần nhất. Phân trang: `limit` mặc định 10, kẹp về 25, `cursor` gắn với
| người, tool và bộ lọc.
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->world = McpReadWorld::build();
    $this->token = McpOAuth::accessToken($this, $this->world->lead);
});

/** @return array{matters: list<array<string, mixed>>, next_cursor: ?string} */
function searchMatters(string $token, array $arguments = []): array
{
    return McpToolCall::structured(test(), $token, 'search_matters', $arguments);
}

/** @return list<string> */
function searchMatterIds(string $token, array $arguments = []): array
{
    return array_column(searchMatters($token, $arguments)['matters'], 'id');
}

function smId(Matter $matter): string
{
    return McpIds::encode(McpIds::MATTER, $matter->id);
}

it('không bộ lọc: mọi vụ trong tập MCP của người gọi; mỗi dòng là MatterPresenter::row cộng mốc gần nhất', function () {
    $matter = $this->world->matter;
    Deadline::factory()->create(['matter_id' => $matter->id, 'responsible_user_id' => $this->world->lead->id, 'name' => 'Sau', 'due_date' => today()->addDays(9)->toDateString()]);
    $first = Deadline::factory()->create(['matter_id' => $matter->id, 'responsible_user_id' => $this->world->lead->id, 'name' => 'Trước', 'due_date' => today()->addDays(2)->toDateString()]);
    Deadline::factory()->create(['matter_id' => $matter->id, 'responsible_user_id' => $this->world->lead->id, 'name' => 'Xong', 'due_date' => today()->subDay()->toDateString(), 'is_completed' => true]);

    $out = searchMatters($this->token);

    expect($out['next_cursor'])->toBeNull()
        ->and($out['matters'])->toHaveCount(1);

    $row = $out['matters'][0];

    expect(array_keys($row))->toBe([...MatterPresenter::ROW_FIELDS, 'next_deadline'])
        ->and($row['id'])->toBe(smId($matter))
        ->and($row['code'])->toBe($matter->code)
        ->and($row['client_name'])->toBe($matter->client->name)
        ->and($row['lead_lawyer'])->toBe('Luật sư Phụ Trách')
        ->and($row['stage_label'])->toBe($matter->currentStage()->label)
        ->and($row['url'])->toBe(AdminUrls::matter($matter))
        ->and($row['next_deadline']['id'])->toBe(McpIds::encode(McpIds::DEADLINE, $first->id))
        ->and($row['next_deadline']['name'])->toBe('Trước');
});

it('vụ không còn mốc nào chưa xong: next_deadline là null', function () {
    expect(searchMatters($this->token)['matters'][0]['next_deadline'])->toBeNull();
});

it('lọc theo chữ trên mã, tiêu đề, tên khách và số thụ lý', function () {
    $client = Client::factory()->create(['name' => 'Hợp tác xã Bilbyton']);
    $other = Matter::factory()->aiAccessAllowed()->create([
        'lead_lawyer_id' => $this->world->lead->id,
        'client_id' => $client->id,
        'case_number' => '815/2026/TLST-HNGĐ',
    ]);

    expect(searchMatterIds($this->token, ['query' => 'Quokkavan']))->toBe([smId($this->world->matter)])
        ->and(searchMatterIds($this->token, ['query' => $this->world->matter->code]))->toBe([smId($this->world->matter)])
        ->and(searchMatterIds($this->token, ['query' => 'Bilbyton']))->toBe([smId($other)])
        ->and(searchMatterIds($this->token, ['query' => '815/2026']))->toBe([smId($other)]);
});

it('R10: không tìm theo tên các bên hay tiêu đề tài liệu; cặp dương: cùng chữ trong tiêu đề vụ thì ra', function () {
    $matter = $this->world->matter;
    MatterParty::factory()->create(['matter_id' => $matter->id, 'name' => 'Người liên quan Dugongara']);
    Document::factory()->create(['matter_id' => $matter->id, 'title' => 'Đơn Dugongara', 'group' => DocumentGroup::ClientProvided]);

    expect(searchMatterIds($this->token, ['query' => 'Dugongara']))->toBe([]);

    $matter->forceFill(['title' => 'Vụ Dugongara'])->save();

    expect(searchMatterIds($this->token, ['query' => 'Dugongara']))->toBe([smId($matter)]);
});

it('lọc theo loại vụ (mã hoặc tên), giai đoạn, vụ tôi phụ trách, đang mở/đã kết thúc', function () {
    $lead = $this->world->lead;
    $matter = $this->world->matter;
    $type = MatterType::factory()->withStages()->create(['code' => 'ZQX', 'name' => 'Loại Zebu']);
    $typed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $lead->id, 'matter_type_id' => $type->id]);

    // Vụ của trưởng phòng, mình là thành viên — vào tập MCP, nhưng mình không phụ trách.
    $teamOnly = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => User::factory()->withRole(Role::Lawyer)->create()->id]);
    $teamOnly->addTeamMember($lead, MatterRole::Associate);

    $closed = Matter::factory()->aiAccessAllowed()->create(['lead_lawyer_id' => $lead->id, 'closed_at' => today()]);

    expect(searchMatterIds($this->token, ['matter_type' => 'ZQX']))->toBe([smId($typed)])
        ->and(searchMatterIds($this->token, ['matter_type' => 'Loại Zebu']))->toBe([smId($typed)])
        ->and(searchMatterIds($this->token, ['stage' => $typed->stage, 'matter_type' => 'ZQX']))->toBe([smId($typed)])
        ->and(searchMatterIds($this->token, ['stage' => 'khong-co-giai-doan-nay']))->toBe([])
        ->and(searchMatterIds($this->token))->toContain(smId($teamOnly))
        ->and(searchMatterIds($this->token, ['mine' => true]))->not->toContain(smId($teamOnly))
        ->and(searchMatterIds($this->token, ['mine' => true]))->toContain(smId($matter), smId($closed))
        ->and(searchMatterIds($this->token, ['open' => true]))->not->toContain(smId($closed))
        ->and(searchMatterIds($this->token, ['open' => false]))->toBe([smId($closed)]);
});

it('R3: chữ khớp vụ đội khác, vụ hạn chế của chính mình, vụ denied — và chữ không khớp gì — cho CÙNG một phản hồi', function () {
    $call = fn (array $arguments) => McpToolCall::call($this, $this->token, 'search_matters', $arguments)->json('result');

    $baseline = $call(['query' => 'Khongtontaigica']);

    expect($baseline['structuredContent'])->toBe(['matters' => [], 'next_cursor' => null]);

    foreach ($this->world->hiddenFromLead() as $case => $matter) {
        expect($call(['query' => $matter->code]))->toBe($baseline, "{$case} by code")
            ->and($call(['query' => explode(' ', $matter->title)[array_key_last(explode(' ', $matter->title))]]))->toBe($baseline, "{$case} by title");
    }

    // Cặp dương: chính chủ vụ đội khác thì thấy.
    expect(searchMatterIds(McpOAuth::accessToken($this, $this->world->outsider), ['query' => 'Narwhalix']))->toBe([smId($this->world->otherTeam)]);
});

it('phân trang: limit mặc định 10; limit quá 25 bị kẹp về 25; cursor dẫn sang trang kế, trang cuối không có cursor', function () {
    Matter::factory()->count(29)->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);
    // Tổng 30 vụ trong tập của $lead (29 + $matter).
    $all = Matter::query()->where('lead_lawyer_id', $this->world->lead->id)->where('confidentiality', 'normal')->where('ai_access', 'allowed')->orderByDesc('id')->pluck('id')->map(fn ($id) => McpIds::encode(McpIds::MATTER, $id))->all();

    expect($all)->toHaveCount(30);

    $default = searchMatters($this->token);
    expect($default['matters'])->toHaveCount(10)
        ->and(array_column($default['matters'], 'id'))->toBe(array_slice($all, 0, 10))
        ->and($default['next_cursor'])->toBeString();

    $first = searchMatters($this->token, ['limit' => 100]);
    expect($first['matters'])->toHaveCount(25)
        ->and(array_column($first['matters'], 'id'))->toBe(array_slice($all, 0, 25));

    $second = searchMatters($this->token, ['limit' => 100, 'cursor' => $first['next_cursor']]);
    expect(array_column($second['matters'], 'id'))->toBe(array_slice($all, 25))
        ->and($second['next_cursor'])->toBeNull();
});

it('cursor của người A không mở trang của người B: cùng bộ lọc, B nhận lỗi cursor, không nhận trang của A', function () {
    Matter::factory()->count(12)->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);

    $cursorOfA = searchMatters($this->token, ['limit' => 5])['next_cursor'];

    // B là admin: tập MCP của B CHỨA mọi vụ của A, nên nếu cursor được nhận thì B sẽ đọc tiếp đúng trang của A.
    $tokenOfB = McpOAuth::accessToken($this, $this->world->admin);

    expect(McpToolCall::error($this, $tokenOfB, 'search_matters', ['limit' => 5, 'cursor' => $cursorOfA]))
        ->toBe(__('mcp.tool_errors.invalid_cursor'));

    // Cặp dương: A dùng lại chính cursor đó thì nhận trang kế.
    expect(searchMatters($this->token, ['limit' => 5, 'cursor' => $cursorOfA])['matters'])->toHaveCount(5);
});

it('cursor gắn với bộ lọc: đổi bộ lọc giữa chừng thì cursor không dùng được; cursor bị sửa hay rác cũng vậy', function () {
    Matter::factory()->count(12)->aiAccessAllowed()->create(['lead_lawyer_id' => $this->world->lead->id]);

    $cursor = searchMatters($this->token, ['limit' => 5])['next_cursor'];
    $lead = $this->world->lead;
    $noFilters = ['query' => null, 'matter_type' => null, 'stage' => null, 'mine' => false, 'open' => null];
    // Dấu vân tay đúng như McpCursor dựng (khoá đã sắp), để dòng "other version" chỉ sai đúng một chỗ: phiên bản.
    $sortedFilters = $noFilters;
    ksort($sortedFilters);
    $afterId = (int) Matter::query()->orderByDesc('id')->skip(4)->value('id');

    // Cặp dương của các cursor tự dựng dưới đây: đúng người, đúng tool, đúng bộ lọc, id dương thì dùng được — kể
    // cả payload dựng tay với phiên bản 1, nên dòng "other version" đỏ chỉ vì phiên bản.
    $handBuilt = fn (int $version): string => Crypt::encryptString((string) json_encode(['v' => $version, 'u' => $lead->id, 't' => 'search_matters', 'f' => hash('sha256', (string) json_encode($sortedFilters)), 'a' => $afterId]));

    expect(searchMatters($this->token, ['limit' => 5, 'cursor' => McpCursor::encode($lead, 'search_matters', $noFilters, $afterId)])['matters'])->toHaveCount(5)
        ->and(searchMatters($this->token, ['limit' => 5, 'cursor' => $handBuilt(1)])['matters'])->toHaveCount(5);

    foreach ([
        'filter changed' => ['limit' => 5, 'cursor' => $cursor, 'open' => true],
        'query added' => ['limit' => 5, 'cursor' => $cursor, 'query' => 'Tranh'],
        'tampered' => ['limit' => 5, 'cursor' => substr($cursor, 0, -4).'AAAA'],
        'garbage' => ['limit' => 5, 'cursor' => 'khong-phai-cursor'],
        'other tool' => ['limit' => 5, 'cursor' => McpCursor::encode($lead, 'list_deadlines', $noFilters, $afterId)],
        'id zero' => ['limit' => 5, 'cursor' => McpCursor::encode($lead, 'search_matters', $noFilters, 0)],
        'other version' => ['limit' => 5, 'cursor' => $handBuilt(2)],
    ] as $case => $arguments) {
        expect(McpToolCall::error($this, $this->token, 'search_matters', $arguments))->toBe(__('mcp.tool_errors.invalid_cursor'), $case);
    }
});

it('chuỗi chỉ có khoảng trắng là "không lọc", không phải "không khớp gì"', function () {
    expect(searchMatterIds($this->token, ['query' => '   ', 'matter_type' => ' ', 'stage' => '']))->toBe([smId($this->world->matter)]);
});

it('một phiên cổng khách đang mở trong cùng tiến trình không cắt tên khách hay mốc gần nhất của dòng', function () {
    Deadline::factory()->create(['matter_id' => $this->world->matter->id, 'responsible_user_id' => $this->world->lead->id, 'is_published' => false]);

    $clientName = $this->world->matter->client->name;

    $this->actingAs(ClientUser::factory()->activated()->create(), 'client');

    $row = searchMatters($this->token)['matters'][0];

    expect($row['client_name'])->toBe($clientName)
        ->and($row['next_deadline'])->not->toBeNull();
});

it('kế toán bị EnsureMcpAccess từ chối (401, không phản hồi tool)', function () {
    McpToolCall::refused($this, McpOAuth::accessToken($this, $this->world->accountant), 'search_matters');
});

it('tham số sai kiểu bị từ chối bằng thông điệp kiểm tra, không phải "Không tìm thấy"', function () {
    foreach ([['limit' => 'nhieu'], ['mine' => 'co'], ['query' => str_repeat('x', 101)], ['stage' => str_repeat('x', 41)]] as $arguments) {
        expect(McpToolCall::error($this, $this->token, 'search_matters', $arguments))->not->toBe(__('mcp.tool_errors.not_found'));
    }
});

// ---------------------------------------------------------------------------------------------
// Tiếng Việt: hành vi THẬT của từng CSDL (chạy cả `test` lẫn `test:mariadb`, M7 Task 9)
// ---------------------------------------------------------------------------------------------

/**
 * `search_matters` dùng chính `SearchMatters` của M7 Task 9, nên chữ có dấu và không dấu theo đúng
 * collation của cột: MariaDB `utf8mb4_unicode_ci` bỏ dấu và hoa/thường nhưng "đ" ≠ "d"; SQLite so
 * byte. Test ghi lại đúng hành vi của CSDL đang chạy.
 */
it('tìm tiếng Việt có dấu và không dấu theo collation của CSDL đang chạy', function () {
    $mariadb = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    $client = Client::factory()->create(['name' => 'Ông Đỗ Văn Kiwiết']);
    $matter = Matter::factory()->aiAccessAllowed()->create([
        'lead_lawyer_id' => $this->world->lead->id,
        'client_id' => $client->id,
        'title' => 'Hợp đồng thuê nhà Đường Lâm',
    ]);

    $finds = fn (string $query): bool => searchMatterIds($this->token, ['query' => $query]) === [smId($matter)];

    // Đúng chữ, đúng dấu: mọi CSDL.
    expect($finds('thuê nhà'))->toBeTrue()
        ->and($finds('Đỗ Văn'))->toBeTrue();

    // Không dấu: MariaDB bỏ qua dấu, SQLite không.
    expect($finds('thue nha'))->toBe($mariadb)
        ->and($finds('van kiwiet'))->toBe($mariadb);

    // "đ" khác "d" trên cả hai.
    expect($finds('duong lam'))->toBeFalse()
        ->and($finds('đường lâm'))->toBe($mariadb);

    // Dạng tổ hợp (NFD) được đưa về NFC trước khi so — mọi CSDL.
    expect($finds(Normalizer::normalize('thuê nhà', Normalizer::FORM_D)))->toBeTrue();
});
