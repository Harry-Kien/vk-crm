<?php

namespace App\Mcp\Methods;

use App\Mcp\Servers\CrmServer;
use Generator;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `tools/call` của {@see CrmServer} (M11 Task 6; đăng ký ở `CrmServer::boot()`): ĐIỂM DUY NHẤT mọi lần
 * gọi tool đi qua, nên mọi luật áp cho "một lần gọi tool" nằm ở đây hoặc ở {@see CrmToolInvoker}, không
 * ở từng tool — tool của Task 10/11/13 thừa hưởng mà không viết thêm dòng nào.
 *
 * Tìm tool giống hệt `CallTool` của laravel/mcp 1.0.1 (thiếu `name`: -32602; tên không có trong danh
 * sách ĐÃ LỌC theo `shouldRegister()` của request này: -32602 "not found"), rồi gọi qua
 * {@see CrmToolInvoker} thay cho `ToolInvoker` của gói. Lớp cha tự `new ToolInvoker`, nên không mượn
 * được `parent::handle()`.
 */
final class CallCrmTool extends CallTool
{
    /**
     * @return JsonRpcResponse|Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if (is_null($request->get('name'))) {
            throw new JsonRpcException('Missing [name] parameter.', -32602, $request->id);
        }

        $tool = $context->tools()->first(
            fn ($tool): bool => $tool->name() === $request->params['name'],
            fn () => throw new JsonRpcException("Tool [{$request->params['name']}] not found.", -32602, $request->id),
        );

        return app(CrmToolInvoker::class)->invoke($tool, $request);
    }
}
