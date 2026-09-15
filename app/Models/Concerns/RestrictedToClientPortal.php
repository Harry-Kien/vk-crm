<?php

namespace App\Models\Concerns;

use App\Models\ClientUser;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model nào dùng trait này thì tự giới hạn truy vấn khi đang ở ngữ cảnh portal.
 * Điều kiện cụ thể do từng model khai báo; model khách không được thấy thì chặn sạch
 * bằng `$query->whereRaw('1 = 0')`.
 */
trait RestrictedToClientPortal
{
    public static function bootRestrictedToClientPortal(): void
    {
        static::addGlobalScope(new ClientPortalScope);
    }

    abstract public function applyClientPortalConstraints(Builder $query, ClientUser $clientUser): void;
}
