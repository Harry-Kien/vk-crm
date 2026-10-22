<?php

namespace App\Actions\Portal;

use App\Actions\Push\ForgetPushDevice;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Xoá (mềm) một tài khoản cổng — nút "Xoá" trên trang sửa và thao tác hàng loạt của bảng tài khoản cổng.
 * Rà soát 2026-10-22 (làn portal): xoá phải cắt phiên và gỡ máy NGAY ({@see EndPortalSessions}); trước bản
 * này "Khôi phục" (`RestoreBulkAction`) trước request kế tiếp của trình duyệt cũ trả lại nguyên phiên cũ.
 * Quyền do người gọi hỏi (`ClientUserPolicy::delete`, Filament `authorizeIndividualRecords`).
 */
final class DeletePortalAccount
{
    public function handle(ClientUser $account, User $actor): void
    {
        DB::transaction(function () use ($account, $actor): void {
            $locked = ClientUser::query()->whereKey($account->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            app(EndPortalSessions::class)->handle($locked, $actor, ForgetPushDevice::REASON_PORTAL_DELETED);

            $locked->delete();
        });
    }
}
