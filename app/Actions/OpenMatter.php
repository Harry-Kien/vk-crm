<?php

namespace App\Actions;

use App\Enums\PartyRole;
use App\Enums\Role;
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

/**
 * Mở vụ việc mới (SPEC §6.10 đầu bài "trước khi lưu vụ việc mới"; §11 "Xung đột lợi ích").
 *
 *  1. Kiểm tra quyền qua `MatterPolicy::create` — Action tự kiểm tra, không tin caller (cùng quy
 *     ước với `TransitionMatterStage`).
 *  2. **Giai đoạn kiểm tra, trong transaction RIÊNG (luôn commit, không bao giờ bị rollback bởi
 *     nhánh chặn):** khoá dòng `clients` của `$attributes['client_id']` trong lúc kiểm tra chạy
 *     (brief yêu cầu), dựng "bên là khách hàng của chính vụ việc" (own-client party) từ hồ sơ
 *     `Client` đã khoá — dùng `identify($client->id_number, $client->phone)`, CHÍNH XÁC như
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
 *     tồn tại, trong khi giai đoạn lưu (bước 4) chỉ chạy nếu không bị chặn — nên `Matter`/
 *     `MatterParty`/danh mục hồ sơ không bao giờ được tạo khi bị chặn.
 *  3. Mức đỏ: chặn, TRỪ KHI actor có vai `manager`/`admin` VÀ `$overrideReason` không rỗng (sau
 *     `trim`). Ném `ConflictBlocked` (mang theo `ConflictCheckResult` để caller hiển thị lại danh
 *     sách bản ghi trùng) — giai đoạn kiểm tra ở bước 2 đã commit, giai đoạn lưu ở bước 4 chưa hề
 *     bắt đầu, nên không có gì bị tạo ra ngoài dòng activity log của chính lần kiểm tra.
 *  4. **Giai đoạn lưu, trong transaction riêng, chỉ chạy nếu không bị chặn:** tạo `Matter` (sinh
 *     mã qua `CodeSequence::next()` ở `Matter::creating()`), ghi các `MatterParty` đã dựng ở bước
 *     2, khoá lại dòng `matters` vừa tạo trước khi sao chép danh mục hồ sơ (carry-forward M1) rồi
 *     gọi `ApplyChecklistTemplate` với template đang hoạt động của loại vụ việc, nếu có, và ghi
 *     activity log `matter_opened`: kết quả kiểm tra xung đột, có ghi đè hay không, lý do ghi đè
 *     nếu có, danh sách bên thiếu định danh (`incompleteParties()`) — không thay thế, không trùng
 *     lặp dòng `conflict_check_run` đã ghi ở bước 2.
 */
class OpenMatter
{
    /**
     * @param  array<string, mixed>  $attributes  Thuộc tính `Matter` (SPEC §4.5 `matters`),
     *                                            bắt buộc có `client_id`. Khoá tuỳ chọn
     *                                            `client_role` (`PartyRole|string`, mặc định
     *                                            `plaintiff`) chọn vai của khách hàng chính
     *                                            trong vụ việc này — không phải cột của
     *                                            `matters`, bị loại trước khi `Matter::create()`.
     * @param  array<int, array<string, mixed>>  $parties  Các bên KHÁC ngoài khách hàng chính của
     *                                                     vụ việc (bị đơn, liên quan, ...). Mỗi
     *                                                     phần tử: `role` (`PartyRole|string`),
     *                                                     `name`, và tuỳ chọn `id_number`,
     *                                                     `phone`, `address`, `note`,
     *                                                     `is_our_client`, `client_id`.
     */
    public function handle(array $attributes, array $parties, ?string $overrideReason = null): Matter
    {
        $actor = Auth::guard('web')->user();

        // Bước 1.
        Gate::forUser($actor)->authorize('create', Matter::class);

        $clientRole = $attributes['client_role'] ?? PartyRole::Plaintiff;
        $clientRole = $clientRole instanceof PartyRole ? $clientRole : PartyRole::from($clientRole);
        unset($attributes['client_role']);

        // Bước 2.
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

        // Bước 3.
        if ($result->isBlocking()) {
            $canOverride = $actor !== null
                && ($actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value))
                && $overrideReason !== null && $overrideReason !== '';

            if (! $canOverride) {
                throw ConflictBlocked::make($result);
            }

            $isOverridden = true;
        }

        // Bước 4.
        return DB::transaction(function () use ($attributes, $proposedParties, $result, $isOverridden, $overrideReason): Matter {
            $matter = Matter::create($attributes);

            $proposedParties->each(fn (MatterParty $party) => $matter->parties()->save($party));

            $lockedMatter = Matter::query()->whereKey($matter->id)->lockForUpdate()->firstOrFail();

            $template = ChecklistTemplate::query()
                ->where('matter_type_id', $lockedMatter->matter_type_id)
                ->where('is_active', true)
                ->first();

            if ($template !== null) {
                app(ApplyChecklistTemplate::class)->handle($lockedMatter, $template);
            }

            Audit::record('matter_opened', $matter, [
                'conflict_level' => $result->level->value,
                'conflict_overridden' => $isOverridden,
                'override_reason' => $isOverridden ? $overrideReason : null,
                'incomplete_conflict_parties' => $result->incompleteParties(),
            ]);

            return $matter;
        });
    }

    /**
     * Bên "khách hàng của chính vụ việc" — luôn dựng từ hồ sơ `Client` thật (đã khoá ở bước 2),
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
