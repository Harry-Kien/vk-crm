<?php

namespace App\Exceptions;

use App\Actions\Matter\RequestHandoverPackage;
use DomainException;

/**
 * Một lần sinh gói bàn giao của vụ này đang chạy ({@see RequestHandoverPackage}) — bấm nút lần nữa
 * không mở thêm lần thứ hai. `DomainException` để `ReportsActionFailures` hiện thành thông báo lỗi
 * thay vì trang 500.
 */
class HandoverPackageBusy extends DomainException
{
    public static function make(): self
    {
        return new self(__('handover.exceptions.busy'));
    }
}
