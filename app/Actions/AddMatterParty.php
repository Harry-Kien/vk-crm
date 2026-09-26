<?php

namespace App\Actions;

use App\Actions\Concerns\BuildsMatterParties;
use App\Enums\ConflictLevel;
use App\Exceptions\ConflictAcknowledgementRequired;
use App\Exceptions\ConflictBlocked;
use App\Exceptions\ConflictCheckBusy;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\AddMatterPartyResult;
use App\Support\Audit;
use App\Support\ConflictCheckResult;
use App\Support\ConflictOverride;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
 *
 * **Và object đó mang thêm `overridden` + `overrideReason` (fix round 4, Critical C-1).** Bước 4
 * trả về BÌNH THƯỜNG ở hai đường khác hẳn nhau — xanh sạch, và ĐỎ đã được ghi đè — nên một caller
 * chỉ cầm `$result` buộc phải đoán, và `$result->level` không phân biệt được "đỏ bị chặn" với "đỏ
 * đã ghi đè". Hai trường này là chính xác những gì màn hình cần để không phải đoán; giá trị lấy
 * đúng từ những gì vừa ghi vào dòng `matter_party_added`, xem bước 4. Cùng hình dạng
 * `OpenMatterResult`, vì hai màn hình phải nói được cùng một sự thật.
 *
 * R13(g)/conflict-11 (M6.5 Task 8) addendum: both phases below (step 2 check, step 4 save) now
 * run under the SAME application lock as OpenMatter -- Cache::store('database')->lock(
 * 'conflict-check', ...), same lock name on purpose (brief R13g). Without it, two concurrent
 * AddMatterParty calls on opposing matters (or an OpenMatter running alongside an
 * AddMatterParty) share the same narrow window already fixed in OpenMatter: one side's check
 * phase can run before the other side's save phase commits. See OpenMatter's class docblock,
 * "Ve khoa R13(g)", for the full reasoning, including why locking the clients row alone is not
 * enough. **Fix round 1 update:** `LockTimeoutException` is no longer left uncaught -- round 0
 * deliberately let it surface as a 500; the controller reversed that call. It is now converted
 * into `ConflictCheckBusy` (a Vietnamese-worded `DomainException`), caught generically by
 * `PartiesRelationManager::createParty()`'s existing `catch (DomainException $exception)` — no
 * screen change was needed, just throwing the right type.
 */
class AddMatterParty
{
    use BuildsMatterParties;

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

        $overrideReason = $overrideReason !== null ? trim($overrideReason) : null;

        // R13(g)/`conflict-11` (M6.5 Task 8): bước 2 (kiểm tra) tới hết bước 4 (lưu) chạy dưới
        // MỘT khoá ứng dụng, CÙNG tên với `OpenMatter` — xem docblock lớp. Fix round 1: khoá
        // không lấy được (`LockTimeoutException`) giờ thành `ConflictCheckBusy`, không còn một
        // lỗi 500 trần — xem docblock lớp.
        try {
            return Cache::store('database')->lock('conflict-check', 30)->block(10, function () use (
                $matter, $actor, $partyData, $overrideReason, $acknowledged,
            ): AddMatterPartyResult {
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

                $isOverridden = false;

                // Bước 3.
                if ($result->isBlocking()) {
                    // Vai nào ghi đè được thì hỏi `ConflictOverride`, không viết lại tại chỗ (Minor, review
                    // gộp nhánh M3): vòng 4 đã gom nửa HIỂN THỊ của quy tắc này về một lớp nhưng để nguyên
                    // hai bản viết tay ở đây và ở `OpenMatter` — mà đây mới là tầng mà một lần lệch nhau
                    // cho phép SAI NGƯỜI ghi đè một xung đột mức đỏ, chứ không chỉ làm màn hình nói sai.
                    $canOverride = ConflictOverride::allowedFor($actor)
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
                    // `blameOn()` TRƯỚC khi save(), cùng lý do và cùng hình dạng như `OpenMatter` bước
                    // 5 và `TransitionMatterStage`: `MatterParty` dùng `HasBlameable`, vốn điền
                    // `created_by`/`updated_by` từ `auth('web')` ambient. Action này đã nhận `$actor`
                    // tường minh và đem chính actor đó đi kiểm tra quyền ở bước 1, nên hai cột "ai tạo"
                    // phải chỉ về người đó — phiên đang mở có thể là người khác, hoặc không tồn tại (job,
                    // lệnh console). Dòng `matter_parties` là hồ sơ pháp lý, không phải nhật ký phụ trợ.
                    // Ruling (fix round 3, cùng lỗ hổng OpenMatter bước 5) — đua tranh hash cũ:
                    // $party được dựng ở bước 2 (transaction RIÊNG, khoá Client đã release khi
                    // transaction đó commit). Khoá lại VÀ đọc lại hồ sơ Client ngay trước khi lưu,
                    // không tin ảnh chụp đã dựng trước đó — xem docblock
                    // `OpenMatter::refreshOwnClientIdentitiesUnderLock()` cho lý do đầy đủ (cùng
                    // lỗ hổng, không viết lại lý lẽ ở đây).
                    if ($party->is_our_client && $party->client_id !== null) {
                        $freshClient = $this->lockClient($party->client_id);
                        $party->name = $freshClient->name;
                        $party->identify($freshClient->id_number, $freshClient->phone);
                    }

                    $party->blameOn($actor);

                    $matter->parties()->save($party);

                    Audit::record('matter_party_added', $matter, [
                        'party_id' => $party->id,
                        'conflict_level' => $result->level->value,
                        'conflict_overridden' => $isOverridden,
                        'override_reason' => $isOverridden ? $overrideReason : null,
                        'incomplete_conflict_parties' => $result->incompleteParties(),
                        // R13(c)/`conflict-01` (M6.5 Task 8, fix round 1 C1): xem chú thích cùng
                        // khoá ở `OpenMatter::handle()` — chữ ký + MỨC ĐÃ CHẤP NHẬN của các khớp
                        // MỚI vừa được chấp nhận ở bước 3, đọc lại ở lần chạy sau qua
                        // `RunConflictCheck::confirmedPairLevels()`. `allNewMatches`, KHÔNG phải
                        // `matches` (fix round 2, NB1) — xem chú thích cùng khoá ở
                        // `OpenMatter::handle()` và docblock `ConflictCheckResult::$allNewMatches`.
                        'confirmed_pairs' => $result->allNewMatches
                            ->map(fn ($match) => ['pair_key' => $match->pairKey(), 'level' => $match->level->value])
                            ->filter(fn (array $pair) => $pair['pair_key'] !== null)
                            ->values()
                            ->all(),
                    ], $actor);

                    // Cùng giá trị đã ghi vào dòng nhật ký ngay trên — không tính lại, để màn hình không
                    // thể hiện ra một lý do khác với lý do đã lưu vĩnh viễn (fix round 4, C-1).
                    return new AddMatterPartyResult($party, $result, $isOverridden, $isOverridden ? $overrideReason : null);
                });
            });
        } catch (LockTimeoutException) {
            throw ConflictCheckBusy::make();
        }
    }

    /**
     * Dựng bên mới. Toàn bộ quy tắc — kể cả "bên là khách hàng của văn phòng thì tên và định danh
     * lấy từ hồ sơ `Client` thật, không lấy từ form" — nằm trong `BuildsMatterParties`, dùng chung
     * với `OpenMatter`; đọc docblock trait đó cho lý do. Ở đây chỉ còn đúng phần riêng của Action
     * này: vụ việc ĐÃ tồn tại nên bên mới mang sẵn `matter_id`.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildParty(Matter $matter, array $data): MatterParty
    {
        return $this->buildMatterParty($data, $matter->id);
    }
}
