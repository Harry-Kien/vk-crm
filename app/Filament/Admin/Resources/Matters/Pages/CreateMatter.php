<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Trang "Mở vụ việc mới" (SPEC §13 tiêu chí M3 "tạo được vụ việc end-to-end", §6.10 "kết quả hiện
 * ngay trong form tạo vụ việc"). Trang này KHÔNG có nghiệp vụ nào của riêng nó: nó thu dữ liệu
 * form, gọi `App\Actions\OpenMatter`, và dịch hai ngoại lệ nghiệp vụ của Action thành thứ người
 * dùng đọc được. Mọi quyết định — ai được tạo, mức nào chặn, ai được ghi đè, xác nhận nào hợp lệ —
 * nằm trong Action, đúng CLAUDE.md.
 *
 * **Luồng hai lượt, sao lại đúng hình dạng của `PartiesRelationManager`** (thời điểm bắt buộc còn
 * lại của SPEC §6.10):
 *
 *  1. Lượt 1 — người dùng bấm lưu. `OpenMatter` chạy kiểm tra xung đột (và ghi dòng
 *     `conflict_check_run` — bằng chứng đã kiểm tra tồn tại kể cả khi lượt này bị chặn) rồi ném
 *     `ConflictBlocked` (đỏ) hoặc `ConflictAcknowledgementRequired` (vàng, hoặc xanh có bên thiếu
 *     định danh). Trang bắt hai ngoại lệ đó, lưu `$result->toArray()` vào `$conflictResult`, và ném
 *     `ValidationException` gắn đúng ô để form GIỮ NGUYÊN dữ liệu đã nhập.
 *  2. Lượt 2 — người dùng đọc bảng kết quả (đã hiện trong form, ngay trên hai ô kia), rồi tích "đã
 *     xem xét" hoặc điền lý do ghi đè và bấm lưu lại. `$pendingConflictLevel` nhớ mức của lượt 1 để
 *     `acknowledged` khớp CHÍNH XÁC mức mà lần kiểm tra trả về — hợp đồng của `OpenMatter`.
 *
 * **Khác `PartiesRelationManager` ở ba điểm, đều có lý do:**
 *  - Kết quả hiện bằng một BẢNG trong chính form (`filament.conflict-check-result`), không phải một
 *    Notification: SPEC §6.10 nói rõ "hiện ngay trong form tạo vụ việc, dạng bảng liệt kê vụ việc
 *    liên quan kèm mã hồ sơ và vai của bên đó". Ở tab "Các bên" thì modal đóng lại sau khi lưu nên
 *    Notification là chỗ duy nhất còn lại; ở đây form vẫn mở nên bảng ở đúng chỗ SPEC yêu cầu.
 *  - Ô "Lý do ghi đè" bị `disabled()` với ai không phải `manager`/`admin`, thay vì hiện ra cho mọi
 *    người như ở tab "Các bên". Một ô mở cho luật sư gõ vào rồi vẫn bị từ chối là một lời hứa sai.
 *  - Trang không hiện gì thêm ở nhánh thành công XANH SẠCH ngoài một Notification tóm tắt (xem
 *    `notifySaved()`), vì sau khi lưu trang chuyển sang hồ sơ vừa mở, form không còn tồn tại.
 *
 * **Không bọc lời gọi trong `DB::transaction()`** — `OpenMatter` cấm điều đó (xem cảnh báo cho
 * caller ở docblock của Action: một transaction ngoài biến giai đoạn kiểm tra thành savepoint và
 * cuốn theo dòng `conflict_check_run` khi bị chặn). Panel admin không bật
 * `databaseTransactions()`, nhưng `hasDatabaseTransactions()` dưới đây khoá cứng `false` để một
 * thay đổi cấu hình panel sau này không âm thầm phá vỡ ràng buộc đó.
 */
class CreateMatter extends CreateRecord
{
    protected static string $resource = MatterResource::class;

    /**
     * Kết quả lần kiểm tra xung đột GẦN NHẤT của form đang mở, dạng `ConflictCheckResult::toArray()`
     * — mảng thuần để Livewire tuần tự hoá được. `null` khi chưa có lần kiểm tra nào (lần đầu mở
     * trang) hoặc sau khi vụ việc đã lưu.
     *
     * `#[Locked]` vì mảng này là thứ DUY NHẤT người dùng nhìn thấy để quyết định có xác nhận hay
     * không: nếu client sửa được nó, một payload dàn dựng có thể hiện một bảng "xanh sạch" trong
     * khi kết quả thật là đỏ. Nó không tự gác cổng lưu (cổng là `OpenMatter`), nhưng nó là bằng
     * chứng hiển thị, và một bằng chứng sửa được từ phía client thì vô giá trị.
     */
    #[Locked]
    public ?array $conflictResult = null;

    /**
     * Mức của lần kiểm tra TRƯỚC, chờ người dùng tích "đã xem xét" ở lượt gửi kế tiếp. Cùng lý do
     * `#[Locked]` như `PartiesRelationManager::$pendingConflictLevel`: không có nó, một payload bị
     * sửa tay có thể tự đặt sẵn giá trị rồi tích luôn ô xác nhận ngay LƯỢT ĐẦU, thoả điều kiện xác
     * nhận mà không ai từng thấy kết quả kiểm tra thật.
     */
    #[Locked]
    public ?string $pendingConflictLevel = null;

    public function getTitle(): string
    {
        return __('matters.create_form.create_heading');
    }

    /** Xem docblock lớp: `OpenMatter` không được gọi từ trong một transaction đang mở. */
    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    /**
     * Nhân sự được chọn làm luật sư phụ trách: còn hoạt động VÀ thật sự chạy được vụ việc
     * (`matter.transitionStage` — SPEC §5 cho admin/manager/lawyer, không cho trợ lý và kế toán).
     * Gán một người không có quyền đó làm lead lawyer tạo ra một vụ việc không ai chuyển giai đoạn
     * được, và `Matter::created` còn tự thêm người đó vào đội ngũ với vai `lead`.
     *
     * Tách static để test được trực tiếp, cùng lý do như `MattersTable::lastClientUpdateColor()`.
     *
     * @return array<int, string>
     */
    public static function leadLawyerOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->permission(Permission::MatterTransitionStage->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** SPEC §6.10 bước 3: chỉ `manager`/`admin` ghi đè được mức đỏ. Chỉ để HIỂN THỊ — cổng thật ở `OpenMatter`. */
    public function canOverrideRedConflict(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User
            && ($actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value));
    }

    /**
     * Dữ liệu cho `resources/views/filament/conflict-check-result.blade.php`. Nhãn tiếng Việt được
     * dịch ở đây (không phải trong blade) để view không phải biết tới enum nào — và để ranh giới
     * lộ thông tin nằm gọn trong một hàm đọc được: mảng trả về chỉ có đúng sáu khoá của
     * `ConflictMatch::toArray()`, không có `matter_id`, không có tiêu đề.
     *
     * @return array<string, mixed>
     */
    public function conflictResultViewData(): array
    {
        $result = $this->conflictResult ?? [];
        $matches = $result['matches'] ?? [];
        $incompleteParties = $result['incomplete_parties'] ?? [];
        $level = $result['level'] ?? ConflictLevel::Green->value;

        return [
            'level' => $level,
            // Cùng luật với PartiesRelationManager::notifyConflictCheckResult(): một mức xanh có
            // bên thiếu định danh KHÔNG được mang màu "sạch".
            'requiresAttention' => $level !== ConflictLevel::Green->value || $incompleteParties !== [],
            'matches' => array_map(fn (array $match): array => [
                'matter_code' => $match['matter_code'],
                'matter_type_name' => $match['matter_type_name'],
                'party_role' => PartyRole::from($match['party_role'])->label(),
                'party_name' => $match['party_name'],
                'tier' => ConflictMatchTier::from($match['tier'])->label(),
                'level' => ConflictLevel::from($match['level'])->label(),
            ], $matches),
            'incompleteParties' => $incompleteParties,
        ];
    }

    /**
     * `VisibleClientOptions`/`leadLawyerOptions()` chỉ hạn chế những gì ô chọn HIỂN THỊ — một
     * request bị chỉnh sửa vẫn gửi thẳng được một id ngoài tầm nhìn. Chặn thật ở đây, đúng lúc hai
     * id đã biết, giống hệt `CreateClientUser::mutateFormDataBeforeCreate()`.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(array_key_exists((int) ($data['client_id'] ?? 0), VisibleClientOptions::forCurrentUser()), 403);
        abort_unless(array_key_exists((int) ($data['lead_lawyer_id'] ?? 0), static::leadLawyerOptions()), 403);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();

        // tryFrom(), không from(): dù đã #[Locked], vẫn phòng thủ ở điểm dùng. Một giá trị không
        // hợp lệ đơn giản là không khớp mức thật của lần kiểm tra NÀY, nên `OpenMatter` vẫn từ chối
        // đúng cách thay vì ném lỗi 500.
        $acknowledged = ((bool) ($data['acknowledge_conflict'] ?? false) && $this->pendingConflictLevel !== null)
            ? ConflictLevel::tryFrom($this->pendingConflictLevel)
            : null;

        try {
            // `handle()` trả `OpenMatterResult` (vụ việc + kết quả kiểm tra + có ghi đè hay không
            // + lý do). Ở đây mới chỉ lấy ra vụ việc để trang chạy như cũ; phần dùng `result`/
            // `overridden` để `notifySaved()` nói đúng nhánh thành công nào là việc của bản sửa
            // giao diện tiếp theo — xem docblock `App\Support\OpenMatterResult`.
            $matter = app(OpenMatter::class)->handle(
                actor: $actor,
                attributes: static::matterAttributes($data),
                parties: static::partiesPayload($data),
                overrideReason: $data['override_reason'] ?? null,
                acknowledged: $acknowledged,
            )->matter;
        } catch (ConflictBlocked $exception) {
            // Mức đỏ không phải thứ "thử lại là qua": xoá mức đang chờ để một ô xác nhận còn tích
            // sót từ lượt trước không mang nghĩa gì ở lượt sau.
            $this->pendingConflictLevel = null;
            $this->conflictResult = $exception->result->toArray();

            throw ValidationException::withMessages([
                $this->errorKey('override_reason') => [$this->canOverrideRedConflict()
                    ? __('matters.conflict.blocked_retry')
                    : __('matters.conflict.blocked_retry_denied')],
            ]);
        } catch (ConflictAcknowledgementRequired $exception) {
            $this->pendingConflictLevel = $exception->result->level->value;
            $this->conflictResult = $exception->result->toArray();

            throw ValidationException::withMessages([
                $this->errorKey('acknowledge_conflict') => [__('matters.conflict.ack_retry')],
            ]);
        }

        $this->notifySaved();

        // Dọn sạch trạng thái của lần mở vụ việc vừa xong. Bắt buộc, không chỉ gọn gàng: nút
        // "Tạo & tạo thêm" giữ nguyên component và dựng lại form trống — nếu `conflictResult` còn
        // lại, form MỚI sẽ mở ra với bảng kết quả của vụ việc TRƯỚC, nói về những bên chưa ai nhập.
        // Đọc theo thứ tự: notifySaved() ở trên còn cần `conflictResult` để chọn đúng câu.
        $this->pendingConflictLevel = null;
        $this->conflictResult = null;

        return $matter;
    }

    /**
     * SPEC §6.10 bước 4 ("phải chứng minh được là đã kiểm tra") áp cả cho đường thành công: người
     * vừa mở vụ việc phải thấy là đã có một lần kiểm tra chạy, kể cả khi kết quả xanh.
     *
     * `OpenMatter::handle()` trả về `Matter`, không trả `ConflictCheckResult`, nên ở nhánh thành
     * công trang không cầm kết quả trên tay. KHÔNG chạy `RunConflictCheck` thêm một lượt chỉ để
     * lấy kết quả hiển thị: mỗi lần chạy là một dòng activity log thật (SPEC §6.10 bước 4), và hai
     * dòng cho một lần mở vụ việc là nhật ký sai sự thật. Thay vào đó suy ra từ hợp đồng của
     * Action, không đoán:
     *  - `$conflictResult !== null` ⟹ đã có một lượt bị chặn/đòi xác nhận trước đó, bảng kết quả
     *    thật đã hiển thị cho người dùng đọc → nói đúng như vậy.
     *  - `$conflictResult === null` ⟹ `handle()` không ném gì ở lượt đầu ⟹ theo bước 4 của Action,
     *    `! isBlocking()` VÀ `! requiresAcknowledgement()` ⟹ mức XANH, không có bản ghi trùng
     *    (`ConflictLevel::Green` không bao giờ gắn vào một `ConflictMatch`) và không có bên thiếu
     *    định danh. Đây là suy luận từ mã nguồn của `OpenMatter`, không phải phỏng đoán — nếu bước
     *    4 của Action đổi, câu thông báo này phải đổi theo.
     */
    private function notifySaved(): void
    {
        Notification::make()
            ->title($this->conflictResult === null
                ? __('matters.conflict.saved_clear')
                : __('matters.conflict.saved_after_review'))
            ->color($this->conflictResult === null ? 'success' : 'warning')
            ->persistent()
            ->send();
    }

    /**
     * Cùng công thức `errorKey()` của `PartiesRelationManager`: Filament chỉ hiện lỗi ở đúng ô khi
     * khoá lỗi khớp CHÍNH XÁC state path của schema (`data.override_reason` cho một trang
     * CreateRecord); một khoá trần bị coi là không thuộc form nào và không hiện ở đâu cả.
     */
    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }

    /**
     * Thuộc tính của `matters` (SPEC §4.5) cộng `client_role` mà `OpenMatter` đòi. Dựng TƯỜNG MINH
     * từ đúng những khoá cần, thay vì đẩy cả `$data`: ba khoá của form không phải cột của bảng
     * (`other_parties`, `acknowledge_conflict`, `override_reason`) sẽ làm `Matter::create()` nổ,
     * và một danh sách tường minh còn là nơi người đọc sau này thấy ngay form gửi gì cho Action.
     * `code`/`stage`/`stage_entered_at` cố ý vắng mặt — `Matter::creating()` tự lo (SPEC §6.1).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function matterAttributes(array $data): array
    {
        return [
            'client_id' => $data['client_id'],
            'client_role' => $data['client_role'] ?? null,
            'matter_type_id' => $data['matter_type_id'],
            'title' => $data['title'],
            'description_internal' => $data['description_internal'] ?? null,
            'summary_for_client' => $data['summary_for_client'] ?? null,
            'lead_lawyer_id' => $data['lead_lawyer_id'],
            'opened_at' => $data['opened_at'] ?? null,
            'confidentiality' => $data['confidentiality'] ?? null,
            'court_name' => $data['court_name'] ?? null,
            'case_number' => $data['case_number'] ?? null,
            'is_published_to_portal' => (bool) ($data['is_published_to_portal'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private static function partiesPayload(array $data): array
    {
        return collect($data['other_parties'] ?? [])
            ->map(function (array $party): array {
                $isOurClient = (bool) ($party['is_our_client'] ?? false);

                return [
                    'role' => $party['role'],
                    'name' => $party['name'],
                    'is_our_client' => $isOurClient,
                    // Ô "Khách hàng" chỉ hiện khi bật công tắc, nên khi tắt lại nó có thể còn giữ
                    // giá trị cũ trong state — không để một client_id mồ côi lọt xuống Action.
                    'client_id' => $isOurClient ? ($party['client_id'] ?? null) : null,
                    'id_number' => $party['id_number'] ?? null,
                    'phone' => $party['phone'] ?? null,
                    'address' => $party['address'] ?? null,
                    'note' => $party['note'] ?? null,
                ];
            })
            ->values()
            ->all();
    }
}
