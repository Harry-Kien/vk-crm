<?php

namespace App\Models;

use Database\Factories\ClientUserFactory;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: gọi auth() trong global scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class ClientUser extends Authenticatable implements FilamentUser, HasEmailAuthentication
{
    /** @use HasFactory<ClientUserFactory> */
    use HasFactory;

    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'must_change_password',
        'activated_at',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Khách hàng chỉ vào được portal, và chỉ khi tài khoản còn hoạt động.
     *
     * Task 2 (`portal/portal-3`): thêm `$this->client !== null`, ĐỘC LẬP với điều kiện tương tự
     * ở `Matter::applyClientPortalConstraints()` — hai tầng cố ý tách rời (query của Matter và
     * cổng vào của chính tài khoản) để một lần đột biến chỉ xoá MỘT trong hai không lặng lẽ mở
     * lại cả hai. Trước bản sửa này, xoá mềm khách hàng (`Client::delete()`, admin bấm ở
     * EditClient) không đụng tới `client_users.is_active`, nên tài khoản cổng của khách đã xoá
     * vẫn đăng nhập, đọc hồ sơ và sinh phiếu "đã xem" như thường.
     *
     * `client()` là `BelongsTo` thường, mang theo `SoftDeletingScope` của `Client` (không phải
     * `ClientPortalScope` — `Client` có cả hai global scope, nhưng scope kia chỉ kích hoạt dưới
     * `ClientPortalScope::isActive()` và luôn là một AND vô hại thêm đúng `client_id` này, không
     * đổi kết luận `null`/không-null), nên quan hệ tự trả `null` ngay khi khách đã xoá mềm — hỏi
     * `deleted_at` trực tiếp ở đây sẽ chỉ lặp lại đúng chuyện `SoftDeletingScope` đã làm.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->is_active && $this->client !== null;
    }

    /**
     * SPEC §8.1: mã một lần qua email là BẮT BUỘC với mọi tài khoản khách, nên câu trả lời là
     * `true` cứng — không đọc một cột nào, vì không có cột nào được phép nói khác.
     *
     * Đây là nửa thứ nhất của "không có tuỳ chọn tắt". Nửa thứ hai là panel `portal` không đăng
     * ký trang hồ sơ cá nhân (`EditProfile`) — đó mới là nơi `DisableEmailAuthenticationAction`
     * sống, và một `toggleEmailAuthentication()` ném ngoại lệ không tự mình ngăn được ai nếu màn
     * hình kia có mặt. Xem `PortalPanelProvider` và `tests/Feature/Portal/LoginTest.php`.
     */
    public function hasEmailAuthentication(): bool
    {
        return true;
    }

    /**
     * Không có đường tắt. Ném thay vì lặng lẽ không làm gì: một lời gọi tới đây là một chỗ nào
     * đó trong hệ thống đang tin rằng bật/tắt được, và điều đó phải vỡ ra ngay lúc viết mã chứ
     * không phải lặng lẽ đúng cho tới ngày ai đó đổi một dòng.
     */
    public function toggleEmailAuthentication(bool $condition): void
    {
        throw new LogicException('Mã đăng nhập một lần của cổng khách hàng là bắt buộc (SPEC §8.1) và không tắt được.');
    }

    /**
     * SPEC §10.6: tạo và vô hiệu hoá tài khoản portal phải có dấu vết. Không log mật khẩu;
     * last_login_at/last_login_ip là dữ liệu hệ thống ghi ở mỗi lần đăng nhập, không phải
     * thay đổi nghiệp vụ, và sự kiện đăng nhập tự nó đã được ghi qua Audit::record().
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['client_id', 'name', 'email', 'phone', 'is_active', 'must_change_password', 'activated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
