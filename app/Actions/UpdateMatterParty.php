<?php

namespace App\Actions;

use App\Actions\Concerns\BuildsMatterParties;
use App\Enums\ConflictLevel;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Exceptions\MatterPartyAlreadyRemoved;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictOverride;
use App\Support\Normalizer;
use App\Support\UpdateMatterPartyResult;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sửa MỘT bên đã có của một vụ việc (SPEC §4.16, §6.10, §7.2 tab "Các bên"; M6.5 Task 9,
 * `conflict-05`). Trước Task 9, không Action nào sửa được một bên — bảng chỉ có `CreateAction`, và
 * một số căn cước/điện thoại gõ sai lúc tiếp nhận nằm lại MÃI MÃI: hash sai không bao giờ tự sửa,
 * và một vụ kiện đúng người đó về sau, dù nhập đúng số, cứ ra XANH vì hash không khớp.
 *
 * Lớp này CỐ Ý mirror lại đúng hình dạng hai giai đoạn của `AddMatterParty` — đọc docblock lớp đó
 * trước khi sửa ở đây — với đúng một khác biệt về NGHIỆP VỤ (không phải hình dạng): bên đang xét đã
 * TỒN TẠI, nên "kiểm tra lại như lúc thêm" (brief) nghĩa là kiểm tra lại TRÊN CHÍNH dòng đó, không
 * dựng một dòng mới.
 *
 *  1. Quyền: `MatterPartyPolicy::update` trên bản ghi đã khoá (bước 5b) — cùng cổng gỡ
 *     (`MatterPartyPolicy::delete` gọi lại chính `update`), và cùng lớp phòng thủ hai tầng đã ghi ở
 *     `AddMatterParty` (policy không nhận `Matter` nên `PartiesRelationManager::editPartyAction()`
 *     tự `authorize()` action viết tay theo `matter.update` trên ĐÚNG bản ghi đang sửa).
 *  2. **Một khoá DUY NHẤT cho MỌI lần sửa `matter_parties` (fix round 1, C2 — phán quyết của chủ
 *     nhiệm, thay cho tài liệu SAI ở bản round 0).** Bản round 0 của docblock này viết
 *     "`RemoveMatterParty` không đi qua khoá `conflict-check` — xoá mềm không cần chạy lại kiểm
 *     tra", ngụ ý chỉ `AddMatterParty`/`UpdateMatterParty`/`OpenMatter` cần khoá đó. SAI: bốn Action
 *     ghi vào `matter_parties` (`AddMatterParty`, `UpdateMatterParty`, `OpenMatter` ở bước lưu bên,
 *     VÀ `RemoveMatterParty`) đều phải tranh chấp qua CÙNG một khoá ứng dụng
 *     `Cache::store('database')->lock('conflict-check', 30)`, cùng TTL (30s) và thời gian chờ
 *     (10s) — không chỉ ba Action có gọi `RunConflictCheck`. Không khoá `RemoveMatterParty` để lại
 *     đúng cửa sổ đua tranh R13(g) tồn tại để chặn: một `AddMatterParty` khác có thể đang ở giữa
 *     giai đoạn kiểm tra (đã đọc `matter_parties` của vụ việc để tính `$ourClientRoles`) trong lúc
 *     `RemoveMatterParty` xoá mềm một bên NGAY GIỮA đó — kết quả kiểm tra của `AddMatterParty` dựa
 *     trên một tập hợp bên đã LỖI THỜI ngay khi nó vừa tính xong, không phải một ảnh chụp nhất
 *     quán. `RemoveMatterParty` giờ khoá y hệt, và `LockTimeoutException` thành `ConflictCheckBusy`
 *     y hệt (không còn chờ vô hạn, không còn 500 trần).
 *  3. **Lock discipline (brief, riêng cho Task 9): câu ĐẦU TIÊN của MỖI transaction là khoá dòng vụ
 *     việc.** Không như `AddMatterParty`/`OpenMatter` (nơi giai đoạn kiểm tra không đụng gì tới
 *     dòng `matters`), `UpdateMatterParty` và `RemoveMatterParty` đều có thể tranh chấp trực tiếp
 *     trên CÙNG một dòng `matter_parties` của CÙNG một vụ việc, nên cả hai phải khoá `matters` LÀM
 *     CÂU ĐẦU TIÊN của từng transaction — đúng lý lẽ đã ghi ở `UpdateMatterDetails`/
 *     `RemoveTeamMember` (một câu đọc trần đứng trước khoá sẽ cố định READ VIEW của REPEATABLE READ
 *     tại một ảnh chụp CŨ, khiến mọi câu đọc SAU ĐÓ trong transaction, kể cả sau khi khoá đã cấp,
 *     vẫn thấy dữ liệu cũ). Khoá dòng `matter_parties` đang sửa là câu THỨ HAI (không phải câu đầu
 *     — không sao, kỷ luật chỉ đòi câu ĐẦU là khoá `matters`), ngay sau khoá vụ việc, trong CÙNG
 *     transaction.
 *  4. **Bên đã bị GỠ dưới khoá (fix round 1, C1).** Hai tab cùng nhìn một bên: tab A gỡ nó, tab B
 *     (modal sửa đã mở TỪ TRƯỚC, không biết) gửi lại. `MatterParty` dùng `SoftDeletes`, nên khoá
 *     xong mà không tìm thấy dòng (đã bị `SoftDeletingScope` loại, hoặc chưa từng có id đó) từng
 *     ném `ModelNotFoundException` trần — một trang lỗi 404 không câu tiếng Việt nào, và (khác mọi
 *     luật nghiệp vụ khác của Action này) KHÔNG phải `DomainException` nên không đi qua được lưới
 *     `catch (DomainException)` mà `PartiesRelationManager` đã có sẵn. Giờ `lockForUpdate()->first()`
 *     (không phải `firstOrFail()`) và ném `MatterPartyAlreadyRemoved` khi `null` — đúng lớp
 *     `DomainException` màn hình đã bắt, chỉ cần dạy nó hiện một Notification thay vì giữ modal mở
 *     (không còn ô nào để gắn lỗi vào — cả dòng đã biến mất).
 *  5. **Giai đoạn kiểm tra (transaction riêng, luôn commit — cùng lý do two-phase của
 *     `AddMatterParty`):**
 *     a. Khoá dòng `matters`, rồi khoá dòng `matter_parties` đang sửa (bước 4 — ném
 *        `MatterPartyAlreadyRemoved` nếu không còn).
 *     b. Quyền (bước 1).
 *     c. Chụp định danh CŨ (`name`, `id_number_hash`, `phone_normalized`, `client_id`), rồi gán dữ
 *        liệu MỚI của form lên CHÍNH bản ghi đã khoá qua `BuildsMatterParties::applyMatterPartyData()`
 *        (`$keepIdentityWhenBlank: true` — xem docblock hàm đó cho lý do SỬA không được xoá sạch
 *        định danh chỉ vì hai ô số căn cước/điện thoại luôn bắt đầu trống trên form sửa). CHƯA
 *        `save()`.
 *     d. So định danh MỚI với định danh CŨ: đổi tên, hash CCCD, số điện thoại HOẶC liên kết khách
 *        hàng (`client_id`) đều tính là "đổi định danh" (brief R14, nguyên văn bốn trường). So TÊN
 *        qua `Normalizer::name()` (fix round 1, I1 — không phải chuỗi thô: `RunConflictCheck` tự
 *        chuẩn hoá tên trước khi so khớp, nên một lượt sửa chỉ đổi HOA/THƯỜNG hay khoảng trắng
 *        không hề đổi cái mà lần kiểm tra thật sự thấy, và không đáng để xoá một xác nhận đã có).
 *        So `client_id` ép về SỐ NGUYÊN ở CẢ hai vế (cùng I1): một form Select gửi lên chuỗi
 *        (`"5"`) trong khi cột đã đọc từ CSDL là số nguyên (`5`) không được phép đọc thành "đổi
 *        liên kết khách hàng" chỉ vì lệch kiểu dữ liệu.
 *     e. Chạy `RunConflictCheck::handle()` trên ĐÚNG một bên này, với `excludePartyId` = id của
 *        chính nó (bắt buộc — xem docblock tham số đó ở `RunConflictCheck::handle()`: bỏ sót sẽ
 *        nạp lại một bản CŨ của cùng dòng qua `existingParties()`, sinh xung đột "tự đối lập với
 *        chính mình" giả nếu vai trò vừa đổi) và `ignoreConfirmedForPartyIds` = `[id của nó]` KHI
 *        VÀ CHỈ KHI định danh vừa đổi (bước d) — nếu không đổi, `null` (dùng cơ chế
 *        xác nhận/ghi đè cũ NGUYÊN VẸN, đúng brief "reuses Task 8's gating unchanged").
 *  6. Mức đỏ/vàng: CÙNG luật, CÙNG hình dạng `if/elseif` như `AddMatterParty` bước 3 — không lặp
 *     lại lý lẽ ở đây.
 *  7. **Giai đoạn lưu (transaction riêng, chỉ chạy nếu không bị chặn/đã xác nhận đúng mức):**
 *     a. Khoá dòng `matters` LÀM CÂU ĐẦU TIÊN (bước 2).
 *     b. Nếu bên là `is_our_client` kèm `client_id`: khoá lại + đọc lại hồ sơ `Client` và áp định
 *        danh MỚI NHẤT của nó (`reapplyFreshClientIdentity()`, dùng chung với `AddMatterParty` bước
 *        4 — cùng lỗ hổng đua tranh hash cũ, cùng cách sửa, không lặp lại lý lẽ). Định danh đổi thì
 *        xếp `RecheckClientIdentityConflicts` `afterCommit()`.
 *     c. Ghi lại danh sách trường đã đổi (`changedFields()`, R14 — tên trường, KHÔNG BAO GIỜ số CCCD
 *        thô) từ `$party->getDirty()` NGAY TRƯỚC `save()`.
 *     d. `blameOn()` rồi `save()` — cùng lý do `HasBlameable` ở mọi Action khác của nhánh này.
 *     e. Ghi `matter_party_updated`: trường đã đổi, mức xung đột, có ghi đè hay không kèm lý do,
 *        bên thiếu định danh, VÀ `confirmed_pairs` từ `allNewMatches` (cùng khoá sự kiện mà
 *        `RunConflictCheck::confirmedPairLevels()` đọc — xem docblock hàm đó).
 *
 * Trả về `UpdateMatterPartyResult` (bên + kết quả kiểm tra + có ghi đè hay không + lý do), cùng lý
 * do `AddMatterPartyResult` tồn tại — không lặp lại ở đây.
 */
class UpdateMatterParty
{
    use BuildsMatterParties;

    /**
     * @param  array<string, mixed>  $partyData  Cùng hình dạng `AddMatterParty::handle()`: `role`
     *                                           (`PartyRole|string`), `name`, và tuỳ chọn
     *                                           `id_number`, `phone`, `address`, `note`,
     *                                           `is_our_client`, `client_id`. Hai ô `id_number`/
     *                                           `phone` để trống nghĩa là "không đổi" (xem
     *                                           `BuildsMatterParties::applyMatterPartyData()`),
     *                                           KHÁC `AddMatterParty` nơi để trống nghĩa là "không
     *                                           có định danh nào".
     * @param  ConflictLevel|null  $acknowledged  Cùng hợp đồng `AddMatterParty`/`OpenMatter`: phải
     *                                            khớp CHÍNH XÁC `$result->level` của lần kiểm tra
     *                                            NÀY.
     */
    public function handle(
        MatterParty $party,
        User $actor,
        array $partyData,
        ?string $overrideReason = null,
        ?ConflictLevel $acknowledged = null,
    ): UpdateMatterPartyResult {
        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;
        $matterId = $party->matter_id;
        $partyId = $party->getKey();

        try {
            return Cache::store('database')->lock('conflict-check', 30)->block(10, function () use (
                $matterId, $partyId, $actor, $partyData, $overrideReason, $acknowledged,
            ): UpdateMatterPartyResult {
                // Bước 5.
                /** @var array{0: ConflictCheckResult, 1: MatterParty, 2: Matter, 3: bool} $checked */
                $checked = DB::transaction(function () use ($matterId, $partyId, $actor, $partyData): array {
                    // Bước 3/5a: câu ĐẦU TIÊN của transaction là khoá dòng vụ việc, rồi khoá bên (bước 4).
                    $matter = Matter::query()->whereKey($matterId)->lockForUpdate()->firstOrFail();
                    $locked = MatterParty::query()->whereKey($partyId)->lockForUpdate()->first();

                    if ($locked === null) {
                        throw MatterPartyAlreadyRemoved::make();
                    }

                    $locked->setRelation('matter', $matter);

                    // Bước 5b.
                    Gate::forUser($actor)->authorize('update', $locked);

                    // Bước 5c.
                    $before = $this->identitySnapshot($locked);

                    $this->applyMatterPartyData($locked, $partyData, keepIdentityWhenBlank: true);

                    // Bước 5d.
                    $identityChanged = $this->identitySnapshot($locked) !== $before;

                    // Bước 5e.
                    $result = app(RunConflictCheck::class)->handle(
                        collect([$locked]),
                        $matter,
                        $actor,
                        excludePartyId: $locked->getKey(),
                        ignoreConfirmedForPartyIds: $identityChanged ? collect([$locked->getKey()]) : null,
                    );

                    return [$result, $locked, $matter, $identityChanged];
                });

                [$result, $party, , $identityChanged] = $checked;

                $isOverridden = false;

                // Bước 6.
                if ($result->isBlocking()) {
                    $canOverride = ConflictOverride::allowedFor($actor)
                        && $overrideReason !== null && $overrideReason !== '';

                    if (! $canOverride) {
                        throw ConflictBlocked::make($result);
                    }

                    $isOverridden = true;
                } elseif ($result->requiresAcknowledgement() && $acknowledged !== $result->level) {
                    throw ConflictAcknowledgementRequired::make($result);
                }

                // Bước 7.
                return DB::transaction(function () use (
                    $matterId, $party, $result, $isOverridden, $overrideReason, $actor, $identityChanged,
                ): UpdateMatterPartyResult {
                    // Bước 3/7a: câu ĐẦU TIÊN của transaction là khoá dòng vụ việc.
                    $lockedMatter = Matter::query()->whereKey($matterId)->lockForUpdate()->firstOrFail();

                    // Bước 7b — cùng lỗ hổng đua tranh hash cũ đã sửa ở `AddMatterParty` bước 4:
                    // khoá Client của giai đoạn kiểm tra (bước 3) release ngay khi transaction đó
                    // commit; đọc lại NGAY TRƯỚC khi lưu, không tin ảnh chụp đã dựng ở đó.
                    if ($party->is_our_client && $party->client_id !== null) {
                        $freshClient = $this->lockClient($party->client_id);

                        if ($this->reapplyFreshClientIdentity($party, $freshClient)) {
                            RecheckClientIdentityConflicts::dispatch((int) $party->client_id)->afterCommit();
                        }
                    }

                    // Bước 7c: TRƯỚC save(), getDirty() vẫn còn nguyên các thay đổi trong bộ nhớ.
                    $changedFields = $this->changedFields($party);

                    // Bước 7d.
                    $party->blameOn($actor)->save();

                    // Bước 7e.
                    Audit::record('matter_party_updated', $lockedMatter, [
                        'party_id' => $party->id,
                        'changed_fields' => $changedFields,
                        'identity_changed' => $identityChanged,
                        'conflict_level' => $result->level->value,
                        'conflict_overridden' => $isOverridden,
                        'override_reason' => $isOverridden ? $overrideReason : null,
                        'incomplete_conflict_parties' => $result->incompleteParties(),
                        // Cùng khoá sự kiện mà `RunConflictCheck::confirmedPairLevels()` đọc —
                        // xem docblock hàm đó cho lý do `matter_party_updated` phải nằm trong
                        // whereIn của nó.
                        'confirmed_pairs' => $result->allNewMatches
                            ->map(fn ($match) => ['pair_key' => $match->pairKey(), 'level' => $match->level->value])
                            ->filter(fn (array $pair) => $pair['pair_key'] !== null)
                            ->values()
                            ->all(),
                    ], $actor);

                    return new UpdateMatterPartyResult($party, $result, $isOverridden, $isOverridden ? $overrideReason : null);
                });
            });
        } catch (LockTimeoutException) {
            throw ConflictCheckBusy::make();
        }
    }

    /**
     * Đúng những trường mà một lượt sửa có thể làm ĐỔI Ý NGHĨA định danh của bên (brief R14: "tên,
     * CCCD hash, phone, hoặc client link"). `client_id` KHÔNG có mặt trong
     * `BuildsMatterParties::conflictIdentityOf()` (dùng cho đua tranh hash cũ — chỉ ba trường
     * `RunConflictCheck` đối chiếu) nên hàm đó không dùng lại được ở đây: đổi liên kết khách hàng
     * (kể cả khi TÊN/hash tình cờ giữ nguyên — ví dụ gán nhầm sang một khách hàng trùng tên) vẫn
     * phải được coi là "đổi định danh" theo đúng phán quyết Task 9.
     *
     * **`name` qua `Normalizer::name()`, không phải chuỗi thô (fix round 1, I1).** Bản round 0 so
     * `$party->name` trực tiếp — một lượt sửa chỉ đổi HOA/THƯỜNG hay thêm/bớt khoảng trắng thừa
     * ("Lê Thị Hoa" → "  lê   THỊ HOA  ") vẫn bị tính là "đổi định danh", dù `RunConflictCheck` tự
     * chuẩn hoá tên trước khi so khớp (`Normalizer::name()`, `matchesFor()`) nên hai chuỗi đó là
     * MỘT với chính lần kiểm tra sẽ chạy. Hệ quả thật: một lượt sửa vô hại (chỉ chỉnh chính tả
     * cách viết hoa) xoá một xác nhận/ghi đè đã có, buộc người dùng xác nhận lại một xung đột họ đã
     * xem xét, không vì lý do nghiệp vụ nào — đúng "cổng-luôn-bật" mà R13(c) tồn tại để chặn, chỉ
     * chuyển sang một đường kích hoạt khác. So bằng CHÍNH hàm `RunConflictCheck` dùng để so khớp là
     * cách duy nhất hai phép so này không bao giờ lệch nhau.
     *
     * **`client_id` ép về SỐ NGUYÊN ở CẢ hai vế (fix round 1, I1).** `$party->client_id` đọc từ
     * CSDL là số nguyên (hoặc `null`), nhưng sau `applyMatterPartyData()` gán từ `$data['client_id']`
     * — dữ liệu form, thường là CHUỖI (`"5"`) từ một ô Select — giá trị TRONG BỘ NHỚ của `$after`
     * có thể là chuỗi trong khi `$before` (đọc thẳng từ model vừa nạp) là số nguyên. So `'5' !== 5`
     * (kiểu khác nhau, `!==` không ép kiểu) sẽ báo "đổi liên kết khách hàng" dù người dùng không hề
     * đổi khách hàng nào — cùng hạng lỗi false-positive với `name`, chỉ khác cột.
     *
     * @return array{name: ?string, id_number_hash: ?string, phone_normalized: ?string, client_id: ?int}
     */
    private function identitySnapshot(MatterParty $party): array
    {
        return [
            'name' => Normalizer::name($party->name),
            'id_number_hash' => $party->id_number_hash,
            'phone_normalized' => $party->phone_normalized,
            'client_id' => $party->client_id !== null ? (int) $party->client_id : null,
        ];
    }

    /**
     * Tên các trường vừa đổi, cho dòng `matter_party_updated` (R14: "ghi audit nêu trường nào
     * đổi… không bao giờ ghi số CCCD thô"). Đây là TÊN CỘT, không phải giá trị — nêu ra rằng
     * `id_number_hash` vừa đổi không làm lộ số căn cước nào, nên hai cột định danh được đổi tên cho
     * dễ đọc (khớp đúng tên Ô trên form) thay vì để nguyên tên cột kỹ thuật.
     *
     * @return array<int, string>
     */
    private function changedFields(MatterParty $party): array
    {
        $labels = ['id_number_hash' => 'id_number', 'phone_normalized' => 'phone'];

        return collect(array_keys($party->getDirty()))
            ->map(fn (string $field): string => $labels[$field] ?? $field)
            ->unique()
            ->values()
            ->all();
    }
}
