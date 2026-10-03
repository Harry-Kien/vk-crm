<?php

namespace App\Actions\Portal;

use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

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

            $account->update($attributes);

            if ($wasActive && ! $account->is_active) {
                Audit::record('portal_account_deactivated', $account, [
                    'client_id' => $account->client_id,
                ], $actor);
            }

            return $account;
        });
    }
}
