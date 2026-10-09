<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Actions\User\DeleteStaffMember;
use App\Actions\User\RecordStaffPermissionChange;
use App\Actions\User\ResetStaffTwoFactor;
use App\Actions\User\SuspendStaffAccess;
use App\Actions\User\UnlockStaffLogin;
use App\Enums\AiRevocationReason;
use App\Enums\Role;
use App\Enums\UserPosition;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Pages\BulkReassign;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenWork;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use GuardsStaffOffboarding;
    use ReportsActionFailures;

    protected static string $resource = UserResource::class;

    /**
     * **Ghim `false`, không để `null` rơi về mặc định của panel (I2.4, fix round 2).** Panel admin
     * (`AdminPanelProvider`) không gọi `->databaseTransactions()`, nên `hasDatabaseTransactions()`
     * của `CanUseDatabaseTransactions` (trait Filament tự trộn vào MỌI trang) rơi về `false` —
     * `beginDatabaseTransaction()`/`commitDatabaseTransaction()` của `EditRecord::save()` đều là
     * no-op hôm nay. Bản Round 1 từng nói SAI ở đây (xem sửa docblock ngay dưới
     * `handleRecordUpdate()`): tưởng Filament tự mở một transaction NGOÀI rồi chạy một câu đọc trần
     * (kiểm tra `unique` email) TRƯỚC KHI hook này chạy, làm bẩn snapshot REPEATABLE READ của
     * `Cache::lock`/`lockForUpdate()` bên dưới. Đã đọc lại
     * `vendor/filament/filament/src/Pages/Concerns/CanUseDatabaseTransactions.php` để xác nhận:
     * không có gì như vậy xảy ra — không transaction ngoài, không câu đọc trần nào đứng trước khoá
     * của tôi. Ghim CỨNG `false` ở đây (không phải chỉ dựa vào mặc định của panel hôm nay) để một
     * thay đổi cấu hình panel SAU NÀY (ai đó bật `->databaseTransactions()`) không âm thầm phá vỡ
     * đúng giả định "khoá của tôi là câu đầu tiên trong MỘT transaction duy nhất" — cùng cách
     * `CreateMatter::hasDatabaseTransactions()` đã ghim.
     */
    protected ?bool $hasDatabaseTransactions = false;

    /**
     * Hai action: "Đặt lại 2FA" (M8 Task 2, R2) rồi `DeleteAction`.
     *
     * **`resetTwoFactor`** — cùng thành ngữ `unlockLogin` của `EditClientUser`: `->visible()` hỏi
     * `Gate` cho HÌNH DẠNG nút (`UserPolicy::resetTwoFactor()` — tên action khớp tên phương thức
     * policy, `HeaderActionsAreReachableTest` đòi), rồi `Gate::authorize()` hỏi LẠI bên trong
     * `action()` — một request Livewire bị chỉnh tay gọi thẳng `callMountedAction()` vẫn phải qua
     * đúng cổng đó dù nút không hiện ra. `ResetStaffTwoFactor` tự nó CŨNG chặn tự đặt lại
     * (`LogicException`) — phòng thủ hai lớp, không tin riêng lớp nào. `->color('warning')`,
     * không `danger`: đây không phải một xoá dữ liệu — nó buộc MỘT người cài lại 2FA, một thao
     * tác khôi phục, không phải phá huỷ.
     *
     * **`DeleteAction`** — chỉ nó. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction`
     * và `RestoreAction`, nhưng `UserPolicy::restore()`/`forceDelete()` luôn từ chối (cùng luật
     * `ClientPolicy`) — nên hai nút đó vẫn không hiện, giờ vì policy TỪ CHỐI thật (`false`), không
     * còn vì THIẾU phương thức tương ứng như trước Task 4. `HeaderActionsAreReachableTest` giữ
     * luật này cho mọi trang.
     *
     * `authorizationNotification()` — cùng thành ngữ `EditClient`: `UserPolicy::delete()` (R7) có
     * thể từ chối kèm một thông điệp (còn việc dở dang). Mặc định Filament ẨN HẲN nút khi không
     * có `authorizationNotification()`/`authorizationTooltip()`, nên admin sẽ không hiểu vì sao
     * nút biến mất; công tắc này giữ nút hiển thị VÀ đổi một lần bấm bị từ chối (không đua) thành
     * một thông báo nêu đúng lý do — vẫn chỉ là cổng HIỂN THỊ, `->using()` dưới đây mới là cổng
     * THẬT.
     *
     * **`->using()` (I2, fix round 1): thay `$record->delete()` mặc định bằng
     * {@see DeleteStaffMember}, chạy dưới khoá dòng VÀ `Cache::lock()`.** Trước bản sửa này,
     * Filament tự hỏi `UserPolicy::delete()` (không khoá gì) để quyết định nút có bấm được không,
     * rồi gọi `$record->delete()` NGAY SAU — hai câu lệnh RỜI. Giữa hai câu đó, một request khác
     * có thể đổi đúng thứ vừa được đọc. Xem docblock lớp của Action đó cho lý lẽ đầy đủ.
     *
     * **`->unauthorizedNotification()` (M7 Task 2, R6) — thêm liên kết tới "Bàn giao hàng loạt"
     * vào ĐÚNG thông báo mà `authorizationNotification()` của Filament tự dựng khi
     * `UserPolicy::delete()` từ chối** (tiêu đề = nguyên văn lý do của policy — KHÔNG đổi, đo bởi
     * `assertNotified(staffOffboardingMessage(...))` đã có từ M6.5). `attachBulkReassignLink()`
     * chỉ THÊM một action-button vào `$notification` nếu người này còn dẫn vụ mở VÀ actor hiện
     * tại `BulkReassign::canAccess()` được — xem docblock `BulkReassign::offboardingLinkAction()`.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTwoFactor')
                ->label(__('users.actions.reset_two_factor.label'))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('users.actions.reset_two_factor.modal_heading', ['name' => $this->getRecord()->name]))
                ->modalDescription(fn (): string => __('users.actions.reset_two_factor.modal_description', ['name' => $this->getRecord()->name]))
                ->visible(fn (): bool => Gate::allows('resetTwoFactor', $this->getRecord()))
                ->action(function (Action $action): void {
                    Gate::authorize('resetTwoFactor', $this->getRecord());

                    /** @var User $target */
                    $target = $this->getRecord();

                    $this->runAction($action, fn () => app(ResetStaffTwoFactor::class)->handle(Auth::user(), $target));

                    Notification::make()
                        ->title(__('users.actions.reset_two_factor.success', ['name' => $target->name]))
                        ->success()
                        ->send();
                }),
            /*
             * M8 Task 3 (SPEC §10.3): xoá khoá đếm đăng nhập của nhân sự này. Cùng khuôn `unlockLogin`
             * của `EditClientUser`: `visible()` hỏi Gate (một ability RIÊNG `unlockLogin` trên
             * `UserPolicy`, vì `HeaderActionsAreReachableTest` đòi tên action trùng tên một phương
             * thức policy), và `Gate::authorize()` LẶP LẠI trong `action()` vì `visible()` chỉ quyết
             * định có VẼ nút hay không — một request Livewire chỉnh tay vẫn gọi được action. Action
             * `UnlockStaffLogin` hỏi Gate lần thứ ba, bên trong.
             */
            Action::make('unlockLogin')
                ->label(__('users.actions.unlock_login.label'))
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('users.actions.unlock_login.modal_heading', ['name' => $this->getRecord()->name]))
                ->modalDescription(fn (): string => __('users.actions.unlock_login.modal_description', ['name' => $this->getRecord()->name]))
                ->visible(fn (): bool => Gate::allows('unlockLogin', $this->getRecord()))
                ->action(function (): void {
                    /** @var User $target */
                    $target = $this->getRecord();

                    Gate::authorize('unlockLogin', $target);

                    $actor = Auth::user();
                    abort_unless($actor instanceof User, 403);

                    $result = app(UnlockStaffLogin::class)->handle($target, $actor);

                    // Final review I2: "đăng nhập lại được ngay" chỉ khi không còn khoá địa chỉ
                    // nào — nhân sự có thể bị khoá chỉ vì đồng nghiệp cùng NAT văn phòng.
                    Notification::make()
                        ->title(match (true) {
                            $result->ipStillLocked => __('users.actions.unlock_login.success_ip_still_locked', [
                                'name' => $target->name,
                                'minutes' => $result->minutesRemaining,
                            ]),
                            $result->anyAddressLockedMinutes !== null => __('users.actions.unlock_login.success_other_address_locked', [
                                'name' => $target->name,
                                'minutes' => $result->anyAddressLockedMinutes,
                            ]),
                            default => __('users.actions.unlock_login.success', ['name' => $target->name]),
                        })
                        ->success()
                        ->send();
                }),
            /*
             * Làn fb, mục A5: "Khoá truy cập ngay" — tách khoá truy cập khỏi nghỉ việc
             * ({@see SuspendStaffAccess}). Cùng khuôn `resetTwoFactor`: `visible()` hỏi Gate cho hình
             * dạng nút (cộng "tài khoản còn hoạt động" — khoá rồi thì không còn gì để khoá; một
             * cuộc đua làm nút tự ẩn lúc bấm chỉ xảy ra khi người đó đã bị khoá, đúng trạng thái
             * muốn có), `Gate::authorize()` hỏi lại trong `action()`, Action hỏi lần ba bên trong.
             */
            Action::make('suspendAccess')
                ->label(__('staff_access.suspend.label'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('staff_access.suspend.modal_heading', ['name' => $this->getRecord()->name]))
                ->modalDescription(function (): string {
                    /** @var User $target */
                    $target = $this->getRecord();
                    $openWork = OpenWork::forUser($target);

                    return __('staff_access.suspend.modal_description', [
                        'name' => $target->name,
                        'matters' => $openWork->leadMatters->count(),
                        'deadlines' => $openWork->deadlines->count(),
                        'requests' => $openWork->clientRequests->count(),
                    ]);
                })
                ->visible(fn (): bool => $this->getRecord()->is_active && Gate::allows('suspendAccess', $this->getRecord()))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('staff_access.suspend.reason'))
                        ->helperText(__('staff_access.suspend.reason_help'))
                        ->required(),
                ])
                ->action(function (Action $action, array $data): void {
                    /** @var User $target */
                    $target = $this->getRecord();

                    Gate::authorize('suspendAccess', $target);

                    $this->runAction($action, fn () => app(SuspendStaffAccess::class)->handle(Auth::user(), $target, $data['reason'] ?? ''));

                    $this->getRecord()->refresh();
                    $this->refreshFormData(['is_active']);

                    Notification::make()
                        ->title(__('staff_access.suspend.success', ['name' => $target->name]))
                        ->success()
                        ->send();
                }),
            DeleteAction::make()
                ->authorizationNotification()
                ->unauthorizedNotification(fn (Notification $notification): Notification => $this->attachBulkReassignLink($notification, $this->getRecord()))
                ->using(function (DeleteAction $action): bool {
                    $this->runAction($action, fn () => app(DeleteStaffMember::class)->handle(Auth::user(), $this->getRecord()));

                    return true;
                }),
        ];
    }

    /**
     * Xem docblock {@see self::getHeaderActions()} và {@see self::handleRecordUpdate()} — dùng
     * lại ở HAI nơi (xoá và tắt `is_active`), cùng một điều kiện.
     */
    private function attachBulkReassignLink(Notification $notification, User $target): Notification
    {
        $action = BulkReassign::offboardingLinkAction($target);

        if ($action !== null) {
            $notification->actions([$action]);
        }

        return $notification;
    }

    /** Chức danh KHÔNG đứng tên phụ trách được một vụ việc — cùng tập vai `ReassignMatter` từ chối cho lead mới (I3, fix round 1). */
    private const NON_LEADING_POSITIONS = [UserPosition::Assistant, UserPosition::Accountant];

    /**
     * Ruling (fix round 3, mục 5): trong hai đích "không lãnh đạo được", CHỈ Kế toán bị chặn khi
     * còn giữ BẤT KỲ loại việc nào (kể cả mốc hạn/yêu cầu khách) — họ không xử lý được việc pháp lý
     * nào cả. Trợ lý vẫn giữ được mốc hạn/yêu cầu khách (chỉ không giữ được vai `lead`), nên chỉ bị
     * chặn khi còn dẫn vụ. Xem {@see GuardsStaffOffboarding::demotionBlockedByAnyOpenWorkReason()}
     * so với {@see GuardsStaffOffboarding::demotionBlockedByLeadMattersReason()}.
     */
    private const FULLY_BLOCKED_DEMOTION_POSITIONS = [UserPosition::Accountant];

    /**
     * R7 (M6.5 Task 4): chặn Ở ĐÂY, KHÔNG ở `UserPolicy::update()` — policy chỉ nhận `$model` HIỆN
     * TẠI, không nhận `$data` mới đang gửi lên, nên "sắp tắt `is_active`" hay "sắp đổi chức danh
     * khỏi Quản trị" chỉ đọc được ở đây, TRƯỚC KHI `parent::handleRecordUpdate()` thật sự ghi đè
     * bản ghi — cùng vị trí `EditClientUser::mutateFormDataBeforeSave()` chặn đổi `client_id`.
     *
     * **Vô hiệu hoá** (`is_active` true → false) hỏi
     * {@see GuardsStaffOffboarding::offboardingOpenWorkReason()} — TRÊN TOÀN HỆ THỐNG (`$matter =
     * null`), khác `RemoveTeamMember` (Task 3) chỉ hỏi trong một vụ việc.
     *
     * **Đổi chức danh sang một chức danh KHÔNG lãnh đạo được (Trợ lý/Kế toán) — ruling "guard
     * demotion" (fix round 1), HAI CÂU HỎI KHÁC NHAU từ ruling round 3 (mục 5).** R7 gốc chỉ liệt
     * "vô hiệu hoá và xoá", nhưng đổi chức danh sang Trợ lý/Kế toán trong khi còn giữ việc pháp lý
     * để lại đúng hệ quả mà R7 muốn chặn — vụ việc/mốc hạn/yêu cầu khách mất người xử lý hợp lệ y
     * hệt một lần vô hiệu hoá. Hai đích hỏi HAI CÂU KHÁC NHAU, không còn "cùng câu" như round 1/2:
     *  - **Trợ lý** → {@see GuardsStaffOffboarding::demotionBlockedByLeadMattersReason()} — CHỈ hỏi
     *    `leadMatters` (SPEC §7.4 không cho Trợ lý đứng tên `lead_lawyer_id`, và
     *    `ReassignMatter`/`ViewMatter::reassignCandidateOptions()` cũng từ chối họ làm lead mới —
     *    I3). Trợ lý VẪN giữ được mốc hạn/yêu cầu khách, nên còn giữ MỘT TRONG HAI loại đó không
     *    chặn được lần đổi này.
     *  - **Kế toán** → {@see GuardsStaffOffboarding::demotionBlockedByAnyOpenWorkReason()} — hỏi
     *    CẢ BA loại việc, vì Kế toán không xử lý được BẤT KỲ việc pháp lý nào (không đứng tên lead,
     *    không mở được mốc hạn hay yêu cầu khách để xử lý).
     * Đổi SANG Trưởng phòng hay Quản trị (VẪN lãnh đạo được) không hỏi câu nào ở đây — chỉ hai đích
     * cụ thể mới chặn, không phải "mọi lần đổi chức danh của người đang dẫn vụ".
     *
     * **Quản trị viên đang hoạt động cuối cùng**: cả hai đường (tắt `is_active`, đổi `position`
     * khỏi Admin) đều hỏi {@see GuardsStaffOffboarding::wouldLeaveNoActiveAdmin()} — CÙNG HÀM (một
     * định nghĩa "admin cuối cùng" duy nhất, không phải hai cách định nghĩa lệch nhau), nhưng
     * **KHÔNG dùng chung ĐƯỜNG VÀO với `UserPolicy::delete()`** (I4-adjacent, sửa lại ở đây, fix
     * round 2 — bản round 1 nói sai chỗ này): `UserPolicy::delete()` KHÔNG hỏi hàm đó (xem docblock
     * của chính nó cho lý do — tự xoá đã bị `$user->isNot($model)` chặn tuyệt đối, một admin KHÁC
     * xoá đúng admin cuối cùng là mã chết). Hàm này CHỈ được gọi ở đây (tự SỬA, khác tự XOÁ, luôn
     * được phép) và ở {@see DeleteStaffMember} (I2, fix round 1/2 — xoá qua `DeleteAction`/
     * `DeleteBulkAction`, một luật KHÁC, không phải `UserPolicy::delete()`).
     *
     * **`Cache::lock('staff-admin-headcount')` (I2, fix round 1) — cùng khoá tên
     * {@see DeleteStaffMember} giữ, đóng cuộc đua HAI HÀNG KHÁC NHAU** (hai admin cuối cùng tự hạ
     * chức danh gần như đồng thời, ở hai request khác nhau) mà một khoá dòng đơn lẻ không đóng
     * được — xem lý lẽ đầy đủ ở docblock lớp `DeleteStaffMember`.
     *
     * **`lockForUpdate()` trên chính dòng `$record` — đóng cuộc đua CÙNG MỘT HÀNG** (hai request
     * cùng sửa một nhân sự). Đọc lại `is_active`/`position` từ `$locked` (không phải `$record` do
     * Filament truyền vào, có thể cũ hơn CSDL) cho MỌI điều kiện dưới đây. Việc GHI thật vẫn qua
     * `$record` (giữ nguyên định danh đối tượng `$this->record` để phần còn lại của vòng đời
     * `save()` đọc đúng), không phải `$locked` — hai biến trỏ tới CÙNG một hàng CSDL, khoá đã giữ nó
     * ổn định tới hết transaction.
     *
     * **Đồng bộ vai trò NGAY TRONG transaction này, không còn `afterSave()` (I2.3, fix round 2).**
     * Bản round 1 gọi `User::assignRoleFromPosition()` ở `afterSave()` — một bước RIÊNG, chạy SAU
     * khi `handleRecordUpdate()` đã trả về VÀ khoá `Cache::lock('staff-admin-headcount')` đã NHẢ.
     * `wouldLeaveNoActiveAdmin()` đếm vai SPATIE (bảng `model_has_roles`), không đếm cột `position` —
     * nên hai admin cuối cùng cùng tự hạ chức danh gần như đồng thời có thể để lọt: lượt hai giành
     * lại khoá NGAY SAU khi lượt một nhả ra, nhưng TRƯỚC KHI `afterSave()` của lượt một kịp đồng bộ
     * vai — đọc thấy admin thứ nhất "vẫn còn vai Admin" (spatie chưa đổi dù `position` đã đổi), cho
     * qua NHẦM cả hai lượt, để hệ thống còn 0 admin thật. Gọi `assignRoleFromPosition()` NGAY SAU
     * `parent::handleRecordUpdate()` thành công, còn TRONG cùng transaction/khoá, đóng đúng khe hở
     * đó: lượt hai không giành được khoá cho tới khi lượt một (kể cả đồng bộ vai) đã commit xong.
     *
     * **Thu hồi kết nối AI (M11 R8, Task 6), bước cuối của CÙNG transaction.** Vô hiệu hoá, đổi vai
     * ({@see RecordStaffPermissionChange::isChange()} — cùng định nghĩa với dòng `permission_changed`)
     * và đặt mật khẩu mới gọi {@see RevokeAiConnections}; lý do do
     * {@see AiRevocationReason::forStaffUpdate()} chọn. Vô hiệu hoá và đổi vai còn hạ `ai_access` về
     * `off` (phán quyết controller: mọi lần đổi vai — một người thành kế toán thì mất `matter.view`).
     * Lần lưu sụp ở bất kỳ bước nào thì không token nào bị thu hồi, và ngược lại.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return Cache::lock('staff-admin-headcount', 10)->block(5, function () use ($record, $data): Model {
            return DB::transaction(function () use ($record, $data): Model {
                // `withTrashed()` (minor, fix round 2): không có nó, lưu form sửa của một tài
                // khoản ĐÃ xoá mềm (ví dụ một tab khác vừa xoá xong, tab này vẫn đang mở form sửa
                // cũ) 404 ngay tại đây thay vì chạy đúng luật bên dưới — SoftDeletes global scope
                // của `User::query()` mặc định loại bỏ hàng đã xoá mềm.
                $locked = User::query()->withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

                $newIsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $locked->is_active;
                $newPosition = isset($data['position']) ? UserPosition::from($data['position']) : $locked->position;

                if ($locked->is_active && ! $newIsActive) {
                    $reason = $this->offboardingOpenWorkReason($locked);

                    if ($reason !== null) {
                        // M7 Task 2, R6: ô lỗi trên form giữ NGUYÊN $reason (đo bởi
                        // `staffOffboardingMessage()` ở UserResourceTest, không đổi) — liên kết
                        // tới "Bàn giao hàng loạt" đi bằng một Notification RIÊNG (chuỗi $reason
                        // không mang được một URL bấm được), và CHỈ khi có gì để trỏ tới: người
                        // này chỉ còn giữ mốc hạn/yêu cầu khách (không còn vụ lead nào) thì màn
                        // hình đó không giúp được gì — xem docblock `BulkReassign::
                        // offboardingLinkAction()`.
                        $linkAction = BulkReassign::offboardingLinkAction($locked);

                        if ($linkAction !== null) {
                            Notification::make()
                                ->warning()
                                ->title(__('users.offboarding.bulk_reassign_notice', ['name' => $locked->name]))
                                ->actions([$linkAction])
                                ->send();
                        }

                        throw ValidationException::withMessages([$this->errorKey('is_active') => [$reason]]);
                    }
                }

                // Final review X4: rời chức danh Quản trị viên — `hasRole`, không `position`, vì chính
                // vai trò admin là thứ mở mọi vụ `restricted` (xem `Matter::scopeListableBy`).
                if ($locked->hasRole(Role::Admin->value) && $newPosition !== UserPosition::Admin) {
                    $reason = $this->demotionFromAdminBlockedByRestrictedReason($locked);

                    if ($reason !== null) {
                        throw ValidationException::withMessages([$this->errorKey('position') => [$reason]]);
                    }
                }

                if ($newPosition !== $locked->position && in_array($newPosition, self::NON_LEADING_POSITIONS, true)) {
                    // Ruling (fix round 3, mục 5): Kế toán hỏi CẢ BA loại việc (không xử lý được
                    // BẤT KỲ việc pháp lý nào); Trợ lý chỉ hỏi `leadMatters` (vẫn giữ được mốc
                    // hạn/yêu cầu khách) — xem docblock lớp cho lý lẽ đầy đủ.
                    $reason = in_array($newPosition, self::FULLY_BLOCKED_DEMOTION_POSITIONS, true)
                        ? $this->demotionBlockedByAnyOpenWorkReason($locked)
                        : $this->demotionBlockedByLeadMattersReason($locked);

                    if ($reason !== null) {
                        throw ValidationException::withMessages([$this->errorKey('position') => [$reason]]);
                    }
                }

                $remainsActiveAdmin = $newIsActive && $newPosition === UserPosition::Admin;

                if ($this->wouldLeaveNoActiveAdmin($locked, $remainsActiveAdmin)) {
                    $field = $newIsActive ? 'position' : 'is_active';

                    throw ValidationException::withMessages([$this->errorKey($field) => [$this->lastActiveAdminReason()]]);
                }

                // M8 Task 3 (§10.6, `permission_changed`): chụp chức danh + vai trò TRƯỚC khi ghi,
                // đọc từ `$locked` (dưới khoá) chứ không từ `$record`. Vai trò hỏi thẳng bảng
                // (`roles()->pluck()`), không qua quan hệ đã nạp — `syncRoles()` không chắc làm
                // mới quan hệ được cache.
                $positionBefore = $locked->position;
                $rolesBefore = $locked->roles()->pluck('name')->all();
                $wasActive = (bool) $locked->is_active;

                $updated = parent::handleRecordUpdate($record, $data);

                $updated->assignRoleFromPosition();

                $rolesAfter = $updated->roles()->pluck('name')->all();
                $actor = Auth::user() instanceof User ? Auth::user() : null;

                app(RecordStaffPermissionChange::class)->handle(
                    $updated,
                    $positionBefore,
                    $updated->position,
                    $rolesBefore,
                    $rolesAfter,
                    $actor,
                );

                // Task 20 (phát hiện "admin đặt được mật khẩu 1 cho luật sư mà không có nhật ký
                // nào"): CÙNG điều kiện dehydrate của ô mật khẩu ở UserForm (`filled($state)`) —
                // "không gõ gì vào ô mật khẩu" không bao giờ sinh dòng này, chỉ một lần THẬT SỰ
                // đặt lại mới sinh. Không ghi mật khẩu (thô hay đã băm) vào properties, chỉ ghi
                // SỰ KIỆN đã xảy ra — cùng nguyên tắc R14 (không ghi định danh/bí mật thô vào
                // nhật ký).
                $passwordReset = array_key_exists('password', $data) && filled($data['password']);

                if ($passwordReset) {
                    Audit::record('user_password_reset', $updated, [], Auth::user());
                }

                // M11 R8 (Task 6): vô hiệu hoá, đổi vai (phán quyết controller: mọi lần đổi vai) và
                // đặt mật khẩu mới thu hồi mọi kết nối AI của người này, CÙNG transaction với chính
                // lần lưu; hai lý do đầu còn hạ `ai_access` về `off` — quản trị phải bật lại.
                $revocation = AiRevocationReason::forStaffUpdate(
                    deactivated: $wasActive && ! $newIsActive,
                    roleChanged: RecordStaffPermissionChange::isChange($positionBefore, $updated->position, $rolesBefore, $rolesAfter),
                    passwordChanged: $passwordReset,
                );

                if ($revocation !== null) {
                    app(RevokeAiConnections::class)->handle($updated, $revocation, $actor);
                }

                return $updated;
            });
        });
    }

    /**
     * `{schema}.{field}` — cùng công thức `CreateMatter::errorKey()`/`PartiesRelationManager::
     * errorKey()`: một khoá TRẦN không khớp state path THẬT của form (`data.is_active`, không phải
     * `is_active`) thì Filament coi nó không thuộc field nào và không hiện lỗi ở đâu cả.
     */
    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }
}
