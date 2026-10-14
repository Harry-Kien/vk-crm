<?php

namespace App\Filament\Admin\Pages\Auth;

use App\Actions\Mcp\RevokeAiConnections;
use App\Enums\AiRevocationReason;
use App\Models\User;
use App\Support\Audit;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Trang hồ sơ cá nhân của nhân sự (panel `admin`, đăng ký qua `AdminPanelProvider::panel()->profile()`).
 *
 * Fix round 1 (ruling "the staff profile page"): "Filament's stock EditProfile lets staff change
 * their own login email without re-verification. Make the email field read-only on the admin
 * profile; an admin changes a staff login email through EditUser."
 *
 * # Vì sao cần chặn: bản mặc định của Filament làm gì
 *
 * `Filament\Auth\Pages\EditProfile::handleRecordUpdate()` (lớp cha) đọc:
 *
 *     if (Filament::hasEmailChangeVerification() && array_key_exists('email', $data)) {
 *         $this->sendEmailChangeVerification($record, $data['email']);
 *         unset($data['email']);
 *     }
 *     $record->update($data);
 *
 * Panel `admin` không bật xác minh đổi email (`Filament::hasEmailChangeVerification()` chỉ đúng
 * khi có cấu hình riêng, panel này không có), nên nhánh `if` không chạy và `$record->update($data)`
 * đổi thẳng `email` — TỨC ĐÚNG ĐỊA CHỈ DÙNG ĐỂ ĐĂNG NHẬP — mà không cần biết mật khẩu, không cần
 * xác minh hộp thư mới, chỉ cần một phiên `web` đang mở. Một phiên bị chiếm (máy dùng chung quên
 * đăng xuất, xem SPEC §4.3 ví dụ tương tự cho khách hàng) đủ để đổi hẳn đường vào của nạn nhân.
 *
 * Đổi email đăng nhập của MỘT nhân sự là việc của admin khác, qua `EditUser` (SPEC §5 — quản lý
 * nhân sự nằm trong `settings.manage`), không phải việc tự nhân sự làm ở trang hồ sơ của chính họ.
 *
 * # Hai lớp chặn, cố ý CHỒNG lên nhau
 *
 * `CanBeDisabled::disabled()` tự cảnh báo trong chính docblock của nó: "skilled users can
 * manipulate Livewire's JavaScript to bypass the disabled state. Always enforce authorization on
 * the backend". Cảnh báo đó đúng cho một `disabled(fn ($get) => …)` CÓ ĐIỀU KIỆN — điều kiện phụ
 * thuộc state mà một payload tinh chỉnh tay có thể ảnh hưởng. `->disabled()` ở đây KHÔNG có điều
 * kiện đó (`true` trần), nên `Schema::getState()` loại `email` khỏi `$data` VÔ ĐIỀU KIỆN — đã tự
 * đo (xem docblock test "cannot change the login email...": `->set('data.email', …)` thẳng vào
 * property Livewire vẫn không đổi được gì, kể cả khi tắt hẳn `mutateFormDataBeforeSave()` dưới
 * đây). `mutateFormDataBeforeSave()` vì vậy KHÔNG phải lớp chặn "thật duy nhất" bù cho một
 * `disabled()` chỉ-hiển-thị như ở `client_id` (`EditClientUser` — field đó CŨNG `disabled()` trần,
 * lý lẽ tương tự); nó là một lớp PHÒNG THỦ THÊM, chủ đích chồng lên `disabled()` để một lần sửa
 * sau này (đổi `->disabled()` thành có điều kiện, hoặc thêm `->dehydrated()` mà không hiểu hết hệ
 * quả) không lặng lẽ mở lại đúng lỗ hổng ruling này lấp — đo riêng, độc lập với `disabled()`, ở
 * test "the mutate hook itself resets a tampered email...", gọi thẳng hook qua reflection.
 *
 * # Phần còn lại: giữ NGUYÊN của lớp cha, không lặp lại
 *
 * - Mật khẩu mới: `getPasswordFormComponent()` của lớp cha đã tự `->rule(\Illuminate\Validation\Rules\Password::default())`
 *   — không cần ghi đè để "áp dụng" nó, chỉ cần KHÔNG ghi đè hàm đó.
 * - "Mật khẩu hiện tại" bắt buộc khi đổi mật khẩu: `getCurrentPasswordFormComponent()` của lớp cha
 *   đã tự `->required()` và tự `->currentPassword(guard: ...)`.
 * - **M8 Task 2 (R2, §10 mục 7) đính chính đoạn này**: panel `admin` GIỜ gọi
 *   `->multiFactorAuthentication()` (xem docblock `AdminPanelProvider::panel()`), nên khối 2FA
 *   CÓ vẽ ra ở đây — `getMultiFactorAuthenticationContentComponent()` của lớp cha không còn trả
 *   `null`. "Không có nút tắt" vẫn đúng, nhưng vì một lý do khác hẳn: KHÔNG PHẢI vì khối 2FA vắng
 *   mặt, mà vì `App\Filament\Admin\Auth\StaffAppAuthentication` (lớp con của
 *   `Filament\Auth\MultiFactor\App\AppAuthentication` đăng ký ở panel) tự bỏ
 *   `DisableAppAuthenticationAction` ra khỏi `getActions()` — đọc docblock lớp đó cho lý do đầy
 *   đủ. Trang này không cần biết gì thêm về 2FA: nó chỉ kế thừa nguyên bản `getMultiFactorAuthenticationContentComponent()`
 *   của lớp cha, đúng như hai mục mật khẩu ở trên.
 *
 * Hai mục mật khẩu có test đo lại QUA CHÍNH LỚP CON NÀY (không phải qua lớp cha) ở
 * `tests/Feature/Filament/StaffEditProfileTest.php`, để một lần ghi đè sau này lỡ tay xoá mất
 * `getPasswordFormComponent()`/`getCurrentPasswordFormComponent()` bị bắt ngay, không chỉ dựa vào
 * "lớp cha vẫn còn đúng" như một giả định không kiểm chứng. "Không có nút tắt 2FA" có test riêng
 * ở `tests/Feature/Filament/StaffTwoFactorEscapeRoutesTest.php` — quét đường tắt kiểu M5, không
 * phải một khẳng định "khối 2FA không tồn tại" như bản đính chính trước Task 2.
 */
class EditProfile extends BaseEditProfile
{
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->disabled()
            ->helperText(__('users.profile.email_readonly_hint'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $data['email'] = $this->getUser()->getAttributeValue('email');

        return $data;
    }

    /**
     * M11 R8 (Task 6): nhân sự tự đổi mật khẩu thì mọi kết nối AI của chính họ bị thu hồi
     * ({@see RevokeAiConnections}, lý do `password_changed`, causer là chính họ), trong CÙNG
     * transaction với lần ghi mật khẩu — lần ghi sụp thì không token nào bị thu hồi, và ngược lại.
     * `ai_access` giữ nguyên: đổi mật khẩu là chuyện thông tin đăng nhập, không phải quyền AI.
     *
     * "Có đổi mật khẩu" là đúng điều kiện lớp cha dùng cho việc cập nhật băm mật khẩu trong phiên
     * (`array_key_exists('password', $data)`): ô mật khẩu chỉ vào `$data` khi có giá trị
     * (`dehydrated(filled)`). Panel không bật `databaseTransactions()`, nên transaction ở đây là
     * transaction NGOÀI CÙNG của lần lưu; câu đầu tiên trong nó khoá dòng `users` của người đó.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            User::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            /** @var User $updated người đăng nhập panel `admin` — luôn là một `User` */
            $updated = parent::handleRecordUpdate($record, $data);

            if (array_key_exists('password', $data)) {
                // Làn fb (mục B): tự đổi mật khẩu để lại dấu vết, như lần admin đặt lại
                // (`user_password_reset`, EditUser). Không ghi mật khẩu, chỉ ghi sự kiện.
                Audit::record('user_password_changed', $updated, ['via' => 'self'], $updated);

                app(RevokeAiConnections::class)->handle($updated, AiRevocationReason::PasswordChanged, $updated);
            }

            return $updated;
        });
    }
}
