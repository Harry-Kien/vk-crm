<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Mở vụ việc mới (SPEC §6.10 đầu bài "trước khi lưu vụ việc mới"; §11 "Xung đột lợi ích").
 *
 *  1. Kiểm tra quyền qua `MatterPolicy::create` — Action tự kiểm tra, không tin caller (cùng quy
 *     ước với `TransitionMatterStage`).
 *  2. `$attributes['client_role']` BẮT BUỘC (fix round 1, finding 2) — KHÔNG có mặc định. Vai của
 *     khách hàng chính quyết định `$ourClientRoles` mà `RunConflictCheck::isOpposing()` dùng để
 *     tính mức đỏ; một mặc định âm thầm (từng là `plaintiff`) khiến MỌI vụ việc mà khách hàng
 *     chính thật ra là bị đơn bị tính sai vai, hạ mức đỏ xuống vàng một cách im lặng — đúng lỗ
 *     hổng người xem xét tìm thấy. Thiếu khoá này ném `ValidationException` ngay, không suy đoán.
 *  3. **Giai đoạn kiểm tra, trong transaction RIÊNG (luôn commit, không bao giờ bị rollback bởi
 *     nhánh chặn):** khoá dòng `clients` của `$attributes['client_id']` trong lúc kiểm tra chạy
 *     (brief yêu cầu — xem "Về hai khoá dòng" bên dưới), dựng "bên là khách hàng của chính vụ
 *     việc" (own-client party) từ hồ sơ `Client` đã khoá — dùng
 *     `identify($client->id_number, $client->phone)`, CHÍNH XÁC như
 *     `MatterPartyFactory::ourClient()` — rồi dựng các bên còn lại từ `$parties` (form), và chạy
 *     `RunConflictCheck` trên toàn bộ tập hợp (`$matter = null` vì vụ việc CHƯA lưu).
 *
 *     Đây là nghĩa vụ của Action, không phải của caller: một khách hàng không có dòng
 *     `matter_parties` là vô hình với `RunConflictCheck` (`clients` không có cột hash), nên nếu
 *     bỏ bước dựng own-client party, khách hàng CHÍNH của vụ việc mới sẽ không bao giờ bị đối
 *     chiếu — dù đó chính là tình huống SPEC §11 mô tả (bị đơn trùng căn cước với "một khách hàng
 *     hiện hữu").
 *
 *     Tách riêng transaction này khỏi transaction lưu vụ việc là CHỦ Ý: `RunConflictCheck` tự ghi
 *     activity log `conflict_check_run` ở MỌI lần chạy, kể cả khi kết quả dẫn tới chặn — nếu toàn
 *     bộ `handle()` nằm trong một `DB::transaction()` duy nhất và Action `throw` khi bị chặn,
 *     Laravel rollback CẢ dòng activity log đó, xoá mất bằng chứng đã kiểm tra đúng lúc nó quan
 *     trọng nhất (một lần chạy dẫn tới chặn). Two-phase đảm bảo dòng `conflict_check_run` luôn
 *     tồn tại, trong khi giai đoạn lưu (bước 5) chỉ chạy nếu không bị chặn/chưa được xác nhận —
 *     nên `Matter`/`MatterParty`/danh mục hồ sơ không bao giờ được tạo trong hai trường hợp đó.
 *
 *     **CẢNH BÁO CHO CALLER — không gọi `handle()` từ bên trong một transaction đang mở.**
 *     `DB::transaction()` lồng nhau chỉ tạo SAVEPOINT, không phải transaction độc lập: nếu một
 *     Filament create action (hay bất kỳ caller nào) tự bọc lời gọi này trong `DB::transaction()`
 *     của riêng nó, giai đoạn kiểm tra ở đây chỉ còn là một savepoint bên trong transaction đó —
 *     và khi `ConflictBlocked`/`ConflictAcknowledgementRequired` được ném ra rồi caller rollback
 *     transaction NGOÀI của họ, dòng `conflict_check_run` cũng bị cuốn theo, đúng thứ hai giai
 *     đoạn này được tách ra để tránh. Action KHÔNG tự kiểm tra điều kiện này lúc chạy (xem "Về
 *     việc không tự kiểm tra transaction lồng nhau" trong báo cáo Fix round 1) — đây là một ràng
 *     buộc phải tôn trọng ở nơi gọi.
 *  4. Mức đỏ (`isBlocking()`): chặn, TRỪ KHI actor có vai `manager`/`admin` VÀ `$overrideReason`
 *     không rỗng (sau `trim`). Ném `ConflictBlocked` (mang theo `ConflictCheckResult` để caller
 *     hiển thị lại danh sách bản ghi trùng) — giai đoạn kiểm tra ở bước 3 đã commit, giai đoạn lưu
 *     ở bước 5 chưa hề bắt đầu, nên không có gì bị tạo ra ngoài dòng activity log của chính lần
 *     kiểm tra. Mức vàng (fix round 1, finding 1): không chặn vĩnh viễn, nhưng caller PHẢI xác
 *     nhận đã xem xét bằng cách truyền `acknowledged: ConflictLevel::Yellow` — thiếu xác nhận ném
 *     `ConflictAcknowledgementRequired` (cùng khuôn với `ConflictBlocked`), và vì lỗi này được ném
 *     TRƯỚC giai đoạn lưu, vụ việc CHƯA tồn tại khi caller mới biết mức — không có chuyện "lưu rồi
 *     mới hỏi". Mức xanh không cần xác nhận gì.
 *  5. **Giai đoạn lưu, trong transaction riêng, chỉ chạy nếu không bị chặn VÀ (xanh HOẶC vàng đã
 *     được xác nhận):** tạo `Matter` (sinh mã qua `CodeSequence::next()` ở `Matter::creating()`),
 *     ghi các `MatterParty` đã dựng ở bước 3, sao chép danh mục hồ sơ từ template đang hoạt động
 *     mới nhất của loại vụ việc (nếu có), và ghi activity log `matter_opened` — causer truyền
 *     tường minh là `$actor` (không suy luận lại từ `auth()` ambient trong `Audit::record`, cùng
 *     actor đã được kiểm tra vai trò ở bước 4) — gồm kết quả kiểm tra xung đột, có ghi đè hay
 *     không, lý do ghi đè nếu có, danh sách bên thiếu định danh (`incompleteParties()`) — không
 *     thay thế, không trùng lặp dòng `conflict_check_run` đã ghi ở bước 3.
 *
 * **Về hai khoá dòng (fix round 1, finding 6/9 — ghi nhận trung thực, không phóng đại):**
 * `lockForUpdate()` trên `clients` ở bước 3 chỉ có tác dụng trong đúng thời gian giai đoạn kiểm
 * tra chạy — khoá được GIẢI PHÓNG khi transaction đó commit, TRƯỚC KHI bất kỳ ghi nào phái sinh từ
 * dữ liệu khách hàng (own-client party) được lưu ở bước 5. Nó ngăn một sửa đổi `id_number`/`phone`
 * xen ngang đúng lúc kiểm tra đọc, nhưng KHÔNG khoá khách hàng xuyên suốt toàn bộ lần mở vụ việc —
 * đúng nghĩa đen "khoá dòng khách hàng trong lúc kiểm tra chạy" của brief, không hơn.
 * `lockForUpdate()` trên `matters` trước khi sao chép danh mục hồ sơ (carry-forward M1) khoá một
 * dòng vừa được chính `Matter::create()` chèn vài dòng lệnh trước đó, TRONG CÙNG transaction —
 * dòng này vô hình với mọi transaction khác cho tới khi commit, nên không có transaction đồng
 * thời nào có thể tranh chấp nó ở đây; khoá này thoả mãn ĐÚNG NGUYÊN VĂN yêu cầu carry-forward,
 * nhưng không ngăn một race thực sự nào trong luồng gọi hiện tại của `OpenMatter` — nó chỉ có ý
 * nghĩa nếu một lời gọi `ApplyChecklistTemplate` khác (ngoài `OpenMatter`) từng chạy đồng thời
 * trên CÙNG một `Matter` đã tồn tại từ trước, điều không xảy ra ở đây vì `Matter` luôn mới tạo.
 */
class OpenMatter
{
    /**
     * @param  array<string, mixed>  $attributes  Thuộc tính `Matter` (SPEC §4.5 `matters`), bắt
     *                                            buộc có `client_id` và `client_role`
     *                                            (`PartyRole|string` — vai của khách hàng chính
     *                                            trong vụ việc này, KHÔNG có mặc định, xem bước 2
     *                                            ở docblock lớp). `client_role` không phải cột
     *                                            của `matters`, bị loại trước khi
     *                                            `Matter::create()`.
     * @param  array<int, array<string, mixed>>  $parties  Các bên KHÁC ngoài khách hàng chính của
     *                                                     vụ việc (bị đơn, liên quan, ...). Mỗi
     *                                                     phần tử: `role` (`PartyRole|string`),
     *                                                     `name`, và tuỳ chọn `id_number`,
     *                                                     `phone`, `address`, `note`,
     *                                                     `is_our_client`, `client_id`.
     * @param  ConflictLevel|null  $acknowledged  Mức mà caller đã hiển thị cho người dùng và được
     *                                            tích xác nhận đã xem xét TRƯỚC lời gọi này (SPEC
     *                                            §11 bullet 3). Chỉ có ý nghĩa khi bằng
     *                                            `ConflictLevel::Yellow` — mức đỏ có luồng riêng
     *                                            (`$overrideReason`), mức xanh không cần xác
     *                                            nhận gì. Dùng enum thay vì `bool $acknowledged`
     *                                            đơn thuần để caller không thể "xác nhận trước"
     *                                            một mức chưa biết: giá trị phải khớp CHÍNH XÁC
     *                                            mức mà lần kiểm tra NÀY trả về, nên một xác nhận
     *                                            lưu từ một request kiểm tra trước đó (mức có thể
     *                                            đã đổi vì dữ liệu đổi) không tự động hợp lệ nếu
     *                                            mức mới không phải vàng.
     */
    public function handle(
        array $attributes,
        array $parties,
        ?string $overrideReason = null,
        ?ConflictLevel $acknowledged = null,
    ): Matter {
        $actor = Auth::guard('web')->user();

        // Bước 1.
        Gate::forUser($actor)->authorize('create', Matter::class);

        // Bước 2.
        if (! array_key_exists('client_role', $attributes) || $attributes['client_role'] === null) {
            throw ValidationException::withMessages([
                'client_role' => [__('actions.open_matter.client_role_required')],
            ]);
        }

        $clientRole = $attributes['client_role'];
        $clientRole = $clientRole instanceof PartyRole ? $clientRole : PartyRole::from($clientRole);
        unset($attributes['client_role']);

        // Bước 3.
        /** @var array{0: ConflictCheckResult, 1: Collection<int, MatterParty>} $checked */
        $checked = DB::transaction(function () use ($attributes, $parties, $clientRole): array {
            $client = Client::query()->whereKey($attributes['client_id'])->lockForUpdate()->firstOrFail();

            $proposedParties = collect([
                $this->buildOwnClientParty($client, $clientRole),
                ...collect($parties)->map(fn (array $party) => $this->buildParty($party)),
            ]);

            return [app(RunConflictCheck::class)->handle($proposedParties), $proposedParties];
        });

        [$result, $proposedParties] = $checked;

        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;
        $isOverridden = false;

        // Bước 4.
        if ($result->isBlocking()) {
            $canOverride = $actor !== null
                && ($actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value))
                && $overrideReason !== null && $overrideReason !== '';

            if (! $canOverride) {
                throw ConflictBlocked::make($result);
            }

            $isOverridden = true;
        } elseif ($result->level === ConflictLevel::Yellow && $acknowledged !== ConflictLevel::Yellow) {
            throw ConflictAcknowledgementRequired::make($result);
        }

        // Bước 5.
        return DB::transaction(function () use (
            $attributes, $proposedParties, $result, $isOverridden, $overrideReason, $actor,
        ): Matter {
            $matter = Matter::create($attributes);

            $proposedParties->each(fn (MatterParty $party) => $matter->parties()->save($party));

            // Khoá dòng vụ việc trước khi sao chép danh mục hồ sơ (carry-forward M1) — xem "Về
            // hai khoá dòng" ở docblock lớp cho ý nghĩa thật của khoá này trong luồng hiện tại.
            $lockedMatter = Matter::query()->whereKey($matter->id)->lockForUpdate()->firstOrFail();

            // Nếu (đáng lẽ không xảy ra) loại vụ việc có nhiều hơn một template đang hoạt động,
            // chọn có chủ đích template MỚI NHẤT (id lớn nhất) thay vì nhận bất kỳ thứ tự ngầm
            // định nào của DB — quyết định tường minh, không phải mặc định tình cờ.
            $template = ChecklistTemplate::query()
                ->where('matter_type_id', $lockedMatter->matter_type_id)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();

            if ($template !== null) {
                app(ApplyChecklistTemplate::class)->handle($lockedMatter, $template);
            }

            Audit::record('matter_opened', $matter, [
                'conflict_level' => $result->level->value,
                'conflict_overridden' => $isOverridden,
                'override_reason' => $isOverridden ? $overrideReason : null,
                'incomplete_conflict_parties' => $result->incompleteParties(),
            ], $actor);

            return $matter;
        });
    }

    /**
     * Bên "khách hàng của chính vụ việc" — luôn dựng từ hồ sơ `Client` thật (đã khoá ở bước 3),
     * không bao giờ tin số căn cước/điện thoại do form gửi lên cho bên này, vì đó là dữ liệu duy
     * nhất đáng tin để bắc cầu qua `clients.id_number` (mã hoá, không có cột hash).
     */
    private function buildOwnClientParty(Client $client, PartyRole $role): MatterParty
    {
        return (new MatterParty([
            'role' => $role,
            'is_our_client' => true,
            'client_id' => $client->id,
            'name' => $client->name,
            'address' => $client->address,
        ]))->identify($client->id_number, $client->phone);
    }

    /** @param  array<string, mixed>  $data */
    private function buildParty(array $data): MatterParty
    {
        $role = $data['role'] instanceof PartyRole ? $data['role'] : PartyRole::from($data['role']);

        return (new MatterParty([
            'role' => $role,
            'is_our_client' => $data['is_our_client'] ?? false,
            'client_id' => $data['client_id'] ?? null,
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'note' => $data['note'] ?? null,
        ]))->identify($data['id_number'] ?? null, $data['phone'] ?? null);
    }
}
