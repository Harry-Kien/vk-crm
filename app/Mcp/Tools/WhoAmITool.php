<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\Read\ReadWhoAmI;
use App\Enums\AiAccessMode;
use App\Mcp\Tools\Concerns\CrmReadTool;
use App\Mcp\Tools\Concerns\OutputSchemas;
use App\Support\Mcp\Presenters\WhoAmIPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;

/**
 * Tool `whoami` (kế hoạch M11, bảng tool 1 [DC:47]): người sở hữu token, vai trò, số vụ thấy được qua
 * MCP, chế độ đang có hiệu lực (`read` / `read_write`), các giới hạn đang áp. Không tham số. Đọc
 * qua {@see ReadWhoAmI}, trình bày qua {@see WhoAmIPresenter}.
 */
final class WhoAmITool extends CrmReadTool
{
    protected string $name = 'whoami';

    public function handle(Request $request, ReadWhoAmI $read): ResponseFactory
    {
        $this->validated($request, []);

        return $this->result(WhoAmIPresenter::present($read->handle($this->actor())));
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return OutputSchemas::required([
            'user' => OutputSchemas::staff($schema),
            'roles' => OutputSchemas::listOf($schema, OutputSchemas::closed($schema, [
                'role' => $schema->string(),
                'role_label' => $schema->string(),
            ])),
            'mode' => $schema->string()->enum([AiAccessMode::Read->value, AiAccessMode::ReadWrite->value]),
            'mode_label' => $schema->string(),
            'matter_count' => $schema->integer(),
            'limits' => OutputSchemas::listOf($schema, OutputSchemas::closed($schema, [
                'code' => $schema->string(),
                'label' => $schema->string(),
            ])),
        ]);
    }
}
