<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\SearchResults;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\UntrustedText;

/**
 * Đầu ra của tool `search` — hợp đồng ChatGPT `{results: [{id, title, url}]}` [DC:644]: vụ việc trước,
 * rồi yêu cầu từ khách, mỗi nhóm theo thứ tự Action trả (mới nhất trước).
 *
 * - Vụ việc: đúng ba khoá của hợp đồng.
 * - Yêu cầu từ khách: ba khoá đó (`title` do văn phòng dựng, {@see RecordTitle}) cộng
 *   `untrusted_client_content.subject` — tiêu đề khách viết, đã qua {@see UntrustedText} (R11). Khoá
 *   thêm này không phá hợp đồng: client chỉ đọc ba khoá nó biết.
 *
 * `url` tuyệt đối về trang `/admin` (`AdminUrls`): trang vụ, hay tab "Yêu cầu từ khách" của trang vụ.
 */
final class SearchResultPresenter
{
    public const MATTER_FIELDS = ['id', 'title', 'url'];

    public const REQUEST_FIELDS = ['id', 'title', 'url', 'untrusted_client_content'];

    /**
     * Cần nạp sẵn: `matter` của mỗi yêu cầu.
     *
     * @return array{results: list<array<string, mixed>>}
     */
    public static function present(SearchResults $results): array
    {
        return [
            'results' => [
                ...array_map(self::matter(...), $results->matters),
                ...array_map(self::clientRequest(...), $results->requests),
            ],
        ];
    }

    /** @return array{id: string, title: string, url: string} */
    private static function matter(Matter $matter): array
    {
        return [
            'id' => McpIds::encode(McpIds::MATTER, (int) $matter->getKey()),
            'title' => RecordTitle::matter($matter),
            'url' => AdminUrls::matter($matter),
        ];
    }

    /** @return array<string, mixed> */
    private static function clientRequest(ClientRequest $request): array
    {
        return [
            'id' => McpIds::encode(McpIds::REQUEST, (int) $request->getKey()),
            'title' => RecordTitle::clientRequest($request),
            'url' => AdminUrls::clientRequest($request),
            'untrusted_client_content' => [
                'subject' => UntrustedText::from($request->subject, ClientRequestPresenter::SUBJECT_LIMIT),
            ],
        ];
    }
}
