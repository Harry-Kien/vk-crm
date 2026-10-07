<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\KeysetPage;
use App\Models\ClientRequest;

/**
 * Đầu ra của tool `list_client_requests` (kế hoạch M11, bảng tool 10): các yêu cầu
 * ({@see ClientRequestPresenter::row()} — tiêu đề khách viết chỉ trong `untrusted_client_content`, R11;
 * không email, không tên người gửi) và `next_cursor`.
 */
final class ClientRequestListPresenter
{
    public const FIELDS = ['requests', 'next_cursor'];

    /**
     * Cần nạp sẵn: mỗi yêu cầu như {@see ClientRequestPresenter::row()}.
     *
     * @param  KeysetPage<ClientRequest>  $page
     * @return array{requests: list<array<string, mixed>>, next_cursor: ?string}
     */
    public static function present(KeysetPage $page, ?string $nextCursor): array
    {
        return [
            'requests' => array_map(fn (ClientRequest $request): array => ClientRequestPresenter::row($request), $page->rows),
            'next_cursor' => $nextCursor,
        ];
    }
}
