<?php

namespace App\Filament\Admin\Resources\ClientUsers\Pages;

use App\Actions\Client\IssuePortalAccess;
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
     * Task 3: `mutateFormDataBeforeSave()` biết email có đổi hay không (đã đọc `$this->record`
     * TRƯỚC khi ghi đè), nhưng lúc đó bản ghi CHƯA lưu — gọi `IssuePortalAccess` ở đó sẽ khoá
     * dòng NGAY TRONG transaction lưu form của Filament, một transaction lồng không cần thiết.
     * Cờ này mang quyết định đó sang `afterSave()`, nơi `$this->record` đã là bản ghi MỚI.
     */
    private bool $reissueAccessAfterSave = false;

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
            $this->reissueAccessAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * `reissueAccess` (Task 3): con đường DUY NHẤT nhân sự cấp lại quyền truy cập từ trang sửa,
     * từ khi ô mật khẩu bị gỡ khỏi form. Ability RIÊNG trên `ClientUserPolicy` (cùng
     * `unlockLogin`), cùng biên giới với `update()` — không mở rộng ai làm được việc này so với
     * ai sửa được thông tin tài khoản.
     *
     * **Fix round 1 (finding Important 1).** Bản trước hiện toast thành công VÔ ĐIỀU KIỆN dù bấm
     * trên một tài khoản `is_active = false` (hoặc khách đã xoá mềm) — `IssuePortalAccess` âm
     * thầm không gửi gì (đúng {@see IssuePortalAccess::isEligible()}), nhưng người bấm không biết.
     * Hai lớp, không chỉ một:
     *  1. `->disabled()` + `->tooltip()`: nút vẫn HIỆN (vẫn hữu ích để thấy nó tồn tại) nhưng
     *     không bấm được khi tài khoản chưa đủ điều kiện, kèm một câu giải thích tại sao — thay vì
     *     ẩn hẳn, khiến người dùng không hiểu vì sao đường "cấp lại mật khẩu" biến mất. Filament tự
     *     chặn `callMountedAction()` cho một action `isDisabled()` (đã đo bằng test:
     *     `ClientUserResourceTest.php`, "disables reissue access…"), nên đây là lớp chặn THẬT, không
     *     chỉ trang trí.
     *  2. `action()` vẫn tự hỏi LẠI cùng điều kiện qua `$result->issued` (trường của
     *     `\App\Actions\Client\IssuePortalAccessResult` mà `IssuePortalAccess::handle()` trả về)
     *     trước khi chọn câu toast, thay vì tin biến `$this->canReissueAccess()` đã dùng để vẽ nút —
     *     cùng thành ngữ "mọi admin action tự kiểm tra lại, không tin trạng thái đã vẽ" của dự án.
     *     Lớp 1 đã chặn hết đường vào bình thường, nhưng nếu một bản sửa sau này gỡ `->disabled()`
     *     mà quên gỡ luôn nhánh này, toast vẫn nói thật thay vì im lặng quay lại lời hứa giả.
     */
    private function reissueAccessAction(): Action
    {
        return Action::make('reissueAccess')
            ->label(__('client_users.actions.reissue_access'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('client_users.actions.reissue_access_heading'))
            ->visible(fn (): bool => Gate::allows('reissueAccess', $this->record))
            ->disabled(fn (): bool => ! $this->canReissueAccess($this->record))
            ->tooltip(fn (): ?string => $this->canReissueAccess($this->record)
                ? null
                : __('client_users.actions.reissue_access_disabled_hint'))
            ->action(function (): void {
                /** @var ClientUser $account */
                $account = $this->record;

                Gate::authorize('reissueAccess', $account);

                $actor = Auth::user();
                abort_unless($actor instanceof User, 403);

                $result = app(IssuePortalAccess::class)->handle($account, $actor);

                Notification::make()
                    ->title($result->issued
                        ? __('client_users.actions.reissue_access_success')
                        : __('client_users.actions.reissue_access_not_eligible'))
                    ->color($result->issued ? 'success' : 'danger')
                    ->send();
            });
    }

    /**
     * CÙNG câu hỏi mà `IssuePortalAccess::isEligible()` tự hỏi lại dưới khoá lúc `handle()` chạy —
     * đọc trên bản ghi CHƯA khoá ở đây chỉ để quyết định VẼ nút, không phải để thay cho lần đọc
     * thật (đó là lý do `action()` bên trên vẫn đọc `$result->issued` thay vì tin biến này).
     */
    private function canReissueAccess(ClientUser $account): bool
    {
        return IssuePortalAccess::isEligible($account);
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
     * **Task 3 — ô mật khẩu không còn nữa.** "Đặt lại mật khẩu bật must_change_password" (Task 7
     * cũ) giờ là việc của nút "Cấp lại mật khẩu" ({@see self::reissueAccessAction()}), không phải
     * của form sửa này — không còn `$data['password']` nào để mà đọc.
     *
     * Fix round 1 (I1), vẫn còn hiệu lực: đổi EMAIL cũng đặt lại `activated_at = null` VÀ
     * `must_change_password = true`, VÀ (Task 3, đề xuất setup agent) cấp một mật khẩu tạm MỚI
     * qua `IssuePortalAccess` — địa chỉ MỚI chưa ai xác minh, nên nó cần chính thư kích hoạt để
     * chứng minh, giống một tài khoản vừa tạo. `activated_at` (R12) là bằng chứng người TỰ TAY đổi
     * mật khẩu lần đầu qua đúng hộp thư đó — đổi sang một địa chỉ khác (gõ đúng hoặc gõ NHẦM lúc
     * nghe điện thoại) làm bằng chứng đó không còn nói lên gì về hộp thư MỚI, nhưng trước bản sửa
     * này `activated_at` vẫn giữ nguyên, nên `NotifyClientOfStageUpdate::eligibleRecipientsQuery()`
     * tiếp tục coi địa chỉ mới là "đã kích hoạt" và gửi `client.stage_update` (tên khách, mã hồ
     * sơ, nội dung công bố) tới một hộp thư chưa ai xác minh — đúng lỗ hổng `intake/intake-04` đã
     * lấp cho lúc TẠO, còn hở ở lúc SỬA. So với `$this->record->email`, KHÔNG với giá trị cũ của
     * `$data` (chưa có gì để so trước dòng này).
     *
     * **Rà soát Task 7 (M6.5 Task 5): so sánh CASE-FOLD (`mb_strtolower`), không phải `!==` trên
     * chuỗi thô.** Một lần sửa chỉ đổi HOA/THƯỜNG (`Nam@x.vn` → `nam@x.vn`, gõ lại vì quen tay lúc
     * nghe điện thoại) là CÙNG một hộp thư — cùng lần khách đã tự tay đổi mật khẩu lần đầu để xác
     * minh nó — nhưng so sánh thô coi đó là "đổi email" và xoá `activated_at` một cách sai lệch,
     * dừng oan thư `client.stage_update` cho một hộp thư khách vẫn đang dùng.
     *
     * **Fix round 1, finding minor: KHÔNG dùng `PortalLoginThrottle::foldEmail()`.** Hàm đó tự
     * nhận nó không phải một phép so sánh định danh (xem docblock của chính nó) — nó NFD-tách dấu
     * rồi bỏ dấu đi, vì việc DUY NHẤT nó tồn tại để làm là dựng một khoá throttle ổn định cho một
     * chuỗi KHÔNG tra ra tài khoản nào. Dùng nó ở đây sẽ coi `a@thu.vn` và `a@thú.vn` — hai địa
     * chỉ THẬT SỰ khác nhau, không chỉ khác hoa/thường — là "cùng một email", giữ nguyên
     * `activated_at` một cách SAI cho một hộp thư khác hẳn. So sánh đúng ở đây chỉ cần gấp CASE,
     * không tách dấu — `mb_strtolower()`.
     *
     * **Fix round 1 (finding Important 1) — bật lại `is_active` cho một tài khoản chưa từng kích
     * hoạt.** Trước bản sửa này, tài khoản tạo với `is_active` tắt (hoặc bị tắt sau đó) không bao
     * giờ nhận được thư `client.activation` — `CreateClientUser::afterCreate()` gọi
     * `IssuePortalAccess` khi tài khoản còn `is_active = false` thì bị bỏ qua (đúng, sau fix round
     * 1 finding Important 1), nhưng KHÔNG có gì gọi lại nó khi nhân sự bật `is_active` lên SAU đó
     * — khách mới không bao giờ có mật khẩu để đăng nhập trừ khi ai đó nhớ bấm "Cấp lại mật khẩu"
     * thủ công. Chỉ áp dụng khi `activated_at` CÒN NULL (chưa từng tự đổi mật khẩu lần đầu): một
     * tài khoản đã từng kích hoạt rồi bị khoá rồi mở lại vẫn còn mật khẩu cũ, không cần thư mới.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['client_id'] = $this->record->client_id;

        $emailChanged = array_key_exists('email', $data)
            && mb_strtolower($data['email']) !== mb_strtolower($this->record->email);

        if ($emailChanged) {
            $data['activated_at'] = null;
            $data['must_change_password'] = true;
        }

        $reactivatedNeverActivated = array_key_exists('is_active', $data)
            && (bool) $data['is_active']
            && ! $this->record->is_active
            && $this->record->activated_at === null;

        // Xem docblock thuộc tính `$reissueAccessAfterSave` cho lý do hoãn việc gọi
        // `IssuePortalAccess` sang `afterSave()`.
        $this->reissueAccessAfterSave = $emailChanged || $reactivatedNeverActivated;

        return $data;
    }

    /**
     * Task 3 (đề xuất setup agent, xem docblock `mutateFormDataBeforeSave()`): email đổi (hoặc,
     * fix round 1, `is_active` bật lại cho một tài khoản chưa từng kích hoạt) thì cần một thư
     * kích hoạt để chứng minh/mở đường vào hộp thư, cùng lý lẽ một tài khoản vừa tạo — không làm
     * việc này, tài khoản đó có `must_change_password = true` NHƯNG không có mật khẩu tạm nào
     * được gửi, tức không có cách nào để khách đăng nhập và tự đổi mật khẩu.
     *
     * Bỏ qua kết quả trả về ở đây (không có toast riêng cho lần lưu form). `issued` KHÔNG luôn
     * true: `mutateFormDataBeforeSave()` không hỏi `IssuePortalAccess::isEligible()` — chỉ
     * `IssuePortalAccess::handle()` tự hỏi, dưới khoá dòng. Kết quả tuỳ tài khoản SAU lần lưu:
     *  - đang bật (đổi email trên tài khoản đang bật, hoặc vừa bật lại `is_active`): `issued` là
     *    true trừ khi khách hàng đã bị xoá mềm;
     *  - đổi email trên một tài khoản đang TẮT (`is_active = false`): `issued` là false, KHÔNG thư
     *    nào đi lúc lưu, và màn hình không báo gì. Thư không mất hẳn: đổi email đã đặt
     *    `activated_at = null`, nên lần bật `is_active` lại sau đó rơi vào nhánh "chưa từng kích
     *    hoạt" ở trên và gửi thư kích hoạt tới địa chỉ MỚI — ghim bằng test "sends no activation
     *    mail for an email change on an inactive account until staff turns it back on"
     *    (`ClientUserResourceTest`).
     */
    protected function afterSave(): void
    {
        if (! $this->reissueAccessAfterSave) {
            return;
        }

        $this->reissueAccessAfterSave = false;

        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        app(IssuePortalAccess::class)->handle($this->record, $actor);
    }
}
