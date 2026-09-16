<?php

namespace App\Actions;

use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use App\Enums\Role;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\AddMatterPartyResult;
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
 *
 * **Trả về `AddMatterPartyResult` (bên + `ConflictCheckResult`), không chỉ `MatterParty` (fix
 * round 2, finding "kết quả kiểm tra không còn hiện trên đường thành công").** Trả trần
 * `MatterParty` từng khiến caller không còn cách nào hiển thị lại kết quả kiểm tra ở NHÁNH THÀNH
 * CÔNG — kể cả sau khi một manager ghi đè mức đỏ, party vẫn lưu được nhưng không ai thấy đã ghi
 * đè xung đột với hồ sơ nào. `PartiesRelationManager` giờ đọc `$addition->result` để gọi
 * `notifyConflictCheckResult()` trên MỌI nhánh (thành công lẫn hai catch), không chỉ hai catch.
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
    ): AddMatterPartyResult {
        // Bước 1.
        Gate::forUser($actor)->authorize('create', MatterParty::class);

        // Bước 2.
        /** @var array{0: ConflictCheckResult, 1: MatterParty} $checked */
        $checked = DB::transaction(function () use ($matter, $partyData, $actor): array {
            $party = $this->buildParty($matter, $partyData);

            // Actor truyền xuống `RunConflictCheck` (fix M3, review toàn nhánh, finding 2): dòng
            // `conflict_check_run` ở đây và dòng `matter_party_added` ở bước 4 là hai bằng chứng
            // của CÙNG một thao tác, nên phải ghi CÙNG một người. Trước bản sửa này chỉ dòng thứ
            // hai nhận actor tường minh, dòng thứ nhất rơi về `auth()` ambient — trên mọi đường
            // không có phiên `web` (job, lệnh console, test) hai dòng đó bất đồng về người thực
            // hiện, đúng chỗ chúng tồn tại để chứng minh ai đã kiểm tra.
            return [app(RunConflictCheck::class)->handle(collect([$party]), $matter, $actor), $party];
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
        return DB::transaction(function () use ($matter, $party, $result, $isOverridden, $overrideReason, $actor): AddMatterPartyResult {
            $matter->parties()->save($party);

            Audit::record('matter_party_added', $matter, [
                'party_id' => $party->id,
                'conflict_level' => $result->level->value,
                'conflict_overridden' => $isOverridden,
                'override_reason' => $isOverridden ? $overrideReason : null,
                'incomplete_conflict_parties' => $result->incompleteParties(),
            ], $actor);

            return new AddMatterPartyResult($party, $result);
        });
    }

    /**
     * `is_our_client = true` VỚI `client_id`: tên và định danh KHÔNG được lấy từ `$data['name']`/
     * `$data['id_number']`/`$data['phone']` do form gửi lên — luôn dựng lại từ hồ sơ `Client` thật đã khoá, giống hệt
     * `OpenMatter::buildOwnClientParty()` (fix round 2, finding C). Đây là chiếc cầu ĐÁNG TIN CẬY
     * DUY NHẤT qua `clients.id_number` (mã hoá, không có cột hash): một bên "là khách hàng của
     * văn phòng" mà định danh lấy từ form (có thể gõ sai, gõ khác hồ sơ gốc) sẽ tạo ra
     * `id_number_hash` KHÔNG khớp hồ sơ `Client` thật — và một hash sai là chính xác cách một lần
     * kiểm tra xung đột trong tương lai BỎ SÓT bên này, im lặng, không ai biết.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildParty(Matter $matter, array $data): MatterParty
    {
        $role = $data['role'] instanceof PartyRole ? $data['role'] : PartyRole::from($data['role']);
        $isOurClient = (bool) ($data['is_our_client'] ?? false);
        $clientId = $isOurClient ? ($data['client_id'] ?? null) : null;

        $party = new MatterParty([
            'matter_id' => $matter->id,
            'role' => $role,
            'is_our_client' => $isOurClient,
            'client_id' => $clientId,
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        if ($clientId !== null) {
            $client = Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();

            // Tên cũng lấy từ hồ sơ thật, cùng một lập luận và cùng cách `OpenMatter` đã làm:
            // tên là tầng so khớp thứ ba của SPEC §6.10, nên một cái tên gõ khác hồ sơ gốc làm
            // lệch đúng cột mà lần kiểm tra sau sẽ tra. Đây cũng là chỗ `AddMatterParty` từng
            // lệch khỏi `OpenMatter::buildOwnClientParty()`, nay hết lệch.
            $party->name = $client->name;

            return $party->identify($client->id_number, $client->phone);
        }

        return $party->identify($data['id_number'] ?? null, $data['phone'] ?? null);
    }
}
