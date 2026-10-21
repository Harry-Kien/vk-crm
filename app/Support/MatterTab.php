<?php

namespace App\Support;

use App\Filament\Admin\Resources\Matters\MatterResource;

/**
 * Đường dẫn tới MỘT tab (relation manager) của trang hồ sơ — làn fa2, cho lời báo mốc hạn và trình duyệt văn bản.
 * Cùng công thức `SeatInbox::teamTabUrl()` / `TaskColumns::matterTabUrl()`: tab không còn đăng ký thì về trang hồ sơ.
 */
final class MatterTab
{
    /** @param  class-string  $relationManager */
    public static function url(int $matterId, string $relationManager): string
    {
        $parameters = ['record' => $matterId];
        $tab = array_search($relationManager, MatterResource::getRelations(), true);

        if ($tab !== false) {
            $parameters['relation'] = $tab;
        }

        return MatterResource::getUrl('view', $parameters, panel: 'admin');
    }
}
