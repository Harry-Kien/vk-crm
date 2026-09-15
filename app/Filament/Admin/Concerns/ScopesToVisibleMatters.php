<?php

namespace App\Filament\Admin\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dùng cho resource/relation manager của một model con của Matter (ví dụ Document, Deadline —
 * Task 6, 9): lọc bản ghi theo đúng luật hiển thị vụ việc (Matter::scopeListableBy), qua
 * `whereHas`, để không phải chép lại ba điều kiện đó ở từng nơi. `MatterResource` chính không
 * dùng trait này — nó gọi thẳng `listableBy()` trên Matter vì model của nó chính là Matter.
 */
trait ScopesToVisibleMatters
{
    protected static function scopeToVisibleMatters(Builder $query, string $relationship = 'matter'): Builder
    {
        return $query->whereHas(
            $relationship,
            fn (Builder $matterQuery) => $matterQuery->listableBy(Auth::user()),
        );
    }
}
