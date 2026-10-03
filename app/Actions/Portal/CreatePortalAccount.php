<?php

namespace App\Actions\Portal;

use App\Models\ClientUser;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Tạo một tài khoản cổng khách hàng và ghi `portal_account_created` (SPEC §10.6, M8 Task 3).
 *
 * Trước task này việc tạo đi thẳng qua `CreateRecord` của Filament: chỉ có dòng `created` của
 * `LogsActivity` (chủ thể `client_user`, kèm mọi thuộc tính được theo dõi dưới dạng diff). Sự
 * kiện tường minh cho người đọc nhật ký một dòng nói đúng việc đã xảy ra — "văn phòng cấp cho
 * khách này một tài khoản" — không phải giải mã một diff. Dòng `created` vẫn còn đó cạnh nó.
 *
 * `$attributes` là dữ liệu đã qua form và `CreateClientUser::mutateFormDataBeforeCreate()` (ép
 * `must_change_password = true`, kiểm `Gate` cho khách hàng đích) — Action này KHÔNG lặp lại các
 * luật ấy, và không nhận mật khẩu ở đâu ngoài `$attributes['password']` (cast `hashed` của model
 * băm nó). Mật khẩu (thô hay đã băm) không bao giờ vào `properties` của nhật ký.
 *
 * Tạo và ghi nhật ký cùng một transaction: không có tài khoản nào tồn tại mà không có dòng nhật
 * ký của nó, và ngược lại.
 */
final class CreatePortalAccount
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): ClientUser
    {
        return DB::transaction(function () use ($attributes, $actor): ClientUser {
            $account = ClientUser::query()->create($attributes);

            Audit::record('portal_account_created', $account, [
                'client_id' => $account->client_id,
            ], $actor);

            return $account;
        });
    }
}
