<?php

namespace App\Filament\Admin\Resources\Matters\Pages;

use App\Actions\Client\CreateClient;
use App\Actions\Client\FindClientByIdentifier;
use App\Actions\OpenMatter;
use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Enums\Permission;
use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\DuplicateClientNotVisible;
use App\Filament\Admin\Resources\Matters\MatterResource;
use App\Filament\Admin\Support\VisibleClientOptions;
use App\Models\Client;
use App\Models\User;
use App\Support\ClientVisibility;
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

    /**
     * Id của hồ sơ `Client` cần dùng cho lần lưu này, hoặc `null` (M6.5 Task 6, R4). Chỉ có ý
     * nghĩa cho actor KHÔNG có `client.manage` — xem `MatterForm::lawyerClientLookupFields()`. Có
     * giá trị theo HAI cách, cả hai đều ở phía máy chủ, không bao giờ từ dữ liệu client tự khai:
     *
     *  - `lookupClient()` gán khi tra ĐÚNG được một hồ sơ đã có (R4 a).
     *  - `resolveClientId()` gán khi dò trùng của khối "Tạo khách mới" (R4 b) khớp một hồ sơ ĐÃ
     *    CÓ mà actor thấy được (`CreateClient::resolve()` trả hồ sơ đó để dùng lại). Một hồ sơ
     *    MỚI thì không còn gán ở đây (final review A-M7): nó chưa được lưu cho tới bước lưu của
     *    `OpenMatter` — xem `$pendingNewClient`.
     *
     * `#[Locked]` cùng lý do `$conflictResult`: dù có giá trị bằng cách nào, đây luôn là kết quả
     * một lần gọi Action THẬT ở máy chủ — nếu client sửa được nó, một payload dàn dựng có thể tự
     * gán một id KHÔNG nằm trong tầm nhìn của actor, đúng lỗ hổng
     * `VisibleClientOptions::assertVisibleToCurrentUser()` tồn tại để chặn. Vì
     * `mutateFormDataBeforeCreate()` dùng THẲNG giá trị này làm `client_id` (không hỏi lại
     * `VisibleClientOptions`, xem docblock ở đó), nó phải bất khả xâm phạm từ phía client.
     */
    #[Locked]
    public ?int $resolvedClientId = null;

    /**
     * Câu đã dịch sẵn hiện cho người dùng khi `resolvedClientId` có giá trị — do `lookupClient()`
     * gán khi tra ĐÚNG được (R4 a), hoặc do `resolveClientId()` gán khi khối "Tạo khách mới" khớp
     * một hồ sơ đã có để dùng lại (R4 b): một khi `resolvedClientId` khác
     * `null`, khối "Tạo khách mới" tự ẩn đi (`MatterForm::newClientFields()`) và nhường chỗ cho
     * câu này — không có nó, một lượt gửi lại sau khi bị chặn xung đột sẽ hiện một khối kết quả
     * tra RỖNG thay vì nói rõ hồ sơ nào đang được dùng.
     */
    #[Locked]
    public ?string $resolvedClientLabel = null;

    /**
     * Final review A-M7: hồ sơ khách hàng MỚI (chưa lưu) mà `resolveClientId()` dựng từ khối "Tạo
     * khách mới" trong CHÍNH request đang lưu, chuyển sang `handleRecordCreation()` để `OpenMatter`
     * chỉ lưu nó sau khi kiểm tra xung đột cho qua. `private` — Livewire không tuần tự hoá nó, nên nó
     * không bao giờ sống sót sang request sau hay đến tay trình duyệt; mỗi lượt gửi dựng lại từ form.
     */
    private ?Client $pendingNewClient = null;

    /**
     * Final review wave 2, M-5: dấu vân tay của khối "Tạo khách mới" mà `CreateClient::resolve()`
     * đã xét và trả về MỘT HỒ SƠ MỚI chưa lưu ở một lượt gửi trước. Lượt gửi lại (sau lời nhắc
     * xung đột) với CÙNG dữ liệu dựng lại hồ sơ đó qua `CreateClient::unsaved()` — không dò trùng
     * lại, không tốn thêm suất tra cứu. Dữ liệu đổi (người dùng sửa khối) thì vân tay đổi và lần dò
     * chạy lại, đúng như phải thế. `#[Locked]`: chỉ máy chủ ghi được.
     */
    #[Locked]
    public ?string $resolvedNewClientFingerprint = null;

    /**
     * Tra ĐÚNG một hồ sơ `Client` theo định danh vừa gõ (M6.5 Task 6, R4a) — gọi từ
     * `afterStateUpdated` của `client_lookup_identifier` (`MatterForm::lawyerClientLookupFields()`).
     *
     * Bỏ trống ô tra (identifier rỗng) xoá luôn kết quả cũ: một luật sư xoá số đã gõ để chuyển
     * sang tạo khách mới không được để `resolvedClientId` của lần tra TRƯỚC còn sống sót và âm
     * thầm ghi đè khối "Tạo khách mới" họ sắp điền.
     *
     * **`ClientLookupThrottled`/`DuplicateClientNotVisible` bắt ở ĐÂY, không để lọt ra ngoài.**
     * Hàm này là một Livewire action gọi từ `afterStateUpdated` khi rời ô — khác
     * `mutateFormDataBeforeCreate()` (nơi Livewire tự bắt `ValidationException` thành lỗi form),
     * một ngoại lệ ném ra từ đây sẽ lọt thẳng thành lỗi 500 chung chung. `Notification::make()->
     * warning()` là cách đúng để báo một lỗi không gắn với ô nào cụ thể của form.
     * `DuplicateClientNotVisible` (fix round 2, E1) dùng CHUNG câu trung lập với nhánh "tạo khách
     * mới" — xem docblock `FindClientByIdentifier` và `ClientVisibility::isOfferableByLookup()`.
     *
     * **Fix round 2 (Minor) — cả hai nhánh `catch` đều XOÁ SẠCH kết quả cũ, không chỉ báo lỗi rồi
     * dừng.** Bản round 1 chỉ `return` sau khi báo — nếu ô tra đang giữ một kết quả THÀNH CÔNG từ
     * lần gõ TRƯỚC (`resolvedClientId` khác `null`) và lần gõ MỚI này bị chặn (quá tần suất, hay
     * khớp một khách hàng không tra ra được), kết quả CŨ vẫn còn nguyên — form âm thầm dùng một
     * hồ sơ khách hàng không phải hồ sơ ứng với số VỪA gõ. Xoá cả `resolvedClientId`,
     * `resolvedClientLabel` lẫn bảng kết quả xung đột (`forgetConflictResult()`) trong CẢ hai
     * nhánh lỗi, đúng nguyên tắc "một lần đổi khách hàng (dù thành hay không) đều làm mất hiệu
     * lực bất kỳ kết quả cũ nào".
     */
    public function lookupClient(?string $identifier): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            $this->resolvedClientId = null;
            $this->resolvedClientLabel = null;
            $this->forgetConflictResult();

            return;
        }

        try {
            $client = app(FindClientByIdentifier::class)->handle($actor, $identifier);
        } catch (ClientLookupThrottled|DuplicateClientNotVisible $exception) {
            $this->resolvedClientId = null;
            $this->resolvedClientLabel = null;
            $this->forgetConflictResult();

            Notification::make()->title($exception->getMessage())->warning()->send();

            return;
        }

        $this->resolvedClientId = $client?->getKey();
        $this->resolvedClientLabel = $client === null
            ? null
            : __('matters.create_form.client_lookup_found', ['code' => $client->code, 'name' => $client->name]);

        // Đổi khách hàng (tìm thấy hay không) làm bảng kết quả kiểm tra xung đột đang hiện không
        // còn nói về vụ việc này nữa — cùng lý do mọi ô "có thể đổi khách hàng" khác của form.
        $this->forgetConflictResult();
    }

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
        $newMatches = $result['matches'] ?? [];
        // R13c/`conflict-01` (M6.5 Task 8): khớp đã xác nhận/ghi đè ở một lần chạy TRƯỚC trên
        // cùng vụ việc không còn tính vào $level, nhưng "vẫn hiện" — gộp cả hai danh sách để hiển
        // thị, chỉ khác ở cờ `already_confirmed` bên dưới. Xem docblock `ConflictCheckResult`.
        $confirmedMatches = $result['confirmed_matches'] ?? [];
        $incompleteParties = $result['incomplete_parties'] ?? [];
        $level = $result['level'] ?? ConflictLevel::Green->value;

        $formatMatch = fn (array $match, bool $alreadyConfirmed): array => [
            'matter_code' => $match['matter_code'],
            'matter_type_name' => $match['matter_type_name'],
            'party_role' => PartyRole::from($match['party_role'])->label(),
            'party_name' => $match['party_name'],
            'tier' => ConflictMatchTier::from($match['tier'])->label(),
            'level' => ConflictLevel::from($match['level'])->label(),
            // R13d/`conflict-07`: vai + tên của bên PHÍA MÌNH gây ra khớp này — không phải bên
            // tìm thấy. Cùng sáu trường còn lại, dịch ở đây chứ không trong blade, cùng lý do.
            'our_party_role' => PartyRole::from($match['our_party_role'])->label(),
            'our_party_name' => $match['our_party_name'],
            'already_confirmed' => $alreadyConfirmed,
        ];

        return [
            'level' => $level,
            // Cùng luật với PartiesRelationManager::notifyConflictCheckResult(): một mức xanh có
            // bên thiếu định danh KHÔNG được mang màu "sạch".
            'requiresAttention' => $level !== ConflictLevel::Green->value || $incompleteParties !== [],
            'matches' => [
                ...array_map(fn (array $match): array => $formatMatch($match, false), $newMatches),
                ...array_map(fn (array $match): array => $formatMatch($match, true), $confirmedMatches),
            ],
            'incompleteParties' => $incompleteParties,
        ];
    }

    /**
     * `VisibleClientOptions`/`leadLawyerOptions()` chỉ hạn chế những gì ô chọn HIỂN THỊ — một
     * request bị chỉnh sửa vẫn gửi thẳng được một id ngoài tầm nhìn. Chặn thật ở đây, đúng lúc mọi
     * id đã biết, giống hệt `CreateClientUser::mutateFormDataBeforeCreate()`.
     *
     * **`client_id` của VỤ VIỆC giờ đi qua `resolveClientId()`, không còn hỏi thẳng
     * `assertVisibleToCurrentUser()` vô điều kiện (M6.5 Task 6, R4).** Với ai có `client.manage`
     * (hoặc một luật sư vẫn chọn được khách cũ từ danh sách), hành vi không đổi: giá trị Select
     * gửi lên vẫn phải nằm trong `VisibleClientOptions::forCurrentUser()`. Với một luật sư đã
     * TRA hoặc TẠO một khách hàng mới (R4 a/b), `resolveClientId()` bỏ qua HẲN `$data['client_id']`
     * mà form gửi lên và tự tính lại từ `$this->resolvedClientId` (kết quả một lần tra THẬT,
     * `#[Locked]`) hoặc bằng cách gọi `CreateClient::handle()` — một request bị chỉnh sửa không
     * thể forge được client_id theo đường này: giá trị cuối cùng luôn bắt nguồn từ một lần gọi
     * Action thật ở phía máy chủ, không phải từ dữ liệu client tự khai.
     *
     * **Cả `client_id` của TỪNG BÊN trong repeater, không chỉ của vụ việc (review fix round 3,
     * finding I-5).** Bên nào bật "là khách hàng của văn phòng" cũng mang một `client_id` xuống
     * `OpenMatter`, và `BuildsMatterParties` lấy TÊN + định danh của bên đó thẳng từ hồ sơ `Client`
     * đã khoá — nên một id giả mạo ghi TÊN THẬT của một khách hàng ngoài tầm nhìn lên một dòng bên
     * mà người gửi đọc lại được ngay sau khi lưu. (Khối này KHÔNG có hai đường thay thế của R4 —
     * ngoài phạm vi Task 6, xem brief.)
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
        $data['client_id'] = static::resolveClientId($this, $data);

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

    /**
     * Ba nguồn của `client_id`, theo đúng thứ tự ưu tiên (M6.5 Task 6, R4):
     *
     *  1. `$livewire->resolvedClientId` — một lần TRA THẬT vừa khớp (R4 a, `lookupClient()`), HOẶC
     *     một hồ sơ đã có mà (2) quyết định dùng lại ở một lượt gửi trước. `#[Locked]`, nên đây là
     *     bằng chứng không thể giả mạo từ phía client.
     *  2. `$data['new_client']['name']` có điền — khối "Tạo khách mới" (R4 b). Gọi
     *     `CreateClient::resolve()`, Action đó tự dò trùng và tự quyết định dùng hồ sơ cũ hay dựng
     *     hồ sơ mới (xem docblock `CreateClient`) — màn hình không đoán thay. Hồ sơ cũ → trả id
     *     của nó. Hồ sơ MỚI → trả `null` và giữ bản CHƯA LƯU ở `$pendingNewClient`.
     *  3. Không có gì ở (1)/(2): hành vi CŨ — `$data['client_id']` do Select gửi lên, phải nằm
     *     trong `VisibleClientOptions::forCurrentUser()`.
     *
     * **Khách hàng mồ côi (Task 6 probe, sửa lại ở final review A-M7).** `mutateFormDataBeforeCreate()`
     * chạy TRƯỚC `handleRecordCreation()`. Bản Task 6 tạo `Client` ngay ở đây, TRƯỚC KHI
     * `OpenMatter` kịp kiểm tra xung đột — một lượt bị chặn đỏ/vàng để lại một hồ sơ đã COMMIT mà
     * không vụ việc nào dùng (bản đó chỉ tránh được hồ sơ THỨ HAI bằng cách nhớ id vào
     * `resolvedClientId`, nhưng hồ sơ đầu vẫn mồ côi nếu người dùng bỏ đi, và lượt gửi lại bỏ qua
     * mọi chỉnh sửa khối "Tạo khách mới"). Giờ hồ sơ mới được `OpenMatter` lưu ở bước lưu, SAU khi
     * kiểm tra cho qua (tham số `$newClient`); lượt bị chặn không ghi gì, khối "Tạo khách mới" còn
     * trên form, và lượt gửi lại dựng lại hồ sơ từ đúng dữ liệu vừa sửa.
     *
     * Tách static + nhận `$livewire` tường minh để test được trực tiếp, cùng lý do
     * `leadLawyerOptions()`.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveClientId(self $livewire, array $data): ?int
    {
        $livewire->pendingNewClient = null;

        if ($livewire->resolvedClientId !== null) {
            $actor = Auth::user();
            abort_unless($actor instanceof User, 403);

            // Fix round 2, E1: hỏi LẠI đúng luật `isOfferableByLookup()` tại thời điểm LƯU, không
            // chỉ tin kết quả của lần TRA (hay lần tạo/dùng lại) đã xảy ra trước đó — một khoảng
            // trống giữa hai lượt (vụ DUY NHẤT của khách hàng chuyển sang `restricted`, hoặc bị
            // xoá mềm) không được để một kết quả cũ còn hiệu lực. Cùng câu trung lập với
            // `FindClientByIdentifier`/`CreateClient` — không nêu tên hay mã hồ sơ.
            if (! ClientVisibility::isOfferableByLookup($actor, $livewire->resolvedClientId)) {
                throw ValidationException::withMessages([
                    $livewire->errorKey('client_lookup_identifier') => [DuplicateClientNotVisible::make()->getMessage()],
                ]);
            }

            return $livewire->resolvedClientId;
        }

        if (filled($data['new_client']['name'] ?? null)) {
            $actor = Auth::user();
            abort_unless($actor instanceof User, 403);

            // Fix round 1, Minor: `CreateClient::handle()` có thể ném một luật nghiệp vụ
            // (`DuplicateClientDetected` khi một client.manage bị chỉnh payload lọt qua được
            // `dehydrated(false)` của `MatterForm::newClientFields()`; `DuplicateClientNotVisible`
            // — C1 — hay `ClientLookupThrottled` — I1 — cho một luật sư bình thường). Không có
            // `try/catch` ở đây, bất kỳ luật nào trong số đó thoát ra thành lỗi 500: hàm này chạy
            // TRONG `mutateFormDataBeforeCreate()`, TRƯỚC `handleRecordCreation()` — lưới an toàn
            // `catch (DomainException)` ở đó không với tới được đây.
            // Final review wave 2, M-5: cùng dữ liệu với lần dò trước đã cho ra một hồ sơ MỚI → dựng
            // lại hồ sơ đó, không dò lại (không tốn thêm suất tra cứu).
            $fingerprint = static::newClientFingerprint($data['new_client']);

            if ($livewire->resolvedNewClientFingerprint === $fingerprint) {
                $livewire->pendingNewClient = app(CreateClient::class)->unsaved($data['new_client']);

                return null;
            }

            try {
                $client = app(CreateClient::class)->resolve($actor, $data['new_client']);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages([
                    $livewire->errorKey('new_client.name') => [$exception->getMessage()],
                ]);
            }

            // Final review A-M7: một hồ sơ MỚI chưa được lưu ở đây — `OpenMatter` chỉ lưu nó ở bước
            // lưu, SAU khi kiểm tra xung đột cho qua (tham số `$newClient`). Lượt bị chặn không để
            // lại khách hàng mồ côi, và khối "Tạo khách mới" còn nguyên trên form nên lượt gửi lại
            // dùng đúng dữ liệu người dùng vừa sửa.
            if (! $client->exists) {
                $livewire->pendingNewClient = $client;
                $livewire->resolvedNewClientFingerprint = $fingerprint;

                return null;
            }

            // Dùng LẠI một hồ sơ đã có (dò trùng khớp một khách hàng actor thấy được):
            // `resolvedClientLabel` đi kèm (cùng câu dịch với `lookupClient()`) — khối "Tạo khách
            // mới" tự ẩn ngay khi `resolvedClientId !== null` (`MatterForm::newClientFields()`),
            // nên đây là thứ DUY NHẤT còn lại để người dùng biết mình đang thao tác trên hồ sơ nào.
            $livewire->resolvedClientId = $client->getKey();
            $livewire->resolvedClientLabel = __('matters.create_form.client_lookup_found', [
                'code' => $client->code,
                'name' => $client->name,
            ]);

            return $livewire->resolvedClientId;
        }

        VisibleClientOptions::assertVisibleToCurrentUser($data['client_id'] ?? null);

        return (int) $data['client_id'];
    }

    /**
     * Final review wave 2, M-5: vân tay của khối "Tạo khách mới" — các ô `CreateClient` đọc, đã
     * cắt khoảng trắng, theo thứ tự cố định.
     *
     * @param  array<string, mixed>  $newClient
     */
    private static function newClientFingerprint(array $newClient): string
    {
        $fields = ['type', 'name', 'id_number', 'phone', 'email', 'representative_name', 'address', 'note'];

        return hash('sha256', json_encode(array_map(
            fn (string $field): string => trim((string) ($newClient[$field] ?? '')),
            array_combine($fields, $fields),
        ), JSON_THROW_ON_ERROR));
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
                newClient: $this->pendingNewClient,
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

        // Wave 2, M-5: hồ sơ khách mới đã được lưu — một khối "Tạo khách mới" giống hệt ở lượt
        // "Tạo & tạo thêm" kế tiếp phải được dò trùng lại (và khớp đúng hồ sơ vừa lưu).
        $this->resolvedNewClientFingerprint = null;

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

        // Fix round 1, C3 (Critical, `conflict-01`): bốn mức, không ba — một vụ việc mở SẠCH về
        // khớp MỚI nhưng còn mang khớp đã xác nhận/ghi đè ở một lần chạy trước (R13c) không được
        // phép hiện tiêu đề + màu của "không tìm thấy xung đột": thân thông báo vẫn kể ra những
        // khớp đó, có thể ở mức Đỏ — một tiêu đề "sạch" màu success đứng cạnh một dòng "…: Đỏ" tự
        // mâu thuẫn với chính nó.
        [$title, $color] = match (true) {
            $opening->overridden => [__('matters.conflict.saved_overridden'), 'danger'],
            $needsAttention => [__('matters.conflict.saved_after_review'), 'warning'],
            $result->confirmedMatches->isNotEmpty() => [
                __('matters.conflict.saved_clear_with_confirmed', ['count' => $result->confirmedMatches->count()]),
                'warning',
            ],
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
     *
     * **Fix round 1 — spec gap: "bên phía mình" (R13d) và nhãn "đã xem xét ở lần trước" (R13c)
     * giờ có trong CHÍNH thông báo.** Bảng trong form (`conflictResultViewData()`) đã có hai nhãn
     * này từ round 0, nhưng đây là bản ghi CUỐI CÙNG người dùng còn đọc được sau khi trang chuyển
     * sang hồ sơ vừa mở — thiếu chúng ở đây là thiếu hẳn, không phải thiếu một bản sao của bảng.
     */
    private static function conflictSummary(ConflictCheckResult $result, ?string $overrideReason): string
    {
        $formatMatch = fn (ConflictMatch $match, bool $alreadyConfirmed): string => sprintf(
            '%s (%s) — %s, %s, %s: %s — %s: %s, %s%s',
            $match->matterCode,
            $match->matterTypeName,
            $match->partyRole->label(),
            $match->partyName,
            $match->tier->label(),
            $match->level->label(),
            __('matters.conflict.column_our_party'),
            $match->ourPartyRole->label(),
            $match->ourPartyName,
            $alreadyConfirmed ? ' — '.__('matters.conflict.already_confirmed') : '',
        );

        $allLines = $result->matches->map(fn (ConflictMatch $match) => $formatMatch($match, false))
            ->concat($result->confirmedMatches->map(fn (ConflictMatch $match) => $formatMatch($match, true)));

        $lines = [$allLines->isEmpty() ? __('matters.conflict.no_matches') : $allLines->implode("\n")];

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
