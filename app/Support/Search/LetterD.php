<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Làn fa3 (2026-10-16, mục 3 — kiểm tra luồng nội bộ): chữ "đ" khi tìm kiếm. Collation
 * `utf8mb4_unicode_ci` của MariaDB bỏ qua dấu và hoa/thường ("thue nha" ra "thuê nhà"), NHƯNG coi "đ" là
 * một chữ KHÁC "d" — nên người gõ không dấu "Dang" không ra khách "Đặng", "dat dai" không ra "đất đai". Lớp
 * này đổi "đ"/"Đ" → "d"/"D" ở CẢ HAI vế của một phép `LIKE` theo collation: chuỗi người gõ
 * ({@see self::fold()}) và cột ({@see self::column()}, `REPLACE(REPLACE(cột, 'đ', 'd'), 'Đ', 'D')` — SQL
 * chuẩn, cùng nghĩa trên MariaDB và SQLite). Sau bước đó collation lo phần còn lại.
 *
 * Tên các bên (`matter_parties.name_normalized`) KHÔNG đi qua đây: cột đó lưu `Normalizer::name()` (chữ
 * thường, bỏ dấu, "đ" → "d" nhờ `Str::ascii`) từ M1 — không có giá trị đã lưu nào phải tính lại.
 *
 * SQLite (bộ test thường) so `LIKE` theo byte và chỉ gộp hoa/thường của chữ ASCII: sau bước này chữ CHỈ
 * mang dấu "đ" ("Đinh", "QĐ") ra trên mọi CSDL; chữ mang dấu khác vẫn chỉ ra khi gõ đúng dấu — như trước.
 *
 * Dùng ở trang Tìm kiếm (`App\Actions\Search\SearchMatters`, kể cả `search_matters` của M11), trang
 * Tài liệu (`App\Filament\Admin\Pages\DocumentLibrary`) và ô tìm của trang Hợp đồng
 * (`App\Filament\Admin\Pages\ContractEntry`: mã hồ sơ, tên khách). Chuỗi người gõ luôn đi qua tham số ràng buộc,
 * và ký tự `%`, `_`, `!` của nó được thoát ({@see self::escape()}, ký tự thoát `!` — cùng lý do với
 * `SearchMatters`: SQLite không có ký tự thoát mặc định).
 */
final class LetterD
{
    public const ESCAPE = '!';

    /** "đ"/"Đ" → "d"/"D" trong chuỗi người gõ; mọi ký tự khác giữ nguyên. */
    public static function fold(string $value): string
    {
        return strtr($value, ['đ' => 'd', 'Đ' => 'D']);
    }

    /**
     * Biểu thức SQL của `$column` sau khi đổi "đ"/"Đ" → "d"/"D". `$column` phải là tên cột do mã viết
     * (hằng hay `qualifyColumn()`), không bao giờ là chữ người gõ.
     */
    public static function column(string $column): string
    {
        return "REPLACE(REPLACE({$column}, 'đ', 'd'), 'Đ', 'D')";
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
}
