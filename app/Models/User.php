<?php

namespace App\Models;

use App\Actions\User\ResetStaffTwoFactor;
use App\Enums\Role;
use App\Enums\UserPosition;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;
use SensitiveParameter;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\Traits\HasRoles;

/**
 * Cố ý KHÔNG dùng RestrictedToClientPortal: gọi auth() trong global scope của chính model xác
 * thực sẽ đệ quy vô hạn khi guard nạp người dùng từ session.
 *
 * M8 Task 2 (R2, §10 mục 7): implement HAI interface của Filament 5 để bật 2FA ứng dụng
 * (`AppAuthentication`, đăng ký ở `AdminPanelProvider`) — KHÔNG dùng hai trait
 * `Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication(Recovery)` đi kèm hai
 * interface đó: chúng đọc/ghi hai cột `app_authentication_secret`/`app_authentication_recovery_codes`,
 * còn migration `0001_01_01_000000_create_users_table.php` (có từ M0, trước cả M8) đã đặt tên hai
 * cột này là `two_factor_secret`/`two_factor_recovery_codes`. Bốn phương thức dưới đây tự ánh xạ
 * sang đúng tên cột đó thay vì đổi tên cột — đổi tên cột đòi một migration đổi tên chạy trên dữ
 * liệu production, trong khi bốn phương thức nhỏ này làm xong đúng việc migration đó sẽ làm.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    // M12 R8 — thiết bị nhận thông báo đẩy của CHÍNH người này (`pushSubscriptions()`); màn hình chỉ
    // chạm bảng đăng ký qua quan hệ này (tests/Feature/Push/PushSubscriptionAccessTest.php).
    use HasPushSubscriptions;
    use HasRoles;
    use LogsActivity;
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
            'session_epoch' => 'integer',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // R2: APP_KEY giờ mã hoá cả secret 2FA của mọi nhân sự — mất APP_KEY là mọi nhân sự bị
            // khoá ngoài (docs/CAI-DAT.md, cảnh báo APP_KEY). Chưa đường nào từng GHI hai cột này
            // trước Task 2 (2FA chưa từng bật ở panel admin — xem ledger m8b, "phán quyết
            // migration"), nên không có bản rõ cũ nào cần một migration chuyển đổi.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
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
     * Doanh thu ghi cho người này (`payments.attributed_lawyer_id`) — chốt tại LÚC THU, không
     * dời theo bàn giao vụ việc sau đó (P2, M9 sổ controller câu hỏi 3).
     */
    public function attributedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'attributed_lawyer_id');
    }

    /**
     * Khung cho tính phí theo giờ giai đoạn 2 (SPEC §15, M9 Task 12 — chỉ khung). Xem docblock
     * {@see TimeEntry}.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
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

    /**
     * Bốn phương thức dưới đây implement {@see HasAppAuthentication}/{@see HasAppAuthenticationRecovery}
     * — xem docblock lớp cho lý do tự viết thay vì dùng trait có sẵn của Filament.
     *
     * `saveAppAuthenticationSecret(null)` — CHỈ được gọi từ {@see ResetStaffTwoFactor}
     * trong `app/`. Đây là lời hứa quét được: R2 ("không có tuỳ chọn tắt") nghĩa là không route,
     * không action, không cột nào khác được phép xoá secret của một người — xem
     * `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php`, mục "§10.7".
     */
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->two_factor_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->two_factor_recovery_codes = $codes;
        $this->save();
    }

    /**
     * SPEC §10.6: đổi phân quyền phải có dấu vết (roles ghi qua spatie/laravel-permission
     * riêng, đây là các cột thuộc chính bản ghi User). Không log mật khẩu; last_login_at là
     * dữ liệu hệ thống ghi ở mỗi lần đăng nhập, sự kiện đăng nhập tự nó được ghi qua
     * Audit::record().
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'position', 'bar_number', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
