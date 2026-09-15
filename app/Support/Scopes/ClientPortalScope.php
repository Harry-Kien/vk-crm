<?php

namespace App\Support\Scopes;

use App\Models\ClientUser;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Giới hạn mọi truy vấn theo khách hàng đang đăng nhập ở portal (SPEC §5 phần Portal).
 *
 * Điều kiện kích hoạt được định nghĩa **duy nhất** ở đây. Hai panel dùng chung cookie phiên nên
 * một nhân sự đăng nhập cả /admin lẫn /portal sẽ có cả hai guard cùng xác thực; panel đang mở
 * (Filament::getCurrentPanel()) quyết định ai thắng, còn ngoài ngữ cảnh panel thì guard web
 * thắng để truy vấn nội bộ (job, console, test) không bị cắt.
 *
 * Không gắn scope này lên User và ClientUser: gọi auth() bên trong scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class ClientPortalScope implements Scope
{
    private static ?ClientUser $actingAs = null;

    public static function isActive(): bool
    {
        if (self::$actingAs !== null) {
            return true;
        }

        if (! auth('client')->check()) {
            return false;
        }

        // Hai panel dùng chung cookie phiên nên một nhân sự đăng nhập cả /admin lẫn /portal có
        // cả hai guard cùng xác thực. Đang ở panel portal thì luôn giới hạn theo khách; ngoài
        // ngữ cảnh panel (job, console, test) thì guard web thắng để truy vấn nội bộ không bị cắt.
        if (Filament::getCurrentPanel()?->getId() === 'portal') {
            return true;
        }

        return ! auth('web')->check();
    }

    /**
     * Chạy $callback như thể $clientUser đang mở portal. Policy dùng hàm này để câu trả lời
     * không phụ thuộc guard nào đang mở; và vì model con giới hạn bằng whereHas, scope phải
     * hoạt động thật thì điều kiện của Matter mới truyền xuống truy vấn con.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function actingAs(ClientUser $clientUser, callable $callback): mixed
    {
        $previous = self::$actingAs;
        self::$actingAs = $clientUser;

        try {
            return $callback();
        } finally {
            self::$actingAs = $previous;
        }
    }

    public static function clientUser(): ?ClientUser
    {
        if (self::$actingAs !== null) {
            return self::$actingAs;
        }

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
