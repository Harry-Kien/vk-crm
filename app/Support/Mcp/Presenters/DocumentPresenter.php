<?php

namespace App\Support\Mcp\Presenters;

use App\Enums\DocumentGroup;
use App\Models\Document;
use App\Support\Mcp\AdminUrls;
use App\Support\Mcp\McpIds;
use App\Support\Mcp\UntrustedText;
use LogicException;

/**
 * Metadata một tài liệu nhóm A/B/C trong kết quả MCP (kế hoạch M11, tool `list_documents`): nhóm,
 * tiêu đề, trạng thái, phiên bản, ngày, khách xem/tải được không, và url về TAB Tài liệu của vụ việc.
 *
 * Không bao giờ: nội dung tệp, tên tệp, đường tải `documents.download` hay URL ký (R4 [DC:111]),
 * người tải lên, tài liệu cha.
 *
 * Tiêu đề nhóm A do KHÁCH đặt khi nộp, nên ra trong `untrusted_client_content.title` qua
 * {@see UntrustedText} và `title` là `null` (R11); nhóm B/C do văn phòng đặt, ra thẳng ở `title` và
 * `untrusted_client_content` là `null`. Hai khoá luôn có mặt, để `outputSchema` của tool cố định.
 *
 * **Nhóm D bị TỪ CHỐI ở đây** — lớp cuối, sau bộ lọc của Action đọc: "không liệt kê, không đếm, không
 * fetch", bất kể `document.viewInternal` (R4 [DC:110]). Nhóm không rõ (cột không được SELECT, giá trị
 * lạ) cũng bị từ chối: không biết thì đóng cửa.
 */
final class DocumentPresenter
{
    public const FIELDS = [
        'id', 'group', 'group_label', 'title', 'untrusted_client_content', 'status', 'status_label', 'version',
        'issued_at', 'published_at', 'created_at', 'client_can_view', 'client_can_download', 'url',
    ];

    /** `documents.title` là string(250). */
    public const TITLE_LIMIT = 250;

    /**
     * @return array<string, mixed>
     */
    public static function present(Document $document): array
    {
        $group = $document->group;

        if (! $group instanceof DocumentGroup || $group->isInternal()) {
            throw new LogicException('DocumentPresenter: tài liệu nhóm D (hoặc nhóm không rõ) không bao giờ ra MCP.');
        }

        $clientWritten = $group === DocumentGroup::ClientProvided;

        return [
            'id' => McpIds::encode(McpIds::DOCUMENT, (int) $document->getKey()),
            'group' => $group->value,
            'group_label' => $group->label(),
            'title' => $clientWritten ? null : (string) $document->title,
            'untrusted_client_content' => $clientWritten
                ? ['title' => UntrustedText::from($document->title, self::TITLE_LIMIT)]
                : null,
            'status' => $document->status?->value,
            'status_label' => $document->status?->label(),
            'version' => (int) $document->version,
            'issued_at' => $document->issued_at?->toDateString(),
            'published_at' => $document->published_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'client_can_view' => (bool) $document->client_can_view,
            'client_can_download' => (bool) $document->client_can_download,
            'url' => AdminUrls::document($document),
        ];
    }
}
