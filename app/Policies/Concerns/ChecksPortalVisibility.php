<?php

namespace App\Policies\Concerns;

use App\Models\ClientUser;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Quyền của khách hàng không dùng spatie (SPEC §5). Policy hỏi lại chính điều kiện mà global
 * scope dùng, nên hai tầng không bao giờ lệch nhau.
 *
 * Áp điều kiện tường minh với $clientUser được truyền vào thay vì dựa vào auth() hiện hành,
 * để kết quả không phụ thuộc ngữ cảnh guard đang mở.
 */
trait ChecksPortalVisibility
{
    protected function visibleToPortal(ClientUser $clientUser, Model $record): bool
    {
        return $record->newQuery()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->tap(fn (Builder $query) => $record->applyClientPortalConstraints($query, $clientUser))
            ->whereKey($record->getKey())
            ->exists();
    }
}
