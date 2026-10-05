<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Lang;
use Tests\Support\McpOAuth;
use Tests\Support\McpToolCall;

/*
|--------------------------------------------------------------------------
| M11 Task 10–11 — danh mục mười một tool đọc, qua `tools/list` THẬT
|--------------------------------------------------------------------------
| Thứ tự cố định (R13, [DC:649]), annotation trung thực (R14, lớp cơ sở `CrmTool`), `inputSchema`
| chặt (`additionalProperties: false`, mọi chuỗi có `maxLength`) và `outputSchema` cho
| `structuredContent` ("Quy ước chung" của bộ tool).
*/

beforeEach(function () {
    McpOAuth::useTestKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->token = McpOAuth::accessToken($this, User::factory()->withRole(Role::Lawyer)->create());
});

/** @return array<string, array<string, mixed>> tool theo tên, đúng như `tools/list` trả */
function catalogTools(string $token): array
{
    $response = McpToolCall::listTools(test(), $token);

    $response->assertOk();

    return collect($response->json('result.tools'))->keyBy('name')->all();
}

it('tools/list trả mười một tool đọc của Task 10 và 11, theo thứ tự cố định của bảng tool', function () {
    expect(array_keys(catalogTools($this->token)))
        ->toBe([
            'whoami', 'search', 'fetch', 'search_matters', 'get_matter',
            'list_matter_updates', 'list_deadlines', 'get_checklist', 'list_documents', 'list_client_requests', 'get_client_request',
        ]);
});

it('mọi tool là tool đọc trung thực: readOnlyHint true, destructive false, idempotent true, openWorld false, title ở cả hai chỗ', function () {
    foreach (catalogTools($this->token) as $name => $tool) {
        expect($tool['annotations'])->toBe([
            'title' => $tool['title'],
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $name)
            ->and($tool['title'])->toBe(__("mcp.tools.{$name}.title"))
            ->and($tool['description'])->toBe(__("mcp.tools.{$name}.description"));
    }
});

it('mô tả tool theo mẫu "Dùng khi… / Không dùng để…" (R11), tiếng Việt qua lang/vi', function () {
    foreach (catalogTools($this->token) as $name => $tool) {
        expect(Lang::has("mcp.tools.{$name}.description"))->toBeTrue($name)
            ->and($tool['description'])->toStartWith('Dùng khi')
            ->and($tool['description'])->toContain('Không dùng để');
    }
});

it('inputSchema chặt: additionalProperties false, mọi tham số chuỗi có maxLength, mọi tham số có mô tả', function () {
    foreach (catalogTools($this->token) as $name => $tool) {
        $schema = $tool['inputSchema'];

        expect($schema['type'])->toBe('object', $name)
            ->and($schema['additionalProperties'] ?? null)->toBeFalse($name);

        foreach ((array) $schema['properties'] as $param => $definition) {
            expect($definition['description'] ?? '')->not->toBe('', "{$name}.{$param}");

            if ($definition['type'] === 'string') {
                expect($definition['maxLength'] ?? null)->toBeInt("{$name}.{$param}");
            }
        }
    }
});

it('tool không tham số vẫn khai properties là object JSON {}, không phải mảng []', function () {
    $raw = McpToolCall::listTools($this, $this->token)->getContent();
    $whoami = collect(json_decode($raw, false)->result->tools)->firstWhere('name', 'whoami');

    expect($whoami->inputSchema->properties)->toBeInstanceOf(stdClass::class)
        ->and((array) $whoami->inputSchema->properties)->toBe([]);
});

it('mọi tool khai outputSchema dạng object đóng (additionalProperties false)', function () {
    foreach (catalogTools($this->token) as $name => $tool) {
        expect($tool['outputSchema']['type'] ?? null)->toBe('object', $name)
            ->and($tool['outputSchema']['additionalProperties'] ?? null)->toBeFalse($name)
            ->and($tool['outputSchema']['properties'] ?? [])->not->toBeEmpty($name);
    }
});

it('tham số ngoài inputSchema bị từ chối ở server, không bị lờ đi; cặp dương: cùng lời gọi không có nó thì chạy', function () {
    $message = McpToolCall::error($this, $this->token, 'whoami', ['as_user' => 'user_1']);

    expect($message)->toBe(__('mcp.tool_errors.unknown_arguments', ['names' => 'as_user']));

    McpToolCall::structured($this, $this->token, 'whoami');
});
