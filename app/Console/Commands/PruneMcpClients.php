<?php

namespace App\Console\Commands;

use App\Actions\Mcp\PruneStaleMcpClients;
use Illuminate\Console\Command;

/**
 * `vkcrm:mcp-prune-clients` (M11 R7, Task 3) — dọn client OAuth do đăng ký động tạo ra mà đã quá 30
 * ngày không còn token nào sống. Luật nằm ở {@see PruneStaleMcpClients}; lệnh chỉ gọi nó và in số
 * client đã xoá. Lên lịch 03:15 hằng ngày (`routes/console.php`, tên `mcp.clients.prune`), chạy tay
 * được bất cứ lúc nào: chạy lại là vô hại.
 */
class PruneMcpClients extends Command
{
    protected $signature = 'vkcrm:mcp-prune-clients';

    protected $description = 'Xoá client OAuth tạo qua đăng ký động (DCR) đã quá 30 ngày không còn token nào sống (M11 R7)';

    public function handle(PruneStaleMcpClients $prune): int
    {
        $this->info(__('mcp.prune.done', ['count' => $prune->handle()]));

        return self::SUCCESS;
    }
}
