<?php

namespace App\Actions\Mcp\Read;

use App\Models\ClientRequest;
use App\Models\Matter;

/** Kết quả của {@see SearchRecords}: vụ việc và yêu cầu từ khách khớp chuỗi, mới nhất trước. */
final readonly class SearchResults
{
    /**
     * @param  list<Matter>  $matters
     * @param  list<ClientRequest>  $requests  đã nạp `matter`
     */
    public function __construct(
        public array $matters,
        public array $requests,
    ) {}
}
