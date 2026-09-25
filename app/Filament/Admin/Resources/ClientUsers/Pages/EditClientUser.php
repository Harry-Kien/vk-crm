<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Actions\Portal\UnlockPortalLogin;
use App\Actions\Portal\UnlockPortalLoginResult;
use App\Filament\Admin\Resources\ClientUsers\ClientUserResource;
use App\Models\ClientUser;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EditClientUser extends EditRecord
{
    protected static string $resource = ClientUserResource::class;

    /**
     * `unlockLogin` (Task 7, R12, phát hiện `portal/portal-4`): giống mọi action tự viết trong dự
     * án, tự hỏi Gate trong `visible()` — không tin cổng `canEdit()` ngầm định của trang, vì đây
     * không phải một field của form mà là một thao tác riêng trên `PortalLoginThrottle`. Ability
     * dùng là `unlockLogin` — một ability RIÊNG trên `ClientUserPolicy`, không phải `update` — vì
     * `HeaderActionsAreReachableTest` đòi TÊN của mọi thao tác trên thanh tiêu đề của một trang
     * Edit/List phải khớp đúng tên một phương thức policy (đã tự đo: gọi `Gate::allows('update', …)`
     * ở đây trong khi action tên `unlockLogin` làm test đó đỏ). `ClientUserPolicy::unlockLogin()`
     * hiện chỉ gọi lại `update()` (cùng biên giới), nhưng là một ability tách riêng, đổi được độc
     * lập sau này nếu luật cần khác đi.
     *
     * `Gate::authorize()` LẶP LẠI trong `action()`, không chỉ trong `visible()` — cùng thành ngữ
     * "Mọi admin action phải tự kiểm tra policy" của dự án: `visible()` chỉ quyết định có VẼ nút
     * hay không, một request Livewire bị chỉnh sửa tay (gọi thẳng `callMountedAction()`) vẫn có
     * thể bỏ qua điều kiện hiện/ẩn.
     *
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng policy của model này không định nghĩa `restore` lẫn `forceDelete`,
     * và Laravel từ chối một ability không có phương thức tương ứng khi model đã có policy — nên
     * hai nút đó luôn bị từ chối. `HeaderActionsAreReachableTest` giữ luật này cho mọi trang.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('unlockLogin')
                ->label(__('client_users.actions.unlock_login'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('unlockLogin', $this->record))
                ->action(function (): void {
                    /** @var ClientUser $account */
                    $account = $this->record;

                    Gate::authorize('unlockLogin', $account);

                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);

                    /** @var UnlockPortalLoginResult $result */
                    $result = app(UnlockPortalLogin::class)->handle($account, $actor);

                    Notification::make()
                        ->title($result->ipStillLocked
                            ? __('client_users.actions.unlock_login_success_ip_still_locked', [
                                'minutes' => $result->minutesRemaining,
                            ])
                            : __('client_users.actions.unlock_login_success'))
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * Task 2 (`roles/roles-01`, critical): bản trước gọi
     * `Gate::authorize('create', [ClientUser::class, $client])` với $client suy từ
     * `$data['client_id']` — tức chỉ xác nhận CLIENT_ID MỚI có nằm trong tầm với của người sửa
     * hay không. Với một luật sư, client_id mới nằm trong tầm với của CHÍNH HỌ (một khách khác
     * họ cũng liệt kê được) đi qua trót lọt, và tài khoản portal bị chuyển sang khách hàng khác —
     * khách cũ mất tài khoản, khách mới (kẻ tấn công chọn) thấy hồ sơ không phải của mình ngay
     * lần đăng nhập kế tiếp bằng mật khẩu cũ.
     *
     * client_id là bất biến sau khi tạo, với BẤT KỲ ai, kể cả admin — đổi khách nghĩa là tạo tài
     * khoản mới (Hành vi phải đạt, task brief). Nên câu hỏi không còn là "client_id mới có hợp lệ
     * không" mà là "bỏ qua bất kỳ client_id nào $data mang, luôn ghi đè lại đúng giá trị đang có
     * trên bản ghi" — không đọc `$data['client_id']` vào đâu cả, kể cả để so sánh.
     *
     * ClientUserForm::configure() đã `disabled()` ô này khi sửa, nhưng đó chỉ là lớp UI: Filament
     * tự cảnh báo ngay trong `CanBeDisabled::disabled()` rằng một request bị chỉnh sửa tay vẫn
     * gửi được `client_id` khác. Hàm này là lớp chặn THẬT, không phụ thuộc trạng thái `disabled()`
     * của field.
     *
     * Task 7 (R12, phát hiện `intake/intake-05`): đặt lại mật khẩu bật lại `must_change_password`
     * — trước bản sửa này, đặt lại mật khẩu qua trang sửa không tự bật lại cờ, nên một khách bị
     * lộ mật khẩu (nhân sự đặt lại, đọc cho khách qua điện thoại) tiếp tục dùng mãi mật khẩu đó
     * mà không bị bắt đổi lại. Chỉ chạm cột này khi `$data` THẬT SỰ mang một mật khẩu mới —
     * `filled()` khớp đúng điều kiện `dehydrated()` của ô password ở `ClientUserForm` (chỉ
     * dehydrate khi có gõ gì), nên "không đổi mật khẩu" không bao giờ vô tình bật lại cờ này khi
     * nhân sự chỉ sửa tên/điện thoại/is_active.
     *
     * Fix round 1 (I1): đổi EMAIL cũng đặt lại `activated_at = null` VÀ `must_change_password =
     * true`, cùng lý lẽ như đặt lại mật khẩu. `activated_at` (R12) là bằng chứng người TỰ TAY đổi
     * mật khẩu lần đầu qua đúng hộp thư đó — đổi sang một địa chỉ khác (gõ đúng hoặc gõ NHẦM lúc
     * nghe điện thoại) làm bằng chứng đó không còn nói lên gì về hộp thư MỚI, nhưng trước bản sửa
     * này `activated_at` vẫn giữ nguyên, nên `NotifyClientOfStageUpdate::eligibleRecipientsQuery()`
     * tiếp tục coi địa chỉ mới là "đã kích hoạt" và gửi `client.stage_update` (tên khách, mã hồ
     * sơ, nội dung công bố) tới một hộp thư chưa ai xác minh — đúng lỗ hổng `intake/intake-04` đã
     * lấp cho lúc TẠO, còn hở ở lúc SỬA. So với `$this->record->email`, KHÔNG với giá trị cũ của
     * `$data` (chưa có gì để so trước dòng này).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['client_id'] = $this->record->client_id;

        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $this->record->email;

        if ($emailChanged) {
            $data['activated_at'] = null;
        }

        if ($emailChanged || filled($data['password'] ?? null)) {
            $data['must_change_password'] = true;
        }

        return $data;
    }
}
