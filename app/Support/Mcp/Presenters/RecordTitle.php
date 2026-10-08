<?php

namespace App\Support\Mcp\Presenters;

use App\Models\ClientRequest;
use App\Models\Matter;
use App\Support\Mcp\Presenters\Concerns\ReadsLoadedRelations;

/**
 * `title` của một kết quả `search`/`fetch` (hợp đồng ChatGPT `{id, title, url}` [DC:644]) — một chỗ
 * dựng, để hai tool trả cùng một tiêu đề cho cùng một bản ghi.
 *
 * - Vụ việc: "mã — tiêu đề" (tiêu đề vụ do văn phòng đặt).
 * - Yêu cầu từ khách: "Yêu cầu từ khách gửi ngày giờ — mã vụ (trạng thái)", do VĂN PHÒNG dựng. Thời
 *   điểm gửi (rà soát Task 10 m2) để hai yêu cầu của cùng một vụ, cùng trạng thái không trùng tiêu đề:
 *   ChatGPT chỉ hiện `title` trong danh sách kết quả. Tiêu đề yêu cầu do KHÁCH viết không bao giờ là
 *   `title`: nó chỉ ra trong `untrusted_client_content` qua `UntrustedText` (R11) — `title` là trường
 *   client AI đọc như lời của máy chủ.
 */
final class RecordTitle
{
    use ReadsLoadedRelations;

    /** Ngày giờ khách gửi yêu cầu trong `title` (giờ của ứng dụng, như màn hình web). */
    public const REQUEST_DATE_FORMAT = 'd/m/Y H:i';

    public static function matter(Matter $matter): string
    {
        return $matter->code.' — '.$matter->title;
    }

    /** Cần nạp sẵn: `matter`. */
    public static function clientRequest(ClientRequest $request): string
    {
        /** @var Matter|null $matter */
        $matter = self::loaded($request, 'matter');

        return __('mcp.search.request_title', [
            'date' => (string) $request->created_at?->format(self::REQUEST_DATE_FORMAT),
            'code' => (string) $matter?->code,
            'status' => (string) $request->status?->label(),
        ]);
    }
}
