<?php

namespace App\Exceptions;

use App\Actions\Document\RetractDocument;
use App\Actions\Matter\RequestHandoverPackage;
use DomainException;

/**
 * Vụ việc chưa ở trạng thái sinh gói được ({@see RequestHandoverPackage}): chưa kết thúc, chưa có
 * bản ghi lưu trữ, hoặc gói hiện tại đang công bố cho khách. Lỗi của NGƯỜI BẤM (hoặc của dữ liệu),
 * không phải lỗi hệ thống.
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

    /**
     * Rà soát cuối M7, I2: gói hiện tại đang ra tới khách. Sinh lại không tự gỡ nó khỏi cổng ("một
     * đường rút duy nhất", Task 7) — câu chỉ tới nút "Rút lại" ({@see RetractDocument}).
     */
    public static function released(): self
    {
        return new self(__('handover.exceptions.released'));
    }
}
