<?php

namespace Tests\Support;

use App\Mcp\Servers\CrmServer;
use App\Mcp\Tools\Concerns\CrmTool;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use LogicException;
use ReflectionClass;

/**
 * Gọi tool MCP qua HTTP THẬT cho test của M11 (kế hoạch M11, "Ràng buộc toàn cục": test tool đi qua
 * `postJson('/mcp', …)` kèm `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` và token Passport).
 *
 * Mọi lời gọi đi qua đủ sáu middleware của `routes/ai.php` với một token thật của
 * {@see McpOAuth}; không `Server::tool()->actingAs()`, không `Passport::actingAs()`.
 *
 * Dạng request là client stateless 2026-07-28: `_meta` mang phiên bản và năng lực, ba header khớp
 * body (`ValidateMcpHeaders` của gói trả 400 khi lệch).
 */
final class McpToolCall
{
    public const PROTOCOL_VERSION = '2026-07-28';

    /**
     * `tools/call` — `$arguments` rỗng vẫn đi thành một object JSON `{}`, đúng hình dạng của client
     * thật.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function call(object $test, string $token, string $tool, array $arguments = [], int $id = 1): TestResponse
    {
        self::freshRequest();

        return $test->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => (object) $arguments,
                '_meta' => self::meta(),
            ],
        ], self::headers('tools/call', $token, $tool));
    }

    public static function listTools(object $test, string $token): TestResponse
    {
        self::freshRequest();

        return $test->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => self::meta()],
        ], self::headers('tools/list', $token));
    }

    /**
     * Gọi một tool, khẳng định lần gọi THÀNH CÔNG, và trả `structuredContent`. Đồng thời khẳng định
     * hai điều mà mọi kết quả thành công của bộ tool phải giữ (kế hoạch M11, "Quy ước chung"):
     *
     *  - `content[0].text` là đúng JSON của `structuredContent` (client không đọc
     *    `structuredContent` vẫn nhận cùng dữ liệu, không hơn);
     *  - `structuredContent` khớp `outputSchema` của tool — mọi khoá khai báo đều có, KHÔNG có khoá
     *    nào ngoài khai báo ({@see JsonSchemaConformance}). Đây là phép thử allowlist ở tầng giao
     *    thức: một trường lọt ra ngoài danh sách của presenter làm test đỏ ở đây.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function structured(object $test, string $token, string $tool, array $arguments = []): array
    {
        $response = self::call($test, $token, $tool, $arguments);

        $response->assertOk();

        $result = $response->json('result');

        if (! is_array($result) || ($result['isError'] ?? false) !== false) {
            throw new LogicException("Tool {$tool} trả lỗi: ".json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        $structured = $result['structuredContent'] ?? null;

        if (! is_array($structured)) {
            throw new LogicException("Tool {$tool} không trả structuredContent.");
        }

        $text = $result['content'][0]['text'] ?? null;

        expect(json_decode((string) $text, true))->toBe($structured);

        $violations = JsonSchemaConformance::violations(self::outputSchema($tool), $structured);

        expect($violations)->toBe([], "structuredContent của {$tool} lệch outputSchema");

        return $structured;
    }

    /**
     * Lời gọi THẤT BẠI (`isError: true`): trả chuỗi lỗi duy nhất của nó.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function error(object $test, string $token, string $tool, array $arguments = []): string
    {
        $response = self::call($test, $token, $tool, $arguments);

        $response->assertOk()->assertJsonPath('result.isError', true);

        expect($response->json('result.content'))->toHaveCount(1);

        return (string) $response->json('result.content.0.text');
    }

    /**
     * `outputSchema` của tool theo tên, đọc thẳng từ lớp tool đăng ký ở `CrmServer::$tools` — cùng
     * thứ `tools/list` gửi cho client.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(string $tool): array
    {
        foreach (self::registeredTools() as $class) {
            /** @var CrmTool $instance */
            $instance = app($class);

            if ($instance->name() === $tool) {
                return $instance->toArray()['outputSchema'] ?? throw new LogicException("Tool {$tool} không khai outputSchema.");
            }
        }

        throw new LogicException("Không có tool {$tool} trong CrmServer.");
    }

    /** @return list<class-string<CrmTool>> */
    public static function registeredTools(): array
    {
        /** @var list<class-string<CrmTool>> $tools */
        $tools = (new ReflectionClass(CrmServer::class))->getProperty('tools')->getDefaultValue();

        return $tools;
    }

    /**
     * Guard `mcp` (Passport `TokenGuard`) giữ người dùng đã xác thực trong chính đối tượng guard, và
     * ứng dụng của test được dùng lại qua mọi request trong một test: không quên đi thì request thứ
     * hai mang token của người B vẫn chạy dưới người A, và một test "B không thấy vụ của A" xanh hay
     * đỏ vì một lý do không liên quan. Trên PHP-FPM mỗi request là một tiến trình mới, nên đây là
     * đúng hình dạng của request thật. Chỉ quên guard `mcp`: phiên cổng khách do test tự dựng
     * (`actingAs(…, 'client')`) phải còn nguyên.
     */
    private static function freshRequest(): void
    {
        Auth::guard('mcp')->forgetUser();
    }

    /** @return array<string, mixed> */
    private static function meta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL_VERSION,
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];
    }

    /** @return array<string, string> */
    private static function headers(string $method, string $token, ?string $name = null): array
    {
        return array_filter([
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            'Mcp-Method' => $method,
            'Mcp-Name' => $name,
            'Authorization' => 'Bearer '.$token,
        ], fn (?string $value): bool => $value !== null);
    }
}
