<?php

namespace App\Support\Search;

use App\Support\Normalizer;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Làn fa3 (2026-10-16, mục 3 — kiểm tra luồng nội bộ): chữ "đ" khi tìm kiếm. Collation
 * `utf8mb4_unicode_ci` của MariaDB bỏ qua dấu và hoa/thường ("thue nha" ra "thuê nhà"), NHƯNG coi "đ" là
 * một chữ KHÁC "d" — nên người gõ không dấu "Dang" không ra khách "Đặng", "dat dai" không ra "đất đai". Lớp
 * này đổi "đ"/"Đ" → "d"/"D" ở CẢ HAI vế của một phép `LIKE` theo collation: chuỗi người gõ
 * ({@see self::fold()}) và cột ({@see self::column()}, bốn `REPLACE` lồng nhau — SQL chuẩn, cùng nghĩa trên
 * MariaDB và SQLite). Sau bước đó collation lo phần còn lại.
 *
 * Fix round 1 (m2): cùng với "đ"/"Đ" (U+0111/U+0110) là "ð"/"Ð" (U+00F0/U+00D0, chữ eth — một số bộ gõ và bộ
 * chuyển mã cũ gõ nó thay chữ "Đ" của tiếng Việt): `Normalizer::name()` đã gộp cả hai (qua `Str::ascii`),
 * nên lớp này gộp theo. `REPLACE` so đúng từng ký tự (không theo collation), nên mỗi ký tự một lần.
 *
 * Tên các bên (`matter_parties.name_normalized`) KHÔNG đi qua đây ở trang Tìm kiếm: cột đó lưu
 * `Normalizer::name()` (chữ thường, bỏ dấu, "đ"/"ð" → "d" nhờ `Str::ascii`) từ M1 — không có giá trị đã lưu
 * nào phải tính lại.
 *
 * SQLite (bộ test thường) so `LIKE` theo byte và chỉ gộp hoa/thường của chữ ASCII: sau bước này chữ CHỈ
 * mang dấu "đ" ("Đinh", "QĐ") ra trên mọi CSDL; chữ mang dấu khác vẫn chỉ ra khi gõ đúng dấu — như trước.
 *
 * Dùng ở trang Tìm kiếm (`App\Actions\Search\SearchMatters`, kể cả `search_matters` của M11), trang
 * Tài liệu (`App\Filament\Admin\Pages\DocumentLibrary`), ô tìm của trang Hợp đồng
 * (`App\Filament\Admin\Pages\ContractEntry`: mã hồ sơ, tên khách) và — fix round 1 (m3) — ô tìm của các
 * danh sách chính qua {@see self::searchable()}: Khách hàng (mã, tên), Vụ việc (mã, khách, loại vụ việc,
 * tiêu đề, luật sư phụ trách), Tiếp nhận (mã, tên người liên hệ), Nhân sự (tên, số thẻ luật sư), Tài khoản
 * cổng khách (khách, tên), tab Các bên (tên). Ô chọn tìm trên máy chủ bằng {@see self::searchOptions()}. Gộp
 * integ-fa (2026-10-18): thêm trang Công việc và tab Công việc của vụ (`TaskColumns`: tiêu đề, mã hồ sơ), bộ lọc vụ /
 * người được giao / người giao của trang Công việc, ô chọn của "Giao việc", "Giao lại", "Bàn giao hàng loạt" và "Thêm
 * thành viên" (danh sách phụ thuộc ô khác: {@see self::filterOptions()}).
 * Chuỗi người gõ luôn đi qua tham số ràng buộc, và ký tự `%`, `_`, `!` của nó được thoát
 * ({@see self::escape()}, ký tự thoát `!` — cùng lý do với `SearchMatters`: SQLite không có ký tự thoát
 * mặc định).
 */
final class LetterD
{
    public const ESCAPE = '!';

    /**
     * Fix round 2 (mA, làn fa3): độ trễ (ms) sau lần gõ cuối của một ô chọn dùng {@see self::searchOptions()}. Trước
     * đó các ô này lọc ngay trên trình duyệt; tìm trên máy chủ với mặc định 1000 ms của Filament là một bước lùi
     * thấy rõ khi gõ. 250 ms: đủ ngắn để giống lọc tại chỗ, đủ dài để không gửi một yêu cầu mỗi phím.
     */
    public const SELECT_SEARCH_DEBOUNCE_MS = 250;

    /** Ký tự đổi => ký tự thay, theo đúng thứ tự `REPLACE` lồng trong {@see self::column()}. */
    private const LETTERS = ['đ' => 'd', 'Đ' => 'D', 'ð' => 'd', 'Ð' => 'D'];

    /** "đ"/"Đ"/"ð"/"Ð" → "d"/"D" trong chuỗi người gõ; mọi ký tự khác giữ nguyên. */
    public static function fold(string $value): string
    {
        return strtr($value, self::LETTERS);
    }

    /**
     * Biểu thức SQL của `$column` sau khi đổi "đ"/"Đ"/"ð"/"Ð" → "d"/"D". `$column` phải là tên cột do mã viết
     * (hằng hay `qualifyColumn()`), không bao giờ là chữ người gõ.
     */
    public static function column(string $column): string
    {
        $expression = $column;

        foreach (self::LETTERS as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }

    /** Thoát `%`, `_` và chính ký tự thoát của chuỗi người gõ trước khi ghép vào mẫu `LIKE`. */
    public static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }

    /**
     * `$column` CHỨA `$search` (không phân biệt "đ"/"d"; dấu và hoa/thường theo collation của cột).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereContains(Builder $query, string $column, string $search, string $boolean = 'and'): Builder
    {
        return $query->whereRaw(
            self::column($column)." LIKE ? ESCAPE '".self::ESCAPE."'",
            ['%'.self::escape(self::fold($search)).'%'],
            $boolean,
        );
    }

    /**
     * Fix round 1 (m3): closure cho `TextColumn::searchable(query: …)` của Filament — cột `$column` của bảng
     * ("name") hay của một quan hệ ("client.name", "leadLawyer.name": phần trước dấu chấm cuối là đường quan
     * hệ, đi qua `whereHas`, như mặc định `whereRelation` của Filament — cùng global scope của model liên
     * quan). Chỉ THU HẸP truy vấn của bảng: phạm vi người xem (`listableBy()`, chính sách của resource) giữ
     * nguyên. Filament gọi closure một lần cho mỗi từ người gõ (bảng tách từ) và tự gộp các cột bằng `OR`.
     *
     * @return Closure(Builder, string): Builder
     */
    public static function searchable(string $column): Closure
    {
        if (! str_contains($column, '.')) {
            return static fn (Builder $query, string $search): Builder => self::whereContains($query, $query->qualifyColumn($column), $search);
        }

        $relation = Str::beforeLast($column, '.');
        $attribute = Str::afterLast($column, '.');

        return static fn (Builder $query, string $search): Builder => $query->whereHas(
            $relation,
            fn (Builder $related): Builder => self::whereContains($related, $related->qualifyColumn($attribute), $search),
        );
    }

    /**
     * Fix round 1 (m3): closure cho `Select::getSearchResultsUsing()` của một ô chọn có sẵn danh sách tuỳ chọn
     * (`id => nhãn`). Tìm trên máy chủ trong ĐÚNG danh sách `$options` trả về — không thêm tuỳ chọn nào, nên
     * tầm nhìn và luật `in:` mà Filament dựng từ `->options()` không đổi (ô chọn phải giữ `->options()` cùng
     * nguồn). So hai vế qua `Normalizer::name()`: bỏ mọi dấu (kể cả "đ"/"ð"), không phân biệt hoa/thường,
     * trên mọi CSDL; mọi từ người gõ phải có mặt, thứ tự nào cũng được. Chuỗi rỗng trả cả danh sách.
     *
     * @param  Closure(): array<int|string, string>  $options
     * @return Closure(?string): array<int|string, string>
     */
    public static function searchOptions(Closure $options): Closure
    {
        return static fn (?string $search): array => self::filterOptions($options(), $search);
    }

    /**
     * Phép lọc của {@see self::searchOptions()} trên một danh sách đã dựng — gộp integ-fa (2026-10-18): cho ô chọn có danh
     * sách PHỤ THUỘC ô khác hay bản ghi của hộp thoại (người nhận theo vụ đã chọn ở "Giao việc", người nhận của "Giao lại",
     * người nhận của "Bàn giao hàng loạt", người của "Thêm thành viên" theo vai), nơi người gọi tự dựng `$options` trong
     * closure `getSearchResultsUsing` của mình (có `Get`, `$record`) bằng ĐÚNG hàm của `->options()`.
     *
     * `$nameBefore` (gộp integ-fa, rà soát chéo, mục 19): nhãn mang thêm chữ sau tên (ô chọn người nhận việc: "Tên — đang
     * giữ 3 việc …") — chỉ so phần đứng TRƯỚC lần xuất hiện cuối của chuỗi này.
     *
     * @param  array<int|string, string>  $options
     * @return array<int|string, string>
     */
    public static function filterOptions(array $options, ?string $search, ?string $nameBefore = null): array
    {
        $words = preg_split('/\s+/u', (string) Normalizer::name($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_filter($options, function (mixed $label) use ($words, $nameBefore): bool {
            $label = (string) $label;
            $cut = $nameBefore === null || $nameBefore === '' ? false : strrpos($label, $nameBefore);
            $haystack = (string) Normalizer::name($cut === false ? $label : substr($label, 0, $cut));

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        });
    }
}
