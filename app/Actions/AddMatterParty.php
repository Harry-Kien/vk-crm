<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Thêm một bên vào vụ việc ĐANG chạy (SPEC §6.10 "…và mỗi lần thêm một bên mới vào vụ việc đang
 * chạy" — nhánh thứ hai của cùng quy tắc mà `OpenMatter` thực hiện cho lúc mở vụ việc; đọc
 * docblock lớp đó trước khi sửa ở đây, vì lớp này cố tình mirror lại đúng hình dạng hai giai đoạn
 * của nó thay vì phát minh một luồng khác).
 *
 * Hai giai đoạn, mỗi giai đoạn một `DB::transaction()` RIÊNG — KHÔNG gộp thành một transaction
 * duy nhất, cùng lý do OpenMatter tách: `RunConflictCheck` tự ghi activity log
 * `conflict_check_run` ở MỌI lần chạy, kể cả khi dẫn tới chặn; nếu giai đoạn kiểm tra và giai
 * đoạn lưu dùng chung một transaction rồi Action `throw` khi bị chặn, Laravel rollback cuốn theo
 * cả dòng nhật ký vừa ghi — đúng lúc nó cần tồn tại nhất (một lần chạy dẫn tới chặn). Bù lại, bên
 * mới KHÔNG BAO GIỜ được lưu trước khi biết kết quả kiểm tra (fix round 1, finding 1 và 5): nếu
 * `RunConflictCheck` ném lỗi bất ngờ ở giai đoạn 1, giai đoạn 1 tự rollback và không có
 * `MatterParty` nào được lưu — không còn tình huống "bên đã lưu, không có dòng kiểm tra".
 *
 *  1. Quyền: `MatterPartyPolicy::create` (Action tự kiểm tra, không tin caller — cùng quy ước
 *     `OpenMatter`/`TransitionMatterStage`). Đây là lớp phòng thủ THỨ HAI: `MatterPartyPolicy::
 *     create()` không nhận `Matter` (áp dụng theo `matter.update` nói chung, không theo từng vụ
 *     việc cụ thể — hạn chế đã biết, xem báo cáo Task 6), nên lớp phòng thủ chính vẫn là
 *     `PartiesRelationManager` tự `authorize()` CreateAction của nó theo `matter.update` trên
 *     ĐÚNG `$matter` đang mở.
 *  2. Giai đoạn kiểm tra (transaction riêng, luôn commit): dựng bên mới từ `$partyData` qua
 *     `identify()`, CHƯA lưu, rồi chạy `RunConflictCheck::handle(collect([$party]), $matter)` —
 *     `$matter` đã tồn tại nên Action tự nạp các bên đã có của vụ việc để xét lại cùng lúc (xem
 *     docblock `RunConflictCheck`), truyền đúng bên mới là đủ, không cần gộp thêm.
 *  3. Mức đỏ (`isBlocking()`): chặn, TRỪ KHI actor có vai `manager`/`admin` VÀ `$overrideReason`
 *     không rỗng (sau `trim`) — ném `ConflictBlocked`. Bất kỳ kết quả nào khác mà
 *     `requiresAcknowledgement()` là true (vàng, hoặc xanh có bên thiếu định danh — KHÔNG chỉ so
 *     `level === Yellow`, xem docblock `OpenMatter` bước 4 cho lý do): ném
 *     `ConflictAcknowledgementRequired` trừ khi `$acknowledged` khớp ĐÚNG `$result->level`. Giống
 *     hệt bước 4 của `OpenMatter`.
 *  4. Giai đoạn lưu (transaction riêng, chỉ chạy nếu không bị chặn/đã xác nhận đúng mức): lưu bên
 *     mới qua `$matter->parties()->save($party)`, ghi activity log `matter_party_added` (mức
 *     xung đột, có ghi đè hay không, lý do ghi đè nếu có, danh sách bên thiếu định danh) — không
 *     thay thế, không trùng lặp dòng `conflict_check_run` đã ghi ở bước 2.
 */
class AddMatterParty
{
    /**
     * @param  array<string, mixed>  $partyData  `role` (`PartyRole|string`), `name`, và tuỳ chọn
     *                                           `id_number`, `phone`, `address`, `note`,
     *                                           `is_our_client`, `client_id`.
     * @param  ConflictLevel|null  $acknowledged  Mức mà caller đã hiển thị cho người dùng và được
     *                                            tích xác nhận đã xem xét TRƯỚC lời gọi này, phải
     *                                            khớp CHÍNH XÁC `$result->level` của lần kiểm tra
     *                                            NÀY (cùng lý do dùng enum thay vì bool đơn thuần
     *                                            ở `OpenMatter`).
     */
    public function handle(
        Matter $matter,
        User $actor,
        array $partyData,
        ?string $overrideReason = null,
        ?ConflictLevel $acknowledged = null,
    ): MatterParty {
        // Bước 1.
        Gate::forUser($actor)->authorize('create', MatterParty::class);

        // Bước 2.
        /** @var array{0: ConflictCheckResult, 1: MatterParty} $checked */
        $checked = DB::transaction(function () use ($matter, $partyData): array {
            $party = $this->buildParty($matter, $partyData);

            return [app(RunConflictCheck::class)->handle(collect([$party]), $matter), $party];
        });

        [$result, $party] = $checked;

        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;
        $isOverridden = false;

        // Bước 3.
        if ($result->isBlocking()) {
            $canOverride = ($actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value))
                && $overrideReason !== null && $overrideReason !== '';

            if (! $canOverride) {
                throw ConflictBlocked::make($result);
            }

            $isOverridden = true;
        } elseif ($result->requiresAcknowledgement() && $acknowledged !== $result->level) {
            throw ConflictAcknowledgementRequired::make($result);
        }

        // Bước 4.
        return DB::transaction(function () use ($matter, $party, $result, $isOverridden, $overrideReason, $actor): MatterParty {
            $matter->parties()->save($party);

            Audit::record('matter_party_added', $matter, [
                'party_id' => $party->id,
                'conflict_level' => $result->level->value,
                'conflict_overridden' => $isOverridden,
                'override_reason' => $isOverridden ? $overrideReason : null,
                'incomplete_conflict_parties' => $result->incompleteParties(),
            ], $actor);

            return $party;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function buildParty(Matter $matter, array $data): MatterParty
    {
        $role = $data['role'] instanceof PartyRole ? $data['role'] : PartyRole::from($data['role']);
        $isOurClient = (bool) ($data['is_our_client'] ?? false);

        return (new MatterParty([
            'matter_id' => $matter->id,
            'role' => $role,
            'is_our_client' => $isOurClient,
            'client_id' => $isOurClient ? ($data['client_id'] ?? null) : null,
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'note' => $data['note'] ?? null,
        ]))->identify($data['id_number'] ?? null, $data['phone'] ?? null);
    }
}
