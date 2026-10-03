<?php

namespace App\Actions\Search;

use App\Enums\SearchSource;

/**
 * Một lý do một vụ việc có mặt trong kết quả tìm kiếm: nguồn đã khớp và chữ đã khớp ở nguồn đó (mã,
 * tiêu đề, tên khách, số thụ lý, tên một bên, tiêu đề một tài liệu). Chỉ được dựng bởi
 * {@see SearchMatters}, và chỉ với chữ mà người tìm được phép đọc — ví dụ không bao giờ là tiêu đề
 * một tài liệu nhóm D với người không có `document.viewInternal`.
 */
final readonly class MatterSearchHit
{
    public function __construct(
        public SearchSource $source,
        public string $text,
    ) {}
}
