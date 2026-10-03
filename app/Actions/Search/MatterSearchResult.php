<?php

namespace App\Actions\Search;

/**
 * Một vụ việc trong kết quả tìm kiếm, đúng như người tìm được phép thấy (M7 Task 9). Không phải
 * model: màn hình và M11 chỉ nhận những trường dưới đây.
 *
 * **`$matterId` và `$title` là `null` khi — và chỉ khi — người tìm không mở được trang vụ việc**
 * (`MatterPolicy::view`, tức kế toán: `matter.viewAny` mà không `matter.view`). Ranh giới đó là
 * ranh giới của danh sách vụ việc (`MattersTable` ẩn cột tiêu đề với kế toán) và của SPEC §5
 * "Ranh giới của kế toán": mã hồ sơ, loại vụ, tên khách — không tiêu đề, không id (một id là nửa
 * đường tới một liên kết vào trang vụ việc). Nơi hiển thị không được tự gắn lại hai trường này từ
 * một nguồn khác.
 *
 * `$clientName`/`$matterTypeName` là `null` khi khách hàng / loại vụ việc đã xoá mềm — cùng cách
 * `MattersTable` để trống ô đó.
 */
final readonly class MatterSearchResult
{
    /**
     * @param  list<MatterSearchHit>  $hits  nguồn đã khớp, theo thứ tự của `SearchSource::cases()`
     */
    public function __construct(
        public ?int $matterId,
        public string $code,
        public ?string $title,
        public ?string $clientName,
        public ?string $matterTypeName,
        public array $hits,
    ) {}
}
