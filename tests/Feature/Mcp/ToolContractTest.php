<?php

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Mcp\Tools\Concerns\CrmTool;
use App\Mcp\Tools\FetchTool;
use App\Models\User;
use App\Support\Mcp\McpIds;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\Support\McpOAuth;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 14 — hợp đồng của MỌI tool, đọc từ `tools/list` qua HTTP thật
|--------------------------------------------------------------------------
| Người gọi là `read_write` với công tắc ghi bật, nên `tools/list` trả đủ mười lăm tool (R13 giấu bốn
| tool ghi với người `read`). Tập tool lấy từ `CrmServer` (`McpToolCall::registeredTools()`), không
| chép tay: tool thêm sau này tự vào mọi khẳng định dưới đây.
|
| `ToolCatalogTest` (Task 10–11) đã ghim từng tool đọc; tệp này là hợp đồng CHUNG cho cả tool ghi:
| bốn hint tường minh và `title` ở hai chỗ (R14), tên đúng quy ước [DC:30], `inputSchema` đóng,
| `outputSchema` có mặt, mô tả không chứa URL, tool ghi `readOnlyHint: false`, không tool nào
| `openWorldHint: true` [DC:35].
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    McpOAuth::openServer(write: true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->token = McpOAuth::accessToken($this, User::factory()->withRole(Role::Lawyer)->withAiAccess(AiAccessMode::ReadWrite)->create());
});

/** @return array<string, array<string, mixed>> tool theo tên, đúng như `tools/list` trả qua HTTP */
function contractTools(string $token): array
{
    $response = McpToolCall::listTools(test(), $token);

    $response->assertOk();

    return collect($response->json('result.tools'))->keyBy('name')->all();
}

/** @return array<string, bool> tên tool → tool ghi?, theo lớp khai ở `CrmServer` */
function contractDeclaredTools(): array
{
    $declared = [];

    foreach (McpToolCall::registeredTools() as $class) {
        /** @var CrmTool $tool */
        $tool = app($class);
        $declared[$tool->name()] = $tool->isWriteTool();
    }

    return $declared;
}

it('tools/list của người read_write trả ĐÚNG mọi tool CrmServer khai, cùng thứ tự; có cả tool đọc lẫn tool ghi', function () {
    $declared = contractDeclaredTools();

    expect(array_keys(contractTools($this->token)))->toBe(array_keys($declared))
        ->and(array_filter($declared))->toHaveCount(4)
        ->and(array_filter($declared, fn (bool $writes): bool => ! $writes))->toHaveCount(11);
});

it('mọi tool khai đủ bốn hint TƯỜNG MINH và title ở cả Tool.title lẫn annotations.title', function () {
    foreach (contractTools($this->token) as $name => $tool) {
        $annotations = $tool['annotations'] ?? [];

        foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
            expect(array_key_exists($hint, $annotations))->toBeTrue("{$name}.{$hint} vắng")
                ->and($annotations[$hint])->toBeBool("{$name}.{$hint}");
        }

        expect($tool['title'] ?? null)->toBeString($name)->not->toBe('')
            ->and($annotations['title'] ?? null)->toBe($tool['title'], $name);
    }
});

it('hint trung thực: tool ghi readOnlyHint false, tool đọc true; không tool nào destructive hay openWorld', function () {
    $declared = contractDeclaredTools();

    foreach (contractTools($this->token) as $name => $tool) {
        expect($tool['annotations']['readOnlyHint'])->toBe(! $declared[$name], $name)
            ->and($tool['annotations']['destructiveHint'])->toBeFalse($name)
            ->and($tool['annotations']['openWorldHint'])->toBeFalse($name)
            ->and($tool['annotations']['idempotentHint'])->toBeTrue($name);
    }
});

it('tên tool khớp ^[a-z0-9_]{1,64}$', function () {
    foreach (array_keys(contractTools($this->token)) as $name) {
        expect(preg_match('/^[a-z0-9_]{1,64}$/', (string) $name))->toBe(1, (string) $name);
    }
});

it('inputSchema là object đóng (additionalProperties === false), outputSchema có mặt và cũng đóng', function () {
    foreach (contractTools($this->token) as $name => $tool) {
        expect($tool['inputSchema']['type'] ?? null)->toBe('object', $name)
            ->and(array_key_exists('additionalProperties', $tool['inputSchema']))->toBeTrue($name)
            ->and($tool['inputSchema']['additionalProperties'])->toBeFalse($name)
            ->and($tool['outputSchema'] ?? null)->toBeArray($name)
            ->and($tool['outputSchema']['type'] ?? null)->toBe('object', $name)
            ->and($tool['outputSchema']['additionalProperties'] ?? null)->toBeFalse($name);
    }
});

it('mô tả tool và mô tả từng tham số không chứa URL (không gì mời model đi ra ngoài)', function () {
    $url = '~(?:https?://|www\.|[a-z0-9-]+\.(?:com|vn|net|org|ai|io)\b)~i';

    foreach (contractTools($this->token) as $name => $tool) {
        expect(preg_match($url, (string) $tool['description']))->toBe(0, "{$name}: {$tool['description']}");

        foreach ((array) ($tool['inputSchema']['properties'] ?? []) as $param => $definition) {
            expect(preg_match($url, (string) ($definition['description'] ?? '')))->toBe(0, "{$name}.{$param}");
        }
    }
});

it('máy dò URL của mô tả bắt đúng các dạng (cặp dương/âm)', function () {
    $url = '~(?:https?://|www\.|[a-z0-9-]+\.(?:com|vn|net|org|ai|io)\b)~i';

    foreach (['Xem https://x.test', 'mở www.example.test', 'vào luatvukhang.com', 'claude.ai'] as $text) {
        expect(preg_match($url, $text))->toBe(1, $text);
    }

    expect(preg_match($url, 'Dùng khi cần tìm vụ việc theo mã, ví dụ VK-2026-0001. Không dùng để tải tệp.'))->toBe(0);
});

/**
 * "Quy ước chung" của bộ tool: `maxLength` bằng độ dài cột DB (MariaDB strict: vượt là lỗi 500). Với
 * tham số lọc theo một cột enum, cùng cột thì cùng giới hạn ở mọi tool. Rà soát Task 11 r3:
 * `list_deadlines.severity` khai 10 trong khi `deadlines.severity` là `string(20)` và
 * `create_deadline.severity` khai 20.
 */
it('tham số severity của list_deadlines và create_deadline khai maxLength bằng độ dài cột deadlines.severity', function () {
    $tools = contractTools($this->token);
    $column = collect(Schema::getColumns('deadlines'))->firstWhere('name', 'severity');

    expect($column)->not->toBeNull();

    // SQLite không giữ độ dài varchar; cột khai `string('severity', 20)` ở migration.
    $length = preg_match('/\((\d+)\)/', (string) $column['type'], $m) === 1 ? (int) $m[1] : 20;

    expect($length)->toBe(20)
        ->and($tools['list_deadlines']['inputSchema']['properties']['severity']['maxLength'])->toBe($length)
        ->and($tools['create_deadline']['inputSchema']['properties']['severity']['maxLength'])->toBe($length);
});

/**
 * Rà soát Task 10 m1: `FetchTool::ID_MAX_LENGTH` là trần của mọi tham số id có tiền tố. Nó phải chứa
 * được id DÀI NHẤT `McpIds` dựng ra (tiền tố dài nhất + 18 chữ số), không hơn nhiều: mọi tham số id
 * của mọi tool khai đúng trần này.
 */
it('trần độ dài tham số id bằng id dài nhất McpIds dựng ra, và mọi tham số id khai đúng trần đó', function () {
    $prefixes = array_filter(
        (new ReflectionClass(McpIds::class))->getConstants(),
        fn (mixed $value): bool => is_string($value),
    );
    $longest = max(array_map(fn (string $prefix): int => strlen(McpIds::encode($prefix, (int) str_repeat('9', 18))), $prefixes));

    expect(FetchTool::ID_MAX_LENGTH)->toBe($longest);

    foreach (contractTools($this->token) as $name => $tool) {
        foreach ((array) $tool['inputSchema']['properties'] as $param => $definition) {
            if ($param === 'id' || str_ends_with((string) $param, '_id')) {
                expect($definition['maxLength'] ?? null)->toBe($longest, "{$name}.{$param}");
            }
        }
    }
});
