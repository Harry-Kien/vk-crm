<?php

namespace App\Actions\Search;

/**
 * Kết quả của một lần {@see SearchMatters::handle()}: các vụ việc (mới nhất trước) và cờ "còn nữa".
 *
 * **Không có tổng số.** `$truncated` chỉ nói "trong những vụ NGƯỜI NÀY thấy, còn vụ khớp ngoài giới
 * hạn" — nó được tính từ cùng câu SQL đã mang luật hiển thị (lấy `limit + 1` dòng), nên không bao
 * giờ bật vì một vụ người đó không được xem (R7: không đếm, không "có kết quả bị ẩn").
 */
final readonly class MatterSearchResults
{
    /**
     * @param  list<MatterSearchResult>  $matters
     */
    public function __construct(
        public array $matters,
        public bool $truncated,
    ) {}

    public static function none(): self
    {
        return new self([], false);
    }

    public function isEmpty(): bool
    {
        return $this->matters === [];
    }
}
