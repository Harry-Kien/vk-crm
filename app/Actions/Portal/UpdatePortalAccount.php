<?php

namespace App\Actions\Portal;

use App\Actions\Push\ForgetPushDevice;
use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cập nhật một tài khoản cổng khách hàng từ trang sửa của nhân sự, và ghi
 * `portal_account_deactivated` khi `is_active` đổi từ `true` sang `false` (SPEC §10.6, M8 Task 3).
 *
 * Trước task này việc vô hiệu hoá chỉ để lại dòng `updated` của `LogsActivity` với một diff
 * `is_active: true → false` lẫn giữa các thuộc tính khác. Sự kiện tường minh nói đúng việc đã xảy
 * ra. Dòng `updated` vẫn còn đó cạnh nó.
 *
 * Đọc lại `is_active` từ dòng đã KHOÁ (`lockForUpdate()`), không từ bản ghi Filament truyền vào
 * (có thể cũ hơn CSDL): hai nhân sự cùng bấm "tắt" gần như đồng thời xếp hàng, và chỉ người thật sự
 * làm đổi trạng thái mới sinh dòng nhật ký — lượt thứ hai thấy `is_active` đã là `false` nên không
 * ghi dòng thứ hai cho một việc không xảy ra.
 *
 * Bật lại (`false` → `true`) không có sự kiện tường minh — SPEC §10.6 chỉ đòi "tạo / vô hiệu
 * hoá"; nó vẫn hiện ở dòng `updated`. `$attributes` là dữ liệu đã qua form và
 * `EditClientUser::mutateFormDataBeforeSave()` (ép `client_id` bất biến, đặt lại
 * `must_change_password`/`activated_at`).
 *
 * # Đổi email gỡ mọi máy nhận thông báo đẩy (việc sau gộp M12, làn fu4, mục 1)
 *
 * Một email cổng mới là một người giữ MỚI, chưa xác minh — cùng lý lẽ `EditClientUser` đặt lại
 * `activated_at` và gửi mật khẩu tạm tới địa chỉ mới. Máy đã bật trước đó là máy của người giữ CŨ:
 * để lại thì khi người mới kích hoạt, máy cũ nhận lại mọi push của tài khoản (tiến độ, tài liệu, trả
 * lời, mỗi cái một liên kết `/portal/ho-so/{id}`) trong khi thư chỉ đi tới địa chỉ mới — trái R10.
 * Nên một lần lưu đổi email (CÙNG luật gấp chữ {@see self::changesEmail()} mà trang sửa dùng) gọi
 * {@see ForgetPushDevice::all()} với lý do `email_changed`, mỗi máy một dòng `push_device_removed`.
 *
 * Luật nằm ở Action này, không ở trang Filament: đây là lối DUY NHẤT ghi email của một tài khoản cổng
 * đã có, nên mọi đường đổi email đều đi qua nó. So email cũ trên dòng đã KHOÁ. Gỡ chạy SAU commit
 * (`DB::afterCommit` — sau transaction NGOÀI CÙNG, kể cả khi người gọi bọc thêm một transaction):
 * lần lưu rollback thì máy còn nguyên; đổi email không chờ được việc dọn máy.
 *
 * ## Phiên của người giữ cũ chết NGAY trong cùng transaction (fu4, fix round 1)
 *
 * Gỡ máy thôi chưa đủ. Mật khẩu tạm của người giữ mới chỉ được ghi khi `SendPortalActivationMail`
 * chạy (lượt `queue.drain` kế tiếp, khoảng một phút, lâu hơn nếu cron trễ), và tới lúc đó phiên cổng
 * đang mở của người giữ CŨ vẫn hợp lệ: `AuthenticateSession` chỉ so băm mật khẩu. Phiên đó bị
 * `RequirePortalPasswordChange` đưa tới `ChangePassword` — trang không hỏi mật khẩu hiện tại và ghi
 * `activated_at` — nên người giữ cũ "kích hoạt" được địa chỉ mới chưa ai xác minh (R12), bật lại máy
 * của mình, và máy đó nhận push mãi (R10).
 *
 * Nên cùng lần lưu đổi email, trong CÙNG transaction, mật khẩu thành một chuỗi ngẫu nhiên không ai
 * biết: request đầy đủ kế tiếp của phiên cũ lệch băm và bị `AuthenticateSession` đăng xuất (sự kiện
 * `CurrentDeviceLogout` — `ForgetPushDeviceOnLogout` gỡ máy của phiên đó nếu có); form đổi mật khẩu
 * đã mở sẵn thì `ChangePassword::changePassword()` tự so băm trong phiên và từ chối (request cập nhật
 * Livewire không chạy `AuthenticateSession`). Người giữ mới không mất gì: mật khẩu của họ là mật khẩu
 * tạm mà job ghi đè lên chuỗi này. Tài khoản không đủ điều kiện nhận thư kích hoạt (đã tắt, khách đã
 * xoá mềm) thì đứng với chuỗi ngẫu nhiên cho tới lần cấp lại — đúng ý: chưa ai được dùng nó.
 */
final class UpdatePortalAccount
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ClientUser $account, array $attributes, User $actor): ClientUser
    {
        return DB::transaction(function () use ($account, $attributes, $actor): ClientUser {
            /** @var ClientUser $locked */
            $locked = ClientUser::query()->withTrashed()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $wasActive = (bool) $locked->is_active;
            $emailChanged = array_key_exists('email', $attributes)
                && self::changesEmail((string) $locked->email, $attributes['email']);

            if ($emailChanged) {
                // Cùng một câu UPDATE với email mới; `password` cast `hashed` băm chuỗi này.
                $account->forceFill(['password' => Str::random(64)]);
            }

            $account->update($attributes);

            if ($wasActive && ! $account->is_active) {
                Audit::record('portal_account_deactivated', $account, [
                    'client_id' => $account->client_id,
                ], $actor);
            }

            if ($emailChanged) {
                DB::afterCommit(fn () => app(ForgetPushDevice::class)
                    ->all($account, $actor, ForgetPushDevice::REASON_EMAIL_CHANGED));
            }

            return $account;
        });
    }

    /**
     * `$new` có phải một HỘP THƯ khác `$current` không: so sánh gấp hoa/thường (`mb_strtolower`),
     * không tách dấu — `Nam@x.vn` và `nam@x.vn` là cùng hộp thư, `a@thu.vn` và `a@thú.vn` thì không
     * (lý lẽ đầy đủ ở docblock `EditClientUser::mutateFormDataBeforeSave()`). MỘT định nghĩa cho trang
     * sửa (đặt lại kích hoạt, hỏi xác nhận) và cho Action này (gỡ máy).
     */
    public static function changesEmail(string $current, mixed $new): bool
    {
        return mb_strtolower((string) $new) !== mb_strtolower($current);
    }
}
