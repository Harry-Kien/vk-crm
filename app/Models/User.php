<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\UserPosition;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: gọi auth() trong global scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'position',
        'bar_number',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'position' => UserPosition::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function leadMatters(): HasMany
    {
        return $this->hasMany(Matter::class, 'lead_lawyer_id');
    }

    public function teamMatters(): BelongsToMany
    {
        return $this->belongsToMany(Matter::class, 'matter_user')
            ->using(MatterUser::class)
            ->withPivot('role_in_matter')
            ->withTimestamps();
    }

    /**
     * Nhân sự chỉ vào được panel nội bộ, và chỉ khi tài khoản còn hoạt động.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->is_active;
    }

    /**
     * Gán vai trò khớp chức danh. Vai trò và chức danh là hai khái niệm khác nhau nhưng
     * ở bản 1.0 luôn trùng giá trị; Action sửa nhân sự (M3) phải gọi lại hàm này.
     *
     * Tự tạo dòng vai trò nếu chưa có, để seeder hoặc lệnh chạy lẻ không ném RoleDoesNotExist.
     * Vai trò rỗng quyền là trạng thái an toàn (không cho gì), và sẽ được
     * RolesAndPermissionsSeeder điền quyền đúng khi chạy.
     */
    public function assignRoleFromPosition(): void
    {
        $role = Role::fromPosition($this->position);

        SpatieRole::findOrCreate($role->value, 'web');

        $this->syncRoles([$role->value]);
    }
}
