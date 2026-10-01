<?php

namespace App\Actions\User;

use App\Enums\UserPosition;
use App\Exceptions\AdminCreationRefused;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use SensitiveParameter;

/**
 * Tạo một quản trị viên từ dòng lệnh — nghiệp vụ của `vkcrm:create-admin`
 * (`App\Console\Commands\CreateAdminCommand`, kế hoạch M8 Task 7; phán quyết sổ M6.5 "M8b prep
 * (b2)").
 *
 * Từ M6.5 Task 19, seed production (`db:seed --force`) chỉ tạo dữ liệu tham chiếu, không tạo tài
 * khoản nào: trên một máy chủ mới, đây là cách DUY NHẤT để có người đầu tiên vào được `/admin`.
 * Người chạy lệnh đã có quyền vào máy chủ, tức đã ở trong vòng tin cậy (cùng tinh thần
 * `vkcrm:reset-2fa`), nên không có ai để "phân quyền" ở đây — chỉ có các luật dưới đây.
 *
 * # Luật
 *
 * - **Đã có quản trị viên thì từ chối**, nêu số lượng, trừ khi người gọi nói rõ `$additional`
 *   (cờ `--additional` của lệnh). "Có quản trị viên" = một dòng `users` CHƯA xoá mềm mang chức
 *   danh {@see UserPosition::Admin}, dù đang hoạt động hay đã bị vô hiệu hoá: một admin bị vô hiệu
 *   hoá vẫn là người văn phòng đã chọn, kích hoạt lại họ là việc của màn hình Nhân sự, không phải
 *   một tài khoản mới. Admin đã xoá mềm không tính — họ không bao giờ đăng nhập lại được.
 * - **Họ tên ≤ 100, email ≤ 150 ký tự** — đúng độ dài cột `users.name`/`users.email` (MariaDB
 *   strict ném lỗi thay vì cắt bớt). Email đúng dạng và **chưa thuộc ai, kể cả nhân sự đã xoá
 *   mềm**: chỉ mục unique của `users.email` phủ cả dòng đã xoá, nên không kiểm `withTrashed()` thì
 *   lần chèn ném lỗi unique (một trang lỗi 500 trong console) thay vì một câu tiếng Việt.
 * - **Mật khẩu theo `PasswordRule::default()`** — CÙNG luật với form nhân sự (`UserForm`); dự án
 *   hôm nay không đặt `Password::defaults()`, nên đó là mặc định của Laravel (tối thiểu 8 ký tự),
 *   và ngày ai đó siết luật mặc định thì lệnh này siết theo. Tối đa 255 ký tự như form.
 * - **Không secret 2FA.** Tài khoản ra đời KHÔNG có `two_factor_secret`, nên lần đăng nhập đầu
 *   Filament dồn họ tới trang cài 2FA bắt buộc (`isRequired: true`, Task 2) — không có đường nào
 *   để một admin mới dùng hệ thống mà chưa cài 2FA.
 * - **Nhật ký** `admin_created_via_console`: chủ thể là tài khoản mới, người thực hiện `null`
 *   (không ai đăng nhập trong console), `via = console`, cờ `additional` và số admin có từ trước
 *   (`admins_before`). Không bao giờ mang mật khẩu; dòng `created` do `LogsActivity` của
 *   {@see User} tự ghi thì không có cột mật khẩu trong `logOnly`.
 *
 * Ba hàm `*Error()` là các luật kiểm TỪNG Ô, để lệnh hỏi một ô rồi từ chối ngay khi ô đó sai (không
 * bắt người vận hành gõ mật khẩu hai lần cho một email đã có người dùng). {@see self::handle()} chạy
 * lại cả ba — nó là chốt chặn duy nhất có thẩm quyền, không tin rằng người gọi đã kiểm.
 *
 * Lần đếm admin và lần chèn nằm chung một transaction, nhưng không khoá gì: hai người cùng chạy
 * lệnh này trong cùng một giây trên một hệ thống trống có thể cùng qua lần đếm. Chấp nhận được với
 * một lệnh chạy MỘT lần lúc dựng máy chủ — và hai lần chạy trùng email thì lần sau vấp chỉ mục
 * unique, không tạo được hai tài khoản trùng email.
 */
final class CreateAdminFromConsole
{
    /** Số quản trị viên CHƯA xoá mềm, đang hoạt động hay không. */
    public function existingAdminCount(): int
    {
        return User::query()->where('position', UserPosition::Admin)->count();
    }

    public function nameError(string $name): ?string
    {
        return $this->firstError(['name' => trim($name)], ['name' => ['required', 'string', 'max:100']]);
    }

    public function emailError(string $email): ?string
    {
        $email = trim($email);

        $formatError = $this->firstError(['email' => $email], ['email' => ['required', 'string', 'email', 'max:150']]);

        if ($formatError !== null) {
            return $formatError;
        }

        $owner = User::withTrashed()->where('email', $email)->first();

        if ($owner === null) {
            return null;
        }

        return __($owner->trashed() ? 'users.create_admin.email_taken_trashed' : 'users.create_admin.email_taken', [
            'email' => $email,
        ]);
    }

    public function passwordError(#[SensitiveParameter] string $password): ?string
    {
        return $this->firstError(
            ['password' => $password],
            ['password' => ['required', 'string', 'max:255', PasswordRule::default()]],
        );
    }

    /**
     * @throws AdminCreationRefused khi một ô sai, hoặc khi đã có quản trị viên mà không có `$additional`.
     */
    public function handle(string $name, string $email, #[SensitiveParameter] string $password, bool $additional): User
    {
        $name = trim($name);
        $email = trim($email);

        foreach ([$this->nameError($name), $this->emailError($email), $this->passwordError($password)] as $error) {
            if ($error !== null) {
                throw new AdminCreationRefused($error);
            }
        }

        return DB::transaction(function () use ($name, $email, $password, $additional): User {
            $adminsBefore = $this->existingAdminCount();

            if ($adminsBefore > 0 && ! $additional) {
                throw AdminCreationRefused::adminsExist($adminsBefore);
            }

            $admin = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'position' => UserPosition::Admin,
                'is_active' => true,
            ]);

            $admin->assignRoleFromPosition();

            Audit::record('admin_created_via_console', $admin, [
                'via' => 'console',
                'additional' => $additional,
                'admins_before' => $adminsBefore,
            ]);

            return $admin;
        });
    }

    /**
     * Lỗi đầu tiên của một ô, bằng câu của `lang/vi/validation.php` với tên ô tiếng Việt.
     *
     * @param  array<string, string>  $data
     * @param  array<string, list<mixed>>  $rules
     */
    private function firstError(array $data, array $rules): ?string
    {
        $validator = Validator::make($data, $rules, [], [
            'name' => __('users.create_admin.attributes.name'),
            'email' => __('users.create_admin.attributes.email'),
            'password' => __('users.create_admin.attributes.password'),
        ]);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
