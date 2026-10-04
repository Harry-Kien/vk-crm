<?php

namespace App\Actions\Search;

use App\Actions\Concerns\ChecksAccountActive;
use App\Enums\DocumentGroup;
use App\Enums\Permission;
use App\Enums\SearchSource;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Tìm vụ việc bằng bất cứ thứ gì người ta nhớ (SPEC §6.13; M7 Task 9, R7): một chuỗi, sáu nguồn —
 * mã hồ sơ, tiêu đề vụ việc, tên khách hàng, số thụ lý, tên các bên (`matter_parties`), tiêu đề tài
 * liệu. Một Action ĐỌC, tách khỏi trang `App\Filament\Admin\Pages\Search`, để `search_matters` của
 * M11 dùng lại đúng định nghĩa này thay vì viết một định nghĩa thứ hai (qua {@see self::matching()}).
 *
 * # Luật hiển thị nằm TRONG câu SQL, trước giới hạn số dòng
 *
 * Mọi đường đi qua {@see self::constrain()}: `Matter::scopeListableBy($actor)` (định nghĩa duy nhất
 * của "nhân sự này thấy vụ nào": vụ thường theo `matter.viewAny`/đội ngũ, vụ `restricted` chỉ lead
 * và admin — kể cả trưởng phòng cũng không) VÀ điều kiện khớp chữ, trong CÙNG một truy vấn, rồi mới
 * `limit`. Lọc sau khi đã giới hạn số dòng sẽ làm lộ số lượng (trang đầy 25 dòng mà chỉ hiện 20); ở
 * đây không có con số nào được tính trên tập người tìm không thấy. Kết quả không có tổng số, chỉ có
 * cờ "còn nữa" ({@see MatterSearchResults}). "Không có gì khớp" và "có khớp nhưng không được xem" là
 * cùng một kết quả rỗng.
 *
 * Kiểm tra xung đột lợi ích (SPEC §6.10) có luật riêng và KHÔNG là cửa sau ở đây: một vụ người tìm
 * không xem được không bao giờ ra, kể cả khi tên một bên của nó khớp đúng từng chữ.
 *
 * # Ai tìm theo nguồn nào ({@see self::sourcesFor()})
 *
 * - Cần `MatterPolicy::viewAny` (`matter.viewAny` hoặc `matter.view`); không có thì không nguồn nào.
 * - Mã hồ sơ và tên khách hàng: mọi người liệt kê được vụ — đúng hai cột kế toán thấy trên danh
 *   sách vụ việc (và SPEC §5 "Ranh giới của kế toán": mã, loại vụ, tên khách).
 * - Tiêu đề vụ việc, số thụ lý, tên các bên, tiêu đề tài liệu: chỉ người có `matter.view` (mở được
 *   trang vụ việc, nơi duy nhất hiện chúng). Kế toán vì vậy không tìm được theo bốn nguồn này:
 *   `MattersTable` ẩn cột tiêu đề với kế toán (và Filament không tìm trên cột ẩn), số thụ lý chỉ có
 *   trên trang vụ việc, và SPEC §5 nói rõ màn hình của kế toán không mang tiêu đề, tài liệu hay các
 *   bên. Tìm theo một trường mà người đó không được đọc là một cách đọc trường đó.
 * - Tài liệu nhóm D: chỉ người có `document.viewInternal` — cùng điều kiện nhóm của
 *   `DocumentPolicy::view()`, nói lại bằng `group != 'D'` trong SQL. Tài liệu và các bên đã xoá mềm
 *   không bao giờ ra (`SoftDeletingScope` của hai model trong `whereHas`); vụ đã xoá mềm cũng vậy.
 *
 * Tham số `$sources` chỉ THU HẸP được (M11 tìm bốn nguồn của vụ, không tên các bên hay tài liệu):
 * giao với {@see self::sourcesFor()}, không bao giờ nới.
 *
 * Tài khoản đã bị vô hiệu hoá hoặc xoá mềm không nhận gì ({@see ChecksAccountActive}): Action nhận
 * actor tường minh và có thể được gọi từ chỗ không có cổng panel (M11).
 *
 * # `LIKE`, index, và vì sao mỗi nguồn tìm một kiểu
 *
 * `LIKE 'x%'` (tiền tố) dùng được index B-tree; `LIKE '%x%'` (chứa) thì không — CSDL duyệt cả bảng
 * (hoặc cả index, nếu index phủ đủ cột). Không Elasticsearch/Meilisearch/Scout (SPEC §6.13, shared
 * hosting).
 *
 * **Câu tìm sáu nguồn duyệt cả bảng, và đó là lựa chọn có đo.** Sáu điều kiện nằm trong MỘT `OR`;
 * chỉ cần một vế là kiểu chứa thì MariaDB không dùng được index nào cho cả `OR` — `EXPLAIN` trên
 * 6.000 vụ / 30.000 tài liệu / 18.000 bên cho `type=ALL` ở cả bốn bảng, và mỗi lần tìm mất 3,5–23,5 ms
 * (`tests/Benchmark/SearchMattersBenchmarkTest.php`, số đo ở "Ghi chú M7"). Ở quy mô SPEC nói ("vài
 * nghìn hồ sơ") thế là đủ; tách thành nhiều câu để vế tiền tố dùng index không mua được gì khi các vế
 * chứa vẫn duyệt bảng.
 *
 * - Số thụ lý: TIỀN TỐ — vì ĐỘ CHÍNH XÁC, không vì tốc độ: phần ký hiệu cuối ("/2026/TLST-DS") chung
 *   cho cả loạt vụ, kiểu chứa theo nó ra mọi vụ cùng loại; người ta nhớ số và năm thụ lý ở đầu
 *   ("4711/2026"). Một câu tiền tố đứng riêng trên cột này dùng `matters_case_number_index`
 *   (`EXPLAIN`: `range`) — đó là thứ index (migration `2026_09_28_070900`) phục vụ, không phải câu
 *   sáu nguồn ở đây.
 * - Mã hồ sơ: CHỨA — người ta nhớ số thứ tự ("0147"), không nhớ cả tiền tố "VK-2026-DD-"; ô tìm của
 *   `MattersTable` cũng tìm mã theo kiểu chứa.
 * - Tiêu đề vụ, tên khách, tiêu đề tài liệu, tên các bên: CHỨA — người ta nhớ một mảnh giữa ("Văn B",
 *   "khởi kiện").
 *
 * Chuỗi người dùng gõ được thoát `%`, `_` (và chính ký tự thoát) trước khi ghép vào mẫu — ký tự thoát
 * là `!` qua `ESCAPE '!'`, không phải `\`: SQLite không có ký tự thoát mặc định, còn chuỗi `'\\'`
 * trong SQL lại đọc khác nhau giữa MariaDB và SQLite. Chuỗi được chuẩn hoá trước khi tìm
 * ({@see self::normalizeTerm()}): NFC, gộp khoảng trắng, cắt ở {@see self::MAX_TERM_LENGTH}.
 *
 * # Tiếng Việt — hành vi THẬT của từng CSDL (đo ở `SearchPageTest`, chạy cả `test:mariadb`)
 *
 * - Tên các bên so trên `name_normalized` (`Normalizer::name()`: chữ thường, bỏ dấu, `đ` → `d`) với
 *   chuỗi tìm chuẩn hoá cùng hàm đó: có dấu hay không, hoa hay thường, đều ra — trên mọi CSDL.
 * - Mã, tiêu đề, tên khách, số thụ lý, tiêu đề tài liệu so bằng collation của cột. MariaDB
 *   `utf8mb4_unicode_ci`: bỏ qua dấu và hoa/thường ("thue nha" ra "thuê nhà"), NHƯNG "đ" là một chữ
 *   khác "d" ("duong" không ra "Đường"). SQLite (bộ test thường): so theo byte, chỉ gộp hoa/thường
 *   của chữ ASCII.
 */
final class SearchMatters
{
    use ChecksAccountActive;

    /** Chuỗi ngắn hơn thế này không tìm (sau chuẩn hoá): một ký tự khớp gần như mọi thứ. */
    public const MIN_TERM_LENGTH = 2;

    /** Trần độ dài chuỗi tìm (ký tự, sau chuẩn hoá) — ô tìm của trang mang đúng `maxlength` này. */
    public const MAX_TERM_LENGTH = 100;

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 50;

    /** Mỗi vụ, mỗi nguồn nhiều-dòng (tên các bên, tiêu đề tài liệu) hiện tối đa chừng này dòng khớp. */
    public const HITS_PER_SOURCE = 3;

    private const ESCAPE = '!';

    /** Nguồn chỉ đọc được với `matter.view` — xem docblock lớp, mục "Ai tìm theo nguồn nào". */
    private const CONTENT_SOURCES = [
        SearchSource::Title,
        SearchSource::CaseNumber,
        SearchSource::PartyName,
        SearchSource::DocumentTitle,
    ];

    /**
     * @param  list<SearchSource>|null  $sources  `null` = mọi nguồn người này được tìm
     */
    public function handle(User $actor, string $term, ?array $sources = null, int $limit = self::DEFAULT_LIMIT): MatterSearchResults
    {
        $prepared = $this->prepare($actor, $term, $sources);

        if ($prepared === null) {
            return MatterSearchResults::none();
        }

        [$patterns, $sources] = $prepared;
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = $this->constrain(Matter::query(), $actor, $patterns, $sources)->select('matters.*');
        $this->selectMatterLevelHits($query, $patterns, $sources);

        /** @var EloquentCollection<int, Matter> $matters */
        $matters = $query
            ->with(['client:id,name', 'matterType:id,name', 'team'])
            ->orderByDesc('matters.id')
            ->limit($limit + 1)
            ->get();

        $truncated = $matters->count() > $limit;
        $matters = $matters->take($limit)->values();
        $ids = $matters->modelKeys();

        // Dòng khớp nhiều-dòng chỉ được ĐỌC khi nguồn đó được phép, và `present()` chỉ dựng dòng khớp
        // cho nguồn được phép: hai lớp độc lập. Bỏ một lớp không lộ gì; bỏ cả hai thì kế toán thấy tên
        // các bên và tiêu đề tài liệu trong dòng "Khớp" (mutation probe ghi ở "Ghi chú M7").
        $partyHits = in_array(SearchSource::PartyName, $sources, true)
            ? $this->hitTexts(MatterParty::query()->whereIn('matter_id', $ids)->where($this->partyConstraint($patterns)), 'name')
            : collect();

        $documentHits = in_array(SearchSource::DocumentTitle, $sources, true)
            ? $this->hitTexts(Document::query()->whereIn('matter_id', $ids)->where($this->documentConstraint($actor, $patterns)), 'title')
            : collect();

        return new MatterSearchResults(
            $matters->map(fn (Matter $matter): MatterSearchResult => $this->present($actor, $matter, $sources, $partyHits, $documentHits))->all(),
            $truncated,
        );
    }

    /**
     * Truy vấn vụ việc khớp chuỗi — ĐÃ mang luật hiển thị của `$actor` — cho nơi gọi tự lắp thêm bộ
     * lọc, sắp xếp và phân trang (M11 `search_matters`). Không có gì để tìm (chuỗi quá ngắn, không
     * nguồn nào được phép, tài khoản không còn hiệu lực) thì là một truy vấn không ra dòng nào.
     *
     * @param  list<SearchSource>|null  $sources
     * @return Builder<Matter>
     */
    public function matching(User $actor, string $term, ?array $sources = null): Builder
    {
        $prepared = $this->prepare($actor, $term, $sources);

        if ($prepared === null) {
            return Matter::query()->whereRaw('1 = 0');
        }

        return $this->constrain(Matter::query(), $actor, ...$prepared);
    }

    /**
     * Những nguồn `$actor` được tìm, theo thứ tự `SearchSource::cases()` — xem docblock lớp, mục "Ai
     * tìm theo nguồn nào". Trang dùng hàm này để nói "đang tìm trong…".
     *
     * @return list<SearchSource>
     */
    public static function sourcesFor(User $actor): array
    {
        if (! Gate::forUser($actor)->allows('viewAny', Matter::class)) {
            return [];
        }

        $readsContent = $actor->can(Permission::MatterView->value);

        return array_values(array_filter(
            SearchSource::cases(),
            fn (SearchSource $source): bool => $readsContent || ! in_array($source, self::CONTENT_SOURCES, true),
        ));
    }

    /**
     * NFC (chữ gõ ở dạng tổ hợp `e` + dấu vẫn ra chữ lưu ở dạng dựng sẵn — `\Normalizer` viết đủ tên,
     * `App\Support\Normalizer` là một lớp khác), gộp mọi khoảng trắng thành một dấu cách, cắt ở
     * {@see self::MAX_TERM_LENGTH} ký tự. `null` khi còn ít hơn {@see self::MIN_TERM_LENGTH} ký tự.
     */
    public static function normalizeTerm(string $term): ?string
    {
        $clean = mb_scrub($term, 'UTF-8');
        $nfc = \Normalizer::normalize($clean, \Normalizer::FORM_C);
        $clean = is_string($nfc) ? $nfc : $clean;

        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $clean = rtrim(mb_substr($clean, 0, self::MAX_TERM_LENGTH));

        return mb_strlen($clean) >= self::MIN_TERM_LENGTH ? $clean : null;
    }

    /**
     * @param  list<SearchSource>|null  $requested
     * @return array{0: array{contains: string, prefix: string, party: ?string}, 1: list<SearchSource>}|null
     */
    private function prepare(User $actor, string $term, ?array $requested): ?array
    {
        $needle = self::normalizeTerm($term);

        if ($needle === null || ! $this->accountIsActive($actor)) {
            return null;
        }

        $sources = array_values(array_filter(
            self::sourcesFor($actor),
            fn (SearchSource $source): bool => $requested === null || in_array($source, $requested, true),
        ));

        if ($sources === []) {
            return null;
        }

        $escaped = self::escapeLike($needle);
        $partyNeedle = Normalizer::name($needle);

        return [[
            'contains' => '%'.$escaped.'%',
            'prefix' => $escaped.'%',
            // `Normalizer::name()` bỏ mọi ký tự không có dạng ASCII — một chuỗi toàn biểu tượng có thể
            // còn lại rỗng (`null`): khi đó nguồn tên các bên không khớp gì, không khớp tất cả.
            'party' => $partyNeedle === null ? null : '%'.self::escapeLike($partyNeedle).'%',
        ], $sources];
    }

    /**
     * Luật hiển thị (`listableBy`) VÀ "khớp ít nhất một nguồn", trong một truy vấn — xem docblock lớp.
     *
     * @param  Builder<Matter>  $matters
     * @param  array{contains: string, prefix: string, party: ?string}  $patterns
     * @param  list<SearchSource>  $sources
     * @return Builder<Matter>
     */
    private function constrain(Builder $matters, User $actor, array $patterns, array $sources): Builder
    {
        return $matters->listableBy($actor)->where(function (Builder $any) use ($actor, $patterns, $sources): void {
            foreach ($sources as $source) {
                match ($source) {
                    SearchSource::Code => $this->like($any, 'matters.code', $patterns['contains'], 'or'),
                    SearchSource::Title => $this->like($any, 'matters.title', $patterns['contains'], 'or'),
                    SearchSource::CaseNumber => $this->like($any, 'matters.case_number', $patterns['prefix'], 'or'),
                    SearchSource::ClientName => $any->orWhereHas('client', $this->clientConstraint($patterns)),
                    SearchSource::PartyName => $any->orWhereHas('parties', $this->partyConstraint($patterns)),
                    SearchSource::DocumentTitle => $any->orWhereHas('documents', $this->documentConstraint($actor, $patterns)),
                };
            }
        });
    }

    /**
     * Cờ "nguồn này khớp" cho bốn nguồn một-dòng của chính vụ — tính bằng SQL, cùng collation với
     * điều kiện lọc (so lại bằng PHP sẽ lệch: MariaDB bỏ qua dấu, `str_contains` thì không).
     *
     * @param  Builder<Matter>  $query
     * @param  array{contains: string, prefix: string, party: ?string}  $patterns
     * @param  list<SearchSource>  $sources
     */
    private function selectMatterLevelHits(Builder $query, array $patterns, array $sources): void
    {
        $columns = [
            SearchSource::Code->value => ['matters.code', $patterns['contains']],
            SearchSource::Title->value => ['matters.title', $patterns['contains']],
            SearchSource::CaseNumber->value => ['matters.case_number', $patterns['prefix']],
        ];

        foreach ($sources as $source) {
            if (isset($columns[$source->value])) {
                [$column, $pattern] = $columns[$source->value];
                $query->selectRaw(
                    "CASE WHEN {$column} LIKE ? ESCAPE '".self::ESCAPE."' THEN 1 ELSE 0 END AS ".self::hitAlias($source),
                    [$pattern],
                );
            }
        }

        if (in_array(SearchSource::ClientName, $sources, true)) {
            $query->withExists(['client as '.self::hitAlias(SearchSource::ClientName) => $this->clientConstraint($patterns)]);
        }
    }

    /**
     * @param  list<SearchSource>  $sources
     * @param  Collection<int, list<string>>  $partyHits
     * @param  Collection<int, list<string>>  $documentHits
     */
    private function present(User $actor, Matter $matter, array $sources, Collection $partyHits, Collection $documentHits): MatterSearchResult
    {
        // `team` đã nạp: `MatterPolicy::view` trả lời trong bộ nhớ (`isListableBy`), không một truy
        // vấn mỗi dòng. Ai không mở được trang vụ việc (kế toán) không nhận id, không nhận tiêu đề.
        $canOpen = Gate::forUser($actor)->allows('view', $matter);

        $hits = [];

        foreach ($sources as $source) {
            $texts = match ($source) {
                SearchSource::Code => $this->flagged($matter, $source) ? [$matter->code] : [],
                SearchSource::Title => $this->flagged($matter, $source) ? [$matter->title] : [],
                SearchSource::CaseNumber => $this->flagged($matter, $source) ? [(string) $matter->case_number] : [],
                SearchSource::ClientName => $this->flagged($matter, $source) ? [(string) $matter->client?->name] : [],
                SearchSource::PartyName => $partyHits->get($matter->getKey(), []),
                SearchSource::DocumentTitle => $documentHits->get($matter->getKey(), []),
            };

            foreach ($texts as $text) {
                $hits[] = new MatterSearchHit($source, $text);
            }
        }

        return new MatterSearchResult(
            matterId: $canOpen ? $matter->getKey() : null,
            code: $matter->code,
            title: $canOpen ? $matter->title : null,
            clientName: $matter->client?->name,
            matterTypeName: $matter->matterType?->name,
            hits: $hits,
        );
    }

    /**
     * Chữ đã khớp của một nguồn nhiều-dòng, theo vụ: gộp chữ trùng (nhiều version của cùng một tài
     * liệu mang cùng tiêu đề), giữ thứ tự tạo, tối đa {@see self::HITS_PER_SOURCE} dòng mỗi vụ.
     *
     * @return Collection<int, list<string>>
     */
    private function hitTexts(Builder $rows, string $column): Collection
    {
        return $rows->orderBy('id')
            ->get(['id', 'matter_id', $column])
            ->groupBy('matter_id')
            ->map(fn (Collection $group): array => $group->pluck($column)->unique()->take(self::HITS_PER_SOURCE)->values()->all());
    }

    /** @param  array{contains: string, prefix: string, party: ?string}  $patterns */
    private function clientConstraint(array $patterns): Closure
    {
        return fn (Builder $clients) => $this->like($clients, $clients->qualifyColumn('name'), $patterns['contains']);
    }

    /** @param  array{contains: string, prefix: string, party: ?string}  $patterns */
    private function partyConstraint(array $patterns): Closure
    {
        return fn (Builder $parties) => $patterns['party'] === null
            ? $parties->whereRaw('1 = 0')
            : $this->like($parties, $parties->qualifyColumn('name_normalized'), $patterns['party']);
    }

    /**
     * Tiêu đề tài liệu khớp, và — với người không có `document.viewInternal` — không thuộc nhóm D.
     * Dùng CHUNG cho điều kiện lọc vụ (`whereHas`) lẫn cho dòng khớp hiển thị, để hai nơi không lệch.
     *
     * @param  array{contains: string, prefix: string, party: ?string}  $patterns
     */
    private function documentConstraint(User $actor, array $patterns): Closure
    {
        $seesInternal = $actor->can(Permission::DocumentViewInternal->value);

        return function (Builder $documents) use ($patterns, $seesInternal): void {
            $this->like($documents, $documents->qualifyColumn('title'), $patterns['contains']);

            if (! $seesInternal) {
                $documents->where($documents->qualifyColumn('group'), '!=', DocumentGroup::Internal->value);
            }
        };
    }

    /** `$column` luôn là một hằng của lớp này (hoặc `qualifyColumn()` của nó) — không bao giờ chữ người gõ. */
    private function like(Builder $query, string $column, string $pattern, string $boolean = 'and'): Builder
    {
        return $query->whereRaw("{$column} LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern], $boolean);
    }

    private function flagged(Matter $matter, SearchSource $source): bool
    {
        return (bool) (int) $matter->getAttribute(self::hitAlias($source));
    }

    private static function hitAlias(SearchSource $source): string
    {
        return 'search_hit_'.$source->value;
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
