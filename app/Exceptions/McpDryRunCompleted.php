<?php

namespace App\Exceptions;

use App\Actions\Mcp\Write\Concerns\ConfirmsInTwoSteps;
use RuntimeException;

/**
 * Tín hiệu NỘI BỘ của lần chạy thử (lần gọi thứ nhất của tool ghi hai bước, M11 R6;
 * {@see ConfirmsInTwoSteps::dryRun()}): Action nghiệp vụ đã chạy hết mọi lần kiểm và ghi trong một
 * transaction, rồi exception này được ném ra ngay trong transaction đó để `DB::transaction()` rollback
 * mọi thứ vừa ghi. Nó mang kết quả của lần chạy (bản ghi chưa bao giờ được commit) ra ngoài để dựng bản
 * xem trước. Không bao giờ rời khỏi `dryRun()`.
 */
final class McpDryRunCompleted extends RuntimeException
{
    public function __construct(public readonly mixed $result)
    {
        parent::__construct('Lần chạy thử đã xong; mọi thứ ghi trong nó được rollback.');
    }
}
