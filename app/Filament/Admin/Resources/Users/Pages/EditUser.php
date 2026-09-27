<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Actions\User\DeleteStaffMember;
use App\Enums\UserPosition;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng `UserPolicy::restore()`/`forceDelete()` luôn từ chối (cùng luật
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
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorizationNotification()
                ->using(function (DeleteAction $action): bool {
                    $this->runAction($action, fn () => app(DeleteStaffMember::class)->handle(Auth::user(), $this->getRecord()));

                    return true;
                }),
        ];
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
                        throw ValidationException::withMessages([$this->errorKey('is_active') => [$reason]]);
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

                $updated = parent::handleRecordUpdate($record, $data);

                $updated->assignRoleFromPosition();

                // Task 20 (phát hiện "admin đặt được mật khẩu 1 cho luật sư mà không có nhật ký
                // nào"): CÙNG điều kiện dehydrate của ô mật khẩu ở UserForm (`filled($state)`) —
                // "không gõ gì vào ô mật khẩu" không bao giờ sinh dòng này, chỉ một lần THẬT SỰ
                // đặt lại mới sinh. Không ghi mật khẩu (thô hay đã băm) vào properties, chỉ ghi
                // SỰ KIỆN đã xảy ra — cùng nguyên tắc R14 (không ghi định danh/bí mật thô vào
                // nhật ký).
                if (array_key_exists('password', $data) && filled($data['password'])) {
                    Audit::record('user_password_reset', $updated, [], Auth::user());
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
