<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\User;
use App\Support\ConflictCheckResult;
use App\Support\ConflictMatch;
use App\Support\ConflictOverride;
use App\Support\OpenMatterResult;
use DomainException;
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
 * **Hai ô quyết định chỉ TỒN TẠI ở lượt 2 (review fix round 3, Critical C-1).** Cả "đã xem xét"
 * lẫn "lý do ghi đè" đều `visible()` theo `$conflictResult !== null`, tức chỉ hiện sau khi một kết
 * quả kiểm tra thật đã được dựng ra trước mắt người dùng. Trước bản sửa này ô lý do hiện vô điều
 * kiện, nên một manager điền nó TRƯỚC lượt gửi đầu tiên đi thẳng vào nhánh ghi đè của `OpenMatter`
 * — ghi đè một mức đỏ chưa ai từng thấy. Một lý do viết cho một xung đột chưa hiện ra không phải
 * một quyết định, nó chỉ là một ô trống đã được điền sẵn. Một trường `hidden` KHÔNG được Filament
 * dehydrate (`isHiddenAndNotDehydratedWhenHidden()`), nên đây là một cổng phía MÁY CHỦ, không phải
 * trang trí — và `handleRecordCreation()` vẫn kiểm tra lại `$conflictResult !== null` một lần nữa
 * để cổng không phụ thuộc vào một chi tiết nội bộ của framework.
 *
 * **Khác `PartiesRelationManager` ở ba điểm, đều có lý do:**
 *  - Kết quả hiện bằng một BẢNG trong chính form (`filament.conflict-check-result`), không phải một
 *    Notification: SPEC §6.10 nói rõ "hiện ngay trong form tạo vụ việc, dạng bảng liệt kê vụ việc
 *    liên quan kèm mã hồ sơ và vai của bên đó". Ở tab "Các bên" thì modal đóng lại sau khi lưu nên
 *    Notification là chỗ duy nhất còn lại; ở đây form vẫn mở nên bảng ở đúng chỗ SPEC yêu cầu.
 *  - Ô "Lý do ghi đè" bị `disabled()` với ai không phải `manager`/`admin`, thay vì hiện ra cho mọi
 *    người như ở tab "Các bên". Một ô mở cho luật sư gõ vào rồi vẫn bị từ chối là một lời hứa sai.
 *  - Nhánh thành công vẫn hiện một Notification tóm tắt kết quả kiểm tra (xem `notifySaved()`), vì
 *    sau khi lưu trang chuyển sang hồ sơ vừa mở và form — cùng bảng kết quả trong nó — không còn
 *    tồn tại. Thông báo mặc định "Đã tạo" của Filament bị tắt để không chồng lên nó (Minor 6).
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

    /**
     * SPEC §6.10 bước 3: chỉ `manager`/`admin` ghi đè được mức đỏ. Chỉ để HIỂN THỊ — cổng thật ở
     * `OpenMatter`. Luật ở `ConflictOverride` để tab "Các bên" hỏi CÙNG một câu và nhận CÙNG một
     * câu trả lời; xem docblock lớp đó cho lý do đầy đủ.
     */
    public function canOverrideRedConflict(): bool
    {
        return ConflictOverride::allowedForCurrentUser();
    }

    /**
     * Một kết quả kiểm tra mức ĐỎ đã thật sự được dựng ra trước mắt người dùng — điều kiện DUY
     * NHẤT làm một lý do ghi đè có nghĩa (I-A, review gộp nhánh M3). Cùng hàm, cùng lý do, cùng
     * tên với `PartiesRelationManager::redResultShown()`; đọc docblock ở đó cho đường leo mức mà
     * điều kiện cũ (`conflictResult !== null`) để lọt, và vì sao ô "đã xem xét" KHÔNG hẹp lại theo
     * mức.
     */
    public function redResultShown(): bool
    {
        return ($this->conflictResult['level'] ?? null) === ConflictLevel::Red->value;
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
     * request bị chỉnh sửa vẫn gửi thẳng được một id ngoài tầm nhìn. Chặn thật ở đây, đúng lúc mọi
     * id đã biết, giống hệt `CreateClientUser::mutateFormDataBeforeCreate()`.
     *
     * **Cả `client_id` của TỪNG BÊN trong repeater, không chỉ của vụ việc (review fix round 3,
     * finding I-5).** Bên nào bật "là khách hàng của văn phòng" cũng mang một `client_id` xuống
     * `OpenMatter`, và `BuildsMatterParties` lấy TÊN + định danh của bên đó thẳng từ hồ sơ `Client`
     * đã khoá — nên một id giả mạo ghi TÊN THẬT của một khách hàng ngoài tầm nhìn lên một dòng bên
     * mà người gửi đọc lại được ngay sau khi lưu.
     *
     * **Vì sao kiểm tra ở MÀN HÌNH chứ không trong Action.** Luật đang áp là "panel user này được
     * tham chiếu tới những hồ sơ khách hàng nào", tức `VisibleClientOptions` — một ranh giới HIỂN
     * THỊ của panel, suy ra từ `Matter::scopeListableBy`. Hai id còn lại mà form gửi lên
     * (`client_id` của vụ việc, `lead_lawyer_id`) đã được chặn ở đúng tầng này; đẩy riêng một
     * trong ba xuống Action sẽ xé một luật ra làm hai tầng. Ranh giới của Action là một luật khác
     * và hẹp hơn — "actor này có được mở vụ việc / thêm bên hay không" (`MatterPolicy::create` /
     * `update`) — và cả hai Action phải gọi được từ console, job, import hay seeder, nơi không tồn
     * tại "tầm nhìn panel" nào để đối chiếu. Đổi lại, cả hai màn hình gọi CÙNG một hàm
     * (`VisibleClientOptions::assertVisibleToCurrentUser()`), nên chúng không thể lệch nhau theo
     * cách hai Action đã từng lệch ba lần.
     *
     * Ghi nhận trung thực: `Select::options()` của Filament đã tự cài sẵn một luật `in:` phía máy
     * chủ dựng từ chính danh sách đó, nên một id giả mạo thực tế bị chặn ngay ở bước xác thực —
     * kiểm tra dưới đây là lớp thứ hai, cố ý. Lớp thứ hai cần thiết vì lớp thứ nhất là một hành vi
     * NGẦM: nó biến mất lặng lẽ nếu ô chọn sau này đổi sang `getSearchResultsUsing()` hay bất kỳ
     * nguồn tuỳ chọn động nào, và không ai đọc diff đó sẽ nhận ra mình vừa gỡ một hàng rào.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        VisibleClientOptions::assertVisibleToCurrentUser($data['client_id'] ?? null);

        foreach ($data['other_parties'] ?? [] as $party) {
            // Cùng điều kiện với partiesPayload(): một client_id mồ côi (công tắc đã tắt lại) bị
            // bỏ trước khi xuống Action, nên không có gì để cho phép hay từ chối.
            if ((bool) ($party['is_our_client'] ?? false) && filled($party['client_id'] ?? null)) {
                VisibleClientOptions::assertVisibleToCurrentUser($party['client_id']);
            }
        }

        // SPEC §10.10: 404 cho cả "không có quyền" lẫn "không tồn tại" — 403 ở đây tự nó xác nhận
        // rằng nhân sự mang id vừa gửi là có thật.
        abort_unless(array_key_exists((int) ($data['lead_lawyer_id'] ?? 0), static::leadLawyerOptions()), 404);

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

        // Lý do ghi đè chỉ có nghĩa khi một bảng kết quả mức ĐỎ đã hiện ra cho người dùng đọc —
        // không phải "một bảng bất kỳ" (I-A, xem `redResultShown()`). Ô này đã `visible()` theo
        // cùng hàm đó nên ngoài vòng đỏ Filament còn không dehydrate nó; kiểm tra lại ở đây để cổng
        // không phụ thuộc vào một chi tiết dehydrate của framework.
        $overrideReason = $this->redResultShown() ? ($data['override_reason'] ?? null) : null;

        try {
            $opening = app(OpenMatter::class)->handle(
                actor: $actor,
                attributes: static::matterAttributes($data),
                parties: static::partiesPayload($data),
                overrideReason: $overrideReason,
                acknowledged: $acknowledged,
            );
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
        } catch (DomainException $exception) {
            // Lưới an toàn cho mọi luật nghiệp vụ còn lại của tầng Action — hôm nay chỉ có
            // `OurClientPartyNeedsClient`. (`client_role` thiếu thì `OpenMatter` ném
            // `ValidationException`, vốn đã là lỗi form; `ConflictBlocked`/
            // `ConflictAcknowledgementRequired` cũng là `DomainException` nên hai `catch` riêng
            // của chúng phải đứng TRƯỚC `catch` này.) `bootstrap/app.php` không đăng ký `render()`
            // nào cho `DomainException`, nên không có lưới này thì một luật như vậy thành trang
            // lỗi 500 — cùng hình dạng với `BuildsStageUpdateSchema`. Đường chính đã bị
            // `required()` chặn; đây là cho những đường vào chưa lường trước.
            //
            // Lỗi gắn vào chính repeater `other_parties` (là một `Field`, nên Filament hiện được
            // lỗi ở đó) chứ không vào một dòng cụ thể: chỉ số dòng gây lỗi không đi cùng exception,
            // và đoán sai chỉ số thì lỗi rơi vào một ô không liên quan. Thông điệp lấy nguyên từ
            // exception — nó đã gọi tên bên vi phạm, qua `lang/vi/exceptions.php`.
            throw ValidationException::withMessages([
                $this->errorKey('other_parties') => [$exception->getMessage()],
            ]);
        }

        $this->notifySaved($opening);

        // Dọn sạch trạng thái của lần mở vụ việc vừa xong. Bắt buộc, không chỉ gọn gàng: nút
        // "Tạo & tạo thêm" giữ nguyên component và dựng lại form trống — nếu `conflictResult` còn
        // lại, form MỚI sẽ mở ra với bảng kết quả của vụ việc TRƯỚC, nói về những bên chưa ai nhập.
        $this->forgetConflictResult();

        return $opening->matter;
    }

    /**
     * Quên kết quả kiểm tra đang hiển thị và mức đang chờ xác nhận.
     *
     * **Minor 3/4 của bản xem xét:** bảng kết quả là một ẢNH CHỤP của những bên đã có lúc bấm lưu,
     * nhưng nó nằm im trong khi người dùng sửa tiếp form — nên nó có thể đang mô tả những bên
     * không còn ở đó nữa. Tệ hơn, `$pendingConflictLevel` chỉ nhớ MỨC: đổi một bên giữa hai lượt
     * gửi mà mức vẫn vàng thì dấu tích "đã xem xét" của bảng CŨ được nhận cho bảng MỚI. Vì vậy mọi
     * thay đổi có thể làm đổi kết quả kiểm tra (khách hàng, vai của khách hàng, bất cứ thứ gì
     * trong danh sách bên) đều gọi hàm này — xem `MatterForm::forgetConflictResultOnChange()`.
     *
     * Dấu tích cũng bị gỡ, không chỉ mức: để nguyên một ô đã tích trong khi lời từ chối bảo người
     * dùng "hãy tích ô này" là một màn hình tự mâu thuẫn. Và LÝ DO GHI ĐÈ cũng vậy (Minor, fix
     * round 4): bản trước để nguyên `data['override_reason']`, nên một lý do viết cho bảng đỏ NÀY
     * sống sót sang bảng đỏ KẾ TIẾP và lượt gửi sau đó ghi đè bằng một câu chưa ai viết cho xung
     * đột đó — cùng hạng lỗi với C-1, chỉ nhỏ hơn.
     *
     * Hàm này KHÔNG được gọi ở lượt render đang hiện bảng ra: `afterStateUpdated` chỉ chạy khi có
     * một thay đổi state thật từ người dùng, không chạy khi Livewire dựng lại giao diện sau khi
     * `handleRecordCreation()` ném `ValidationException` — nên bảng vừa đặt vào vẫn còn nguyên khi
     * người dùng nhìn thấy nó.
     */
    public function forgetConflictResult(): void
    {
        $this->conflictResult = null;
        $this->pendingConflictLevel = null;
        $this->data['acknowledge_conflict'] = false;
        $this->data['override_reason'] = null;
    }

    /**
     * SPEC §6.10 bước 4 ("phải chứng minh được là đã kiểm tra") áp cả cho đường thành công: người
     * vừa mở vụ việc phải thấy là đã có một lần kiểm tra chạy, và thấy nó nói GÌ — kể cả khi kết
     * quả xanh, và nhất là khi không xanh. Sau khi lưu, trang chuyển sang hồ sơ vừa mở nên bảng
     * trong form không còn tồn tại: thông báo này là thứ duy nhất còn lại.
     *
     * **Nói theo KẾT QUẢ THẬT, không suy luận (review fix round 3, Critical C-1).** Bản trước đọc
     * `$conflictResult === null` rồi suy ra "Action không ném gì ⟹ xanh sạch". Chuỗi suy luận đó
     * BỎ SÓT nhánh ghi đè: `OpenMatter` bước 4 cũng trả về BÌNH THƯỜNG khi một manager ghi đè mức
     * ĐỎ, nên một manager ghi đè ngay lượt gửi đầu tiên được báo "không tìm thấy bản ghi trùng
     * nào" MÀU XANH — về một xung đột chưa từng hiện ra cho họ xem. Nhật ký thì đúng, chỉ có màn
     * hình nói ngược lại, và đó là thứ người dùng thật sự đọc.
     *
     * `OpenMatterResult` mang đủ ba thứ cần để nói thật, nên ở đây không còn suy luận nào:
     *  - `$opening->overridden` — lần lưu này có đi qua cổng ghi đè mức đỏ hay không. KHÔNG suy ra
     *    được từ `level`: đỏ xuất hiện ở CẢ nhánh bị chặn (ném ngoại lệ) lẫn nhánh được ghi đè.
     *  - `$opening->result` — mức và danh sách bản ghi trùng, để câu thông báo kể ra ĐÃ ghi đè
     *    xung đột với hồ sơ nào, không chỉ rằng có ghi đè.
     *  - `$opening->overrideReason` — lý do đã ghi vĩnh viễn vào nhật ký, hiện lại nguyên văn để
     *    người vừa gõ nó nhìn thấy mình vừa ký vào cái gì.
     *
     * Ba mức hiển thị, không hai: ĐỎ ĐÃ GHI ĐÈ (`danger`), CẦN XEM XÉT (`warning`, gồm cả mức xanh
     * có bên thiếu định danh — cùng luật `requiresAcknowledgement()` mà `PartiesRelationManager`
     * dùng), và XANH SẠCH (`success`).
     */
    private function notifySaved(OpenMatterResult $opening): void
    {
        $result = $opening->result;
        $needsAttention = $result->requiresAcknowledgement();

        [$title, $color] = match (true) {
            $opening->overridden => [__('matters.conflict.saved_overridden'), 'danger'],
            $needsAttention => [__('matters.conflict.saved_after_review'), 'warning'],
            default => [__('matters.conflict.saved_clear'), 'success'],
        };

        Notification::make()
            ->title($title)
            ->body(static::conflictSummary($result, $opening->overrideReason))
            ->color($color)
            ->persistent()
            ->send();
    }

    /**
     * Phần thân thông báo: danh sách hồ sơ trùng, cảnh báo bên thiếu định danh, và lý do ghi đè
     * nếu có.
     *
     * **Mỗi dòng mang ĐÚNG sáu trường của `ConflictMatch`, theo đúng thứ tự các cột của bảng trong
     * form** (`conflictResultViewData()` và `filament.conflict-check-result`): mã hồ sơ, loại vụ
     * việc, vai của bên trùng, TÊN của bên trùng, tầng khớp, mức. Không tiêu đề, không tóm tắt,
     * không id — ranh giới lộ thông tin của SPEC §6.10 đoạn cuối.
     *
     * **`partyName` từng thiếu ở ĐÂY dù đã có trên bảng ngay trên nó (I-B, review gộp nhánh M3).**
     * Sau khi lưu, trang chuyển sang hồ sơ vừa mở và bảng biến mất: thông báo này là bản ghi cuối
     * cùng người dùng còn đọc được, nên nó không được nghèo hơn bảng. Đính chính SPEC 2026-09-16:
     * "không có nó thì người dùng không có cách nào kiểm chứng hay phản bác kết quả."
     *
     * Không dùng chung hàm với `PartiesRelationManager` dù hình dạng giống nhau: hai màn hình nói
     * về hai thao tác khác nhau ("mở vụ việc" và "thêm bên") nên bộ chuỗi tiếng Việt khác nhau, và
     * gộp lại sẽ đẻ ra một hàm nhận tiền tố khoá dịch làm tham số — khó đọc hơn chính đoạn nó thay
     * thế. Ghi ra đây để lần sau ai đó thấy hai đoạn giống nhau thì biết là có chủ đích.
     */
    private static function conflictSummary(ConflictCheckResult $result, ?string $overrideReason): string
    {
        $lines = [$result->matches->isEmpty()
            ? __('matters.conflict.no_matches')
            : $result->matches
                ->map(fn (ConflictMatch $match): string => sprintf(
                    '%s (%s) — %s, %s, %s: %s',
                    $match->matterCode,
                    $match->matterTypeName,
                    $match->partyRole->label(),
                    $match->partyName,
                    $match->tier->label(),
                    $match->level->label(),
                ))
                ->implode("\n")];

        if ($result->hasIncompleteParties()) {
            $lines[] = __('matters.conflict.incomplete', ['names' => implode(', ', $result->incompleteParties())]);
        }

        if (filled($overrideReason)) {
            $lines[] = __('matters.conflict.saved_overridden_reason', ['reason' => $overrideReason]);
        }

        return implode("\n", $lines);
    }

    /**
     * Minor 6: `CreateRecord::create()` tự gửi thông báo "Đã tạo" của Filament NGAY SAU
     * `handleRecordCreation()`, nên một lần lưu sạch hiện HAI thông báo chồng nhau — cái thứ hai
     * không nói gì mà cái của trang chưa nói, và nó đẩy câu về kết quả kiểm tra xung đột (thứ SPEC
     * §6.10 bắt buộc người dùng đọc) xuống dưới. Tắt hẳn: `notifySaved()` đã là thông báo thành
     * công của màn hình này.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return null;
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
