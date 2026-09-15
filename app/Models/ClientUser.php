<?php

namespace App\Models;

use Database\Factories\ClientUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: gọi auth() trong global scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class ClientUser extends Authenticatable implements FilamentUser
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
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->is_active;
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
