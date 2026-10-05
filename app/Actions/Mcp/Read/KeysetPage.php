<?php

namespace App\Actions\Mcp\Read;

use Illuminate\Database\Eloquent\Model;

/**
 * Một trang của {@see KeysetOrder::page()}: các dòng, và vị trí của dòng cuối trang khi CÒN trang
 * sau (ngược lại `null`).
 *
 * @template TModel of Model
 */
final readonly class KeysetPage
{
    /**
     * @param  list<TModel>  $rows
     */
    public function __construct(
        public array $rows,
        public ?KeysetPosition $next,
    ) {}

    /**
     * Cùng trang, chỉ giữ các dòng `$keep` nhận — cho lần hỏi policy `view` từng dòng sau truy vấn.
     * `next` KHÔNG đổi: nó là vị trí của dòng cuối mà truy vấn đã đọc, nên trang kế bắt đầu đúng sau
     * trang này dù dòng cuối bị bỏ.
     *
     * @param  callable(TModel): bool  $keep
     * @return self<TModel>
     */
    public function filter(callable $keep): self
    {
        return new self(array_values(array_filter($this->rows, $keep)), $this->next);
    }
}
