<?php

namespace Tests\Support;

use App\Mcp\Servers\CrmServer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;

/**
 * Gọi `/mcp` qua HTTP THẬT cho test audit và rate limit của M11 Task 8 (`AuditTest`,
 * `RateLimitTest`): token Passport thật ({@see McpOAuth}), mọi middleware của `routes/ai.php`, và
 * `CrmServer` thật với một danh sách tool cho trước ({@see self::serve()}).
 */
final class McpToolCalls
{
    /**
     * Ứng dụng của test sống qua mọi request; máy chủ thật (PHP-FPM) thì không. Quên guard và bộ
     * nhớ `once()` trước mỗi request, như một tiến trình mới.
     */
    public static function fresh(): void
    {
        Auth::forgetGuards();
        Once::flush();
    }

    /**
     * Một `tools/call` stateless 2026-07-28, kèm ba header của đặc tả (`MCP-Protocol-Version`,
     * `Mcp-Method`, `Mcp-Name`).
     *
     * `$fresh = false` giữ nguyên guard đang có của ứng dụng — để dựng đúng tình huống "trong cùng
     * tiến trình, guard `web` đang mang một người KHÁC" (Review Focus 4).
     *
     * `$test` là test case đang chạy (`$this`, hoặc `test()` của Pest — một proxy chuyển tiếp lời gọi).
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function call(object $test, string $token, string $name, array $arguments = [], bool $fresh = true): TestResponse
    {
        return self::send($test, $token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments], $fresh);
    }

    /**
     * Một request JSON-RPC bất kỳ (`tools/list`, `ping`…) theo cùng hình dạng của {@see self::call()}.
     *
     * @param  array<string, mixed>  $params
     */
    public static function send(object $test, string $token, string $method, array $params = [], bool $fresh = true): TestResponse
    {
        if ($fresh) {
            self::fresh();
        }

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];

        if (isset($params['name']) && is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }

        return $test->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => $method,
            'params' => [
                ...$params,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                ],
            ],
        ], $headers);
    }

    /**
     * Thay `CrmServer` (chỉ trong test đang chạy) bằng một lớp con có các tool cho trước. `/mcp` phân
     * giải lớp server qua container (`Registrar::startServer()`), nên middleware, guard, `boot()` và
     * method `tools/call` của server vẫn là mã thật.
     *
     * @param  list<Tool>  $tools
     */
    public static function serve(array $tools): void
    {
        app()->bind(CrmServer::class, function ($app, array $parameters) use ($tools) {
            $server = new class($parameters['transport']) extends CrmServer
            {
                /** @param  list<Tool>  $tools */
                public function useTools(array $tools): static
                {
                    $this->tools = $tools;

                    return $this;
                }
            };

            return $server->useTools($tools);
        });
    }
}
