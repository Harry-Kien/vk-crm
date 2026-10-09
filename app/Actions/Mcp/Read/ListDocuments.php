<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\FindsVisibleMatter;
use App\Enums\DocumentGroup;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Đọc cho tool `list_documents` (kế hoạch M11, bảng tool 9 [DC:55]): metadata tài liệu của MỘT vụ.
 *
 * - **Vụ**: {@see FindsVisibleMatter} (`McpMatterScope` rồi Gate `view`); không thấy thì `null`.
 * - **Nhóm D không bao giờ** (R4 [DC:110]): điều kiện nằm TRONG truy vấn, trước khi cắt trang, bất kể
 *   người gọi có `document.viewInternal` hay không — nên tài liệu nhóm D không thành một dòng, không
 *   làm trang ngắn đi, không làm "còn trang sau" bật lên. Viết bằng danh sách nhóm ĐƯỢC PHÉP
 *   ({@see self::groups()}: mọi nhóm mà `DocumentGroup::isInternal()` nói không), không bằng `!= D`:
 *   một giá trị lạ trong cột thì đóng cửa. `DocumentPresenter` từ chối nhóm D lần nữa ở tầng cuối.
 * - **Quyền từng dòng**: `DocumentPolicy::view` sau khi cắt trang (bảng tool); với nhân sự, trên nhóm
 *   A/B/C nó là `canSeeMatter`, hôm nay không bỏ dòng nào ({@see KeysetPage::filter()}).
 * - **Mọi tài liệu chưa xoá mềm**, kể cả bản nháp nội bộ, phiên bản cũ, tài liệu đã rút: tab "Tài
 *   liệu" của nhân sự hiện cả ba; `status`, `version` và hai cờ khách trong kết quả nói rõ từng dòng.
 * - **Thứ tự**: ngày tạo giảm dần rồi `id` — đúng tab ({@see KeysetOrder}).
 *
 * Chỉ metadata: không nạp tệp (medialibrary), không dựng đường tải hay URL ký nào (R4 [DC:111]).
 * Mỗi dòng được gắn chính vụ đã nạp `team`, nên policy không lazy-load vụ cha dưới scope cổng.
 */
final class ListDocuments
{
    use FindsVisibleMatter;

    public function __construct(private readonly McpMatterScope $scope) {}

    public function handle(User $actor, int $matterId, int $limit, ?KeysetPosition $after = null): ?MatterDocumentsPage
    {
        $matter = $this->visibleMatter($this->scope, $actor, $matterId);

        if ($matter === null) {
            return null;
        }

        $page = (new KeysetOrder('documents', 'created_at', descending: true))->page(
            $this->scope->constrain(Document::query(), $actor)
                ->where('documents.matter_id', $matter->getKey())
                ->whereIn('documents.group', self::groups()),
            $limit,
            $after,
        );

        foreach ($page->rows as $document) {
            $document->setRelation('matter', $matter);
        }

        return new MatterDocumentsPage(
            $matter,
            $page->filter(fn (Document $document): bool => Gate::forUser($actor)->allows('view', $document)),
        );
    }

    /**
     * Giá trị cột của các nhóm được ra MCP: A, B, C.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return array_values(array_map(
            fn (DocumentGroup $group): string => $group->value,
            array_filter(DocumentGroup::cases(), fn (DocumentGroup $group): bool => ! $group->isInternal()),
        ));
    }
}
