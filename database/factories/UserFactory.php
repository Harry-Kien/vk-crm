<?php

namespace Database\Factories;

use App\Enums\AiAccessMode;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Models\AiAcknowledgement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * M8 Task 2 (kế hoạch, "sẽ cắn" #3): panel `admin` bắt buộc 2FA (`isRequired: true`,
     * `AdminPanelProvider`), nên MỌI nhân sự do factory sinh ra phải có sẵn secret hợp lệ — không
     * có nó, `EnsureMultiFactorAuthenticationIsEnabled` chuyển hướng gần 2.400 test của panel
     * admin sang trang cài đặt bắt buộc thay vì trang chúng đang đo. Sinh bằng CHÍNH API của
     * `pragmarx/google2fa` (`AppAuthentication::generateSecret()` gọi cùng hàm) để secret luôn là
     * một base32 hợp lệ, không phải một chuỗi tự bịa. Cache tĩnh — cùng thành ngữ `$password` ở
     * trên — vì hàng nghìn user không cần secret PHÂN BIỆT nhau, chỉ cần một secret HỢP LỆ.
     */
    protected static ?string $twoFactorSecret;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('09########'),
            'position' => UserPosition::Lawyer,
            'is_active' => true,
            'remember_token' => Str::random(10),
            'two_factor_secret' => static::$twoFactorSecret ??= app(Google2FA::class)->generateSecretKey(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Đường "chưa cài 2FA" (nhân sự mới, hay vừa bị admin đặt lại — {@see
     * \App\Actions\User\ResetStaffTwoFactor}): panel admin chuyển hướng người này sang trang cài
     * đặt bắt buộc thay vì trang họ định vào. Dùng ở test cho đúng đường đó, thay vì `forceFill`
     * rải rác từng nơi.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->position(UserPosition::Admin)->withRole(Role::Admin);
    }

    public function position(UserPosition $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    /**
     * M11 R2/R12 (Task 6): nhân sự đã được quản trị bật truy cập qua AI ở chế độ `$mode` VÀ đã cam
     * kết chính sách dùng AI đúng phiên bản hiện hành — hai trong bốn điều kiện `EnsureMcpAccess`
     * kiểm ở mỗi request `/mcp` (hai điều kiện còn lại: tài khoản đang hoạt động, mặc định của
     * factory; công tắc toàn hệ thống, `Tests\Support\McpOAuth::openServer()`).
     *
     * Đi vòng `SetUserAiAccess` có chủ đích: factory dựng TRẠNG THÁI, không thay Action (Action
     * từ chối người thiếu `matter.view`; test cần dựng được cả trạng thái sai đó để chứng minh
     * middleware vẫn chặn).
     */
    public function withAiAccess(AiAccessMode $mode = AiAccessMode::Read): static
    {
        return $this->state(fn (): array => ['ai_access' => $mode])
            ->afterCreating(function (User $user): void {
                AiAcknowledgement::factory()->for($user)->create();
            });
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            SpatieRole::findOrCreate($role->value, 'web');
            $user->syncRoles([$role->value]);
        });
    }
}
