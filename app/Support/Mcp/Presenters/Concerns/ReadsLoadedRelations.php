<?php

namespace App\Support\Mcp\Presenters\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Presenter MCP không bao giờ truy vấn: mọi quan hệ nó đọc phải được Action đọc (Task 10–11) nạp
 * sẵn. Ba lý do:
 *  - Action đọc nạp dưới `ReadsWithoutPortalScope`; một lần lazy-load trong presenter chạy dưới
 *    `ClientPortalScope` của bất kỳ phiên cổng nào đang mở trong tiến trình, và trả `null` hay một
 *    tập thiếu thay cho dữ liệu thật — im lặng;
 *  - lazy-load trên một danh sách là N+1 truy vấn trong một request MCP;
 *  - "presenter là hàm thuần trên dữ liệu đã nạp" là thứ test đơn vị kiểm được không cần CSDL.
 * Quan hệ chưa nạp là lỗi lập trình: ném ngay, không âm thầm truy vấn.
 */
trait ReadsLoadedRelations
{
    protected static function loaded(Model $model, string $relation): mixed
    {
        if (! $model->relationLoaded($relation)) {
            throw new LogicException(
                'Presenter MCP cần quan hệ '.$relation.' đã nạp sẵn trên '.$model::class.' (presenter không truy vấn).'
            );
        }

        return $model->getRelation($relation);
    }
}
