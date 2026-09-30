<?php

namespace App\Exceptions;

use App\Actions\Matter\RequestHandoverPackage;
use DomainException;

/**
 * Vụ việc chưa ở trạng thái sinh gói được ({@see RequestHandoverPackage}): chưa kết thúc, hoặc
 * chưa có bản ghi lưu trữ. Lỗi của NGƯỜI BẤM (hoặc của dữ liệu), không phải lỗi hệ thống.
 */
class HandoverPackageUnavailable extends DomainException
{
    public static function notClosed(): self
    {
        return new self(__('handover.exceptions.not_closed'));
    }

    public static function noArchive(): self
    {
        return new self(__('handover.exceptions.no_archive'));
    }
}
