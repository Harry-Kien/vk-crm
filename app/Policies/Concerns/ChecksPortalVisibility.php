<?php

namespace App\Policies\Concerns;

use App\Models\ClientUser;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Quyền của khách hàng không dùng spatie (SPEC §5). Policy hỏi lại global scope thật qua
 * ClientPortalScope::actingAs(), chạy như thể $clientUser đang mở portal, nên hai tầng không
 * bao giờ lệch nhau và các ràng buộc whereHas lồng nhau của model con vẫn nhận đúng điều kiện
 * của Matter (scope phải thực sự hoạt động thì truy vấn con mới kế thừa được).
 *
 * Kết quả không phụ thuộc ngữ cảnh guard đang mở: actingAs() ghi đè tường minh, không đọc auth().
 */
trait ChecksPortalVisibility
{
    protected function visibleToPortal(ClientUser $clientUser, Model $record): bool
    {
        return ClientPortalScope::actingAs(
            $clientUser,
            fn (): bool => $record->newQuery()->whereKey($record->getKey())->exists(),
        );
    }
}
