<?php

namespace App\Support\Scopes;

use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Giới hạn mọi truy vấn theo khách hàng đang đăng nhập ở portal (SPEC §5 phần Portal).
 *
 * Điều kiện kích hoạt được định nghĩa **duy nhất** ở đây. Vế `! auth('web')->check()` là bắt
 * buộc: hai panel dùng chung cookie phiên nên một nhân sự đăng nhập cả /admin lẫn /portal sẽ
 * có cả hai guard cùng xác thực, và nếu thiếu vế này thì truy vấn ở /admin bị giới hạn sai.
 *
 * Không gắn scope này lên User và ClientUser: gọi auth() bên trong scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class ClientPortalScope implements Scope
{
    public static function isActive(): bool
    {
        return auth('client')->check() && ! auth('web')->check();
    }

    public static function clientUser(): ?ClientUser
    {
        return self::isActive() ? auth('client')->user() : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $clientUser = self::clientUser();

        if ($clientUser === null) {
            return;
        }

        $model->applyClientPortalConstraints($builder, $clientUser);
    }
}
