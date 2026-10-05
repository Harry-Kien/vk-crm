<?php

namespace App\Actions\Mcp\Read;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Thứ tự (cột, `id`) và phân trang theo khoá cho các tool danh sách của Task 11 (kế hoạch M11, "Quy
 * ước chung": `limit` mặc định 10, tối đa 25, `cursor`). Cùng ý với `ListMatters` (keyset, không
 * `OFFSET`, đọc `limit + 1` dòng để biết còn trang sau mà không đếm), nhưng xếp theo một cột ngày
 * trước rồi mới tới `id` — cùng thứ tự với màn hình web tương ứng: mốc theo hạn tăng dần, dòng tiến độ
 * theo ngày xảy ra giảm dần, tài liệu theo ngày tạo giảm dần, yêu cầu theo hoạt động gần nhất giảm
 * dần. `id` cùng chiều là khoá phụ, nên hai dòng cùng giá trị cột không bao giờ trùng hay sót ở ranh
 * giới trang.
 *
 * **Cột rỗng.** MariaDB và SQLite đều coi `NULL` nhỏ nhất: đứng CUỐI khi giảm dần, ĐẦU khi tăng dần.
 * Điều kiện "sau vị trí" ({@see self::apply()}) viết theo đúng quy ước đó, nên một cột cho phép rỗng
 * (`client_requests.last_activity_at`, `documents.created_at`) vẫn phân trang đủ và không lặp.
 *
 * Giá trị cột đọc THÔ (`getRawOriginal()`), không qua cast: so lại với chính cột đó trên chính CSDL đó
 * thì khớp từng ký tự, bất kể SQLite lưu `date` thành `Y-m-d H:i:s` hay MariaDB trả `Y-m-d`.
 */
final readonly class KeysetOrder
{
    /**
     * @param  string  $table  bảng của truy vấn (cột được viết đủ tên bảng)
     * @param  string  $column  cột sắp xếp, không có tên bảng
     */
    public function __construct(
        public string $table,
        public string $column,
        public bool $descending,
    ) {}

    /** `limit` kẹp vào [1, {@see ListMatters::MAX_LIMIT}] — quá 25 thì lấy 25, không báo lỗi. */
    public static function clamp(int $limit): int
    {
        return max(1, min($limit, ListMatters::MAX_LIMIT));
    }

    /**
     * Một trang: thứ tự (cột, `id`), chỉ các dòng sau `$after`, `limit` đã kẹp. `next` là vị trí của
     * dòng cuối trang khi còn dòng sau nó.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return KeysetPage<TModel>
     */
    public function page(Builder $query, int $limit, ?KeysetPosition $after): KeysetPage
    {
        $limit = self::clamp($limit);

        $rows = $this->apply($query, $after)->limit($limit + 1)->get();

        /** @var list<TModel> $page */
        $page = $rows->take($limit)->values()->all();
        $last = end($page);

        return new KeysetPage(
            $page,
            $rows->count() > $limit && $last instanceof Model ? $this->positionOf($last) : null,
        );
    }

    /**
     * Thêm `ORDER BY` (cột, `id`) và, khi có `$after`, điều kiện "đứng sau vị trí đó" theo đúng thứ tự
     * ấy (xem docblock lớp về cột rỗng).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?KeysetPosition $after): Builder
    {
        $column = $this->table.'.'.$this->column;
        $id = $this->table.'.id';
        $beyond = $this->descending ? '<' : '>';

        if ($after !== null) {
            $query->where(function (Builder $next) use ($after, $column, $id, $beyond): void {
                if ($after->sort === null) {
                    // Đang ở giữa nhóm cột rỗng: còn các dòng rỗng có id đứng sau, và — khi tăng dần,
                    // nơi nhóm rỗng đứng đầu — mọi dòng có giá trị.
                    $next->where(fn (Builder $empty) => $empty->whereNull($column)->where($id, $beyond, $after->id));

                    if (! $this->descending) {
                        $next->orWhereNotNull($column);
                    }

                    return;
                }

                $next->where($column, $beyond, $after->sort)
                    ->orWhere(fn (Builder $same) => $same->where($column, $after->sort)->where($id, $beyond, $after->id));

                if ($this->descending) {
                    // Giảm dần: nhóm cột rỗng đứng cuối, sau mọi giá trị.
                    $next->orWhereNull($column);
                }
            });
        }

        $direction = $this->descending ? 'desc' : 'asc';

        return $query->orderBy($column, $direction)->orderBy($id, $direction);
    }

    public function positionOf(Model $row): KeysetPosition
    {
        $sort = $row->getRawOriginal($this->column);

        return new KeysetPosition($sort === null ? null : (string) $sort, (int) $row->getKey());
    }
}
