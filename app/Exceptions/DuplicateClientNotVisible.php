<?php

namespace App\Exceptions;

use DomainException;

/**
 * Fix round 1, C1 (Critical, M6.5 Task 6): dò trùng khi tạo khách mới (`App\Actions\Client\
 * CreateClient`) tìm thấy một hồ sơ khớp định danh, nhưng actor KHÔNG thấy được hồ sơ đó
 * (`App\Support\ClientVisibility::isVisibleTo()` trả `false` — ví dụ vụ DUY NHẤT của khách hàng
 * đó là `restricted` và do người khác phụ trách).
 *
 * **Không mang theo `Client` — khác `DuplicateClientDetected` một cách CÓ CHỦ ĐÍCH.** Nhánh này
 * chỉ dành cho actor KHÔNG có `client.manage`, và mục đích của cả nhánh là "đừng để lộ gì cả" —
 * không mã hồ sơ, không tên, không liên kết. Mang theo đối tượng `Client` (dù caller hứa không
 * đọc nó) là một cạm bẫy cho người sửa sau này; không có gì để đọc thì không có gì để lộ nhầm.
 *
 * Thông điệp TRUNG LẬP: không xác nhận hay phủ nhận có một hồ sơ khớp, không nêu tên ai.
 */
class DuplicateClientNotVisible extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.duplicate_client_not_visible'));
    }
}
