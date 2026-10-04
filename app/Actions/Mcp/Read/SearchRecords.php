<?php

namespace App\Actions\Mcp\Read;

use App\Actions\Mcp\McpMatterScope;
use App\Actions\Mcp\Read\Concerns\LoadsWithoutPortalScope;
use App\Actions\Search\SearchMatters;
use App\Enums\SearchSource;
use App\Models\ClientRequest;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Đọc cho tool `search` (hợp đồng ChatGPT, kế hoạch M11 bảng tool 2 [DC:48], [DC:644]): một chuỗi,
 * hai loại kết quả — vụ việc và yêu cầu từ khách — mỗi loại tối đa {@see self::LIMIT_PER_KIND}, mới
 * nhất trước. Hợp đồng `search` không có phân trang; ai cần nhiều hơn dùng `search_matters`.
 *
 * **Vụ việc**: ĐÚNG định nghĩa tìm của M7 Task 9 (`SearchMatters::matching()`), thu hẹp về bốn nguồn
 * {@see self::MATTER_SOURCES} — mã, tiêu đề, tên khách, số thụ lý. Không tìm theo tên các bên hay
 * tiêu đề tài liệu dù web tìm được: tên bên thứ ba là dữ liệu R10, và một kết quả "khớp" theo một
 * trường là một cách đọc trường đó. `SearchMatters` chỉ lọc theo `listableBy`, nên truy vấn của nó
 * chỉ là một ĐIỀU KIỆN (`id IN (…)`) trên `McpMatterScope::query($actor)` — giao, không thay (R3:
 * không vụ hạn chế, không vụ `denied`).
 *
 * **Yêu cầu từ khách**: tiêu đề (`client_requests.subject`) chứa chuỗi, thuộc một vụ trong tập R3
 * (`McpMatterScope::constrain()`), chưa rút (xoá mềm). Chuỗi được chuẩn hoá bằng chính
 * `SearchMatters::normalizeTerm()` (NFC, gộp khoảng trắng, ≥ 2 và ≤ 100 ký tự), `%`/`_` được thoát
 * như ở đó, so theo collation của cột (MariaDB bỏ dấu, SQLite so byte — ghi ở `SearchMatters`).
 * Tiêu đề do KHÁCH viết: Action chỉ trả bản ghi; presenter bọc nó (R11).
 *
 * Không có số đếm, không "còn nữa": một chuỗi khớp vụ người gọi không thấy cho đúng kết quả của một
 * chuỗi không khớp gì (R3, M7 R7).
 */
final class SearchRecords
{
    use LoadsWithoutPortalScope;

    public const LIMIT_PER_KIND = 10;

    /** Bốn nguồn R10 cho phép trên MCP — thứ tự của `SearchSource`. */
    public const MATTER_SOURCES = [
        SearchSource::Code,
        SearchSource::Title,
        SearchSource::ClientName,
        SearchSource::CaseNumber,
    ];

    private const ESCAPE = '!';

    public function __construct(
        private readonly McpMatterScope $scope,
        private readonly SearchMatters $search,
    ) {}

    public function handle(User $actor, string $query): SearchResults
    {
        $term = SearchMatters::normalizeTerm($query);

        if ($term === null) {
            return new SearchResults([], []);
        }

        /** @var list<Matter> $matters */
        $matters = $this->scope->query($actor)
            ->whereIn('matters.id', $this->matchingMatterIds($actor, $term))
            ->orderByDesc('matters.id')
            ->limit(self::LIMIT_PER_KIND)
            ->get()
            ->all();

        /** @var list<ClientRequest> $requests */
        $requests = $this->scope->constrain(ClientRequest::query(), $actor)
            ->whereRaw("client_requests.subject LIKE ? ESCAPE '".self::ESCAPE."'", ['%'.self::escapeLike($term).'%'])
            ->with($this->withoutPortalScope('matter'))
            ->orderByDesc('client_requests.id')
            ->limit(self::LIMIT_PER_KIND)
            ->get()
            ->all();

        return new SearchResults($matters, $requests);
    }

    /**
     * Truy vấn con `SELECT matters.id` của `SearchMatters::matching()` trên bốn nguồn — bỏ scope cổng
     * như `McpMatterScope` (nó tự mang `listableBy` và luật "ai tìm theo nguồn nào" của M7).
     *
     * @return Builder<Matter>
     */
    public function matchingMatterIds(User $actor, string $term): Builder
    {
        return $this->search->matching($actor, $term, self::MATTER_SOURCES)
            ->withoutGlobalScope(ClientPortalScope::class)
            ->select('matters.id');
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }
}
