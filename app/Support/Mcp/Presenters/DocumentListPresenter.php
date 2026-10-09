<?php

namespace App\Support\Mcp\Presenters;

use App\Actions\Mcp\Read\MatterDocumentsPage;
use App\Models\Document;

/**
 * Đầu ra của tool `list_documents` (kế hoạch M11, bảng tool 9): tham chiếu vụ, metadata tài liệu
 * ({@see DocumentPresenter} — nhóm A/B/C, không đường tải, tiêu đề nhóm A chỉ trong
 * `untrusted_client_content`), và `next_cursor`. Không có số đếm nào: nhóm D không được đếm (R4).
 */
final class DocumentListPresenter
{
    public const FIELDS = ['matter', 'documents', 'next_cursor'];

    /**
     * @return array{matter: array<string, string>, documents: list<array<string, mixed>>, next_cursor: ?string}
     */
    public static function present(MatterDocumentsPage $result, ?string $nextCursor): array
    {
        return [
            'matter' => MatterPresenter::reference($result->matter),
            'documents' => array_map(fn (Document $document): array => DocumentPresenter::present($document), $result->page->rows),
            'next_cursor' => $nextCursor,
        ];
    }
}
