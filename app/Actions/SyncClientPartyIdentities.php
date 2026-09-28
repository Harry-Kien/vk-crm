<?php

namespace App\Actions;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\ConflictLevel;
use App\Jobs\RecheckClientIdentityConflicts;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Support\Audit;
use App\Support\ConcurrentChange;
use App\Support\Scopes\ClientPortalScope;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Đồng bộ lại ảnh chụp định danh (`name`/`name_normalized`, `id_number_hash`, `phone_normalized`)
 * của MỌI bên trỏ về một khách hàng, sau khi hồ sơ khách hàng đó đổi tên, số căn cước hoặc số
 * điện thoại.
 *
 * **Vì sao việc này bắt buộc phải tồn tại.** `clients.id_number` dùng cast `encrypted`
 * (SPEC §10.5), nên `RunConflictCheck` KHÔNG thể truy vấn nó — `matter_parties.id_number_hash`
 * là cây cầu duy nhất để tầng "chắc chắn" của SPEC §6.10 bước 2 nhìn thấy định danh. Cây cầu đó
 * là một ảnh chụp lấy lúc bên được tạo (`MatterParty::identify()`) và trước đây không có gì đồng
 * bộ lại. Hệ quả: sửa một số căn cước nhập sai lúc tiếp nhận sẽ làm mù VĨNH VIỄN tầng mạnh nhất
 * đối với đúng khách hàng đó — một vụ việc mới ghi tên họ ở vai đối lập, nhập số ĐÚNG, sẽ băm ra
 * một hash không khớp hash cũ (sai) nào cả và trả về XANH, không một dòng cảnh báo nào.
 *
 * **Đồng bộ cả các bên đã xoá mềm (`withTrashed()`) — nhưng KHÔNG còn vì lý do "vẫn tham gia so
 * khớp" (đính chính fix round 2, I1/R14).** Đoạn này TỪNG nói `RunConflictCheck` cố tình đọc cả
 * lịch sử đã xoá mềm nên một dòng đã xoá vẫn cần hash đúng — SAI kể từ phán quyết R14 đầy đủ: một
 * bên đã GỠ (xoá mềm) không còn là dữ liệu đối chiếu xung đột ở bất kỳ đâu (`RunConflictCheck::
 * matchesFor()` không còn `withTrashed()` ở `MatterParty`). Vẫn đồng bộ các dòng này CHỈ vì vệ
 * sinh dữ liệu — nếu một bên có ngày được khôi phục (Task 9 chưa cài huỷ khôi phục), ảnh chụp định
 * danh của nó phải đúng ngay lúc khôi phục, không lệch từ trước đó. Không còn hậu quả nghiệp vụ gì
 * nếu bỏ bước này (khác hẳn hai bước đồng bộ kia), nhưng giữ lại vì rẻ và đúng.
 *
 * Cũng bỏ `ClientPortalScope` vì `MatterParty::applyClientPortalConstraints()` chặn SẠCH bảng
 * này khi guard `client` có phiên: nếu không bỏ, một lần đồng bộ chạy trong hoàn cảnh đó sẽ tìm
 * thấy 0 dòng và âm thầm không làm gì — cùng lý do đã buộc `RunConflictCheck` bỏ scope này ở cả
 * hai truy vấn của nó.
 *
 * **Chỉ động vào các bên có `client_id` trỏ đúng khách hàng này.** Bên nhập tay từ form
 * (`client_id` null) có định danh riêng, không lấy từ hồ sơ khách hàng nào — ghi đè lên đó là
 * làm hỏng dữ liệu thật, không phải sửa ảnh chụp lệch.
 *
 * SPEC §10.5: không bao giờ ghi số căn cước ra nhật ký ở bất kỳ dạng nào — kể cả hash. Dòng
 * nhật ký chỉ nói rằng đã đồng bộ và chạm bao nhiêu dòng.
 *
 * ---
 *
 * # R13(e)/`conflict-04` (M6.5 Task 8) — "thời điểm thứ ba" mà PROGRESS từng để ngỏ
 *
 * `docs/PROGRESS.md` mục "Việc hoãn lại, có chủ đích" ghi lại đúng lỗ hổng này khi nó còn chưa có
 * quyết định: sửa lại `id_number_hash` của các bên có thể TẠO RA một xung đột mức đỏ (khách hàng A
 * gõ sai CCCD lúc tiếp nhận; sau đó văn phòng mở vụ M2 kiện đúng người đó, mang CCCD đúng — lúc đó
 * ra xanh vì hash không khớp; khi A được sửa CCCD cho đúng, M2 giờ chính là đang kiện khách hàng
 * của mình) mà không có lần kiểm tra nào chạy sau đó, và không ai được báo. SPEC §6.10 chỉ bắt
 * buộc kiểm tra ở hai thời điểm (mở vụ, thêm bên), nên đây không phải một vi phạm SPEC — nhưng là
 * một khoảng trống nghiệp vụ thật, và chủ văn phòng đã quyết (R13e): **sửa định danh của khách thì
 * chạy lại kiểm tra cho MỌI vụ việc ĐANG MỞ có một bên trỏ về khách đó; kết quả vàng hoặc đỏ MỚI
 * (không phải đã được xác nhận/ghi đè ở một lần chạy trước — R13c lọc đúng việc đó) sinh một thông
 * báo trong hệ thống cho người được xem vụ (R3, qua `ResolveStaffRecipients`) và một dòng audit.**
 *
 * **Fix round 1, C2 (Critical) — "một bên trỏ về khách đó" nghĩa là gì.** Bản đầu đọc câu đó là
 * "một bên có `client_id` = khách hàng này" — tức chỉ rà lại những vụ việc mà CHÍNH khách hàng A
 * đứng tên. Đó là SAI, và đúng kịch bản gốc của `conflict-04` (probe T7) chứng minh: vụ việc CẦN
 * được báo không phải M1 (nơi A là khách hàng) mà là M2 — nơi "Ông D" được NHẬP TAY (không
 * `client_id`, không phải khách hàng của văn phòng) và CHỈ trùng A sau khi CCCD của A được sửa
 * đúng. M2 không có bên nào mang `client_id` = A, nên bản đầu KHÔNG BAO GIỜ rà lại M2 — và nếu vụ
 * việc CỦA A (M1) tình cờ đã đóng, `recheckAffectedOpenMatters()` bản đầu không còn gì để rà, im
 * lặng tuyệt đối, đúng như test cũ (đã bị THAY, không còn khoá hành vi đó) từng ghim lại. Phán
 * quyết C2: rà theo đúng những gì `RunConflictCheck::matchesFor()` THẬT SỰ tìm — mọi vụ việc có
 * MỘT BÊN BẤT KỲ (không cần `client_id`) mang `id_number_hash`/`phone_normalized` khớp với hash/
 * số điện thoại MỚI của các bên vừa đồng bộ, xem `matterIdsMatchedByNewIdentity()`. Việc này TỰ
 * ĐỘNG gồm cả M1 (chính bên vừa đồng bộ khớp với chính nó) lẫn M2 — không cần hai đường dò riêng.
 * Chỉ hash/điện thoại, KHÔNG gồm tên (đúng phạm vi phán quyết C2 — tầng tên là "cần xem xét", tin
 * cậy thấp nhất, và một lần sửa hồ sơ khách hàng không đổi TÊN đã đồng bộ theo cùng cách hash/điện
 * thoại đổi; quét theo tên sẽ kéo vào những vụ việc không hề liên quan tới lần sửa này).
 *
 * **"Đang mở"** đọc là `Matter::scopeOpen()` (R8, M6.5 Task 5) — không còn viết thẳng
 * `whereNull('closed_at')` như bản round trước Task 5 (M6.5 Task 9, đúng phán quyết đã hẹn từ
 * trước) — cùng hai điều kiện `closed_at`/`deleted_at`, không đổi ý nghĩa. Vụ việc CỦA khách hàng đang sửa
 * (M1) có thể đã đóng mà KHÔNG làm mất M2 — hai truy vấn tách rời (dò theo định danh, rồi lọc mở)
 * nên một vụ việc đóng chỉ tự loại chính nó, không loại những vụ việc khác cũng khớp.
 *
 * **Vì sao dùng THẲNG `$result->level` của `RunConflictCheck` mà không tự so "trước/sau".** R13c
 * đã dạy `RunConflictCheck` phân biệt khớp MỚI với khớp đã xác nhận/ghi đè trên CHÍNH vụ việc đó
 * (`ConflictCheckResult::$matches` chỉ còn khớp mới, `$confirmedMatches` là phần còn lại) — "vàng
 * hoặc đỏ MỚI" của R13e chính xác là `$result->level` sau khi lọc đó, không cần tự dựng lại một
 * phép so sánh trạng thái trước/sau nào khác. Một cặp bên đã từng bị chặn rồi được một manager ghi
 * đè (hay một vòng vàng đã được xác nhận) sẽ KHÔNG sinh thông báo lặp lại chỉ vì `id_number_hash`
 * của khách hàng vừa được viết lại — `pairKey()` (fix round 1, C1) theo ID DÒNG, không theo định
 * danh, nên nó sống sót qua một lần resync (hai dòng vẫn là hai dòng, dù hash của một trong hai
 * vừa đổi).
 *
 * **Người nhận: `$matter->leadLawyer` + {@see ResolveStaffRecipients::
 * supervisorsFor()}, đưa hết vào `$preferred` của `ResolveStaffRecipients::handle()` (vòng sửa 1,
 * M1 — đính chính bản trước, từng cộng thẳng "mọi manager + mọi admin đang hoạt động" không điều
 * kiện: một vụ THƯỜNG cũng cho admin `Gate::view()` qua — `matter.viewAny` là đủ — nên cộng cả hai
 * vai trò khiến MỌI admin nhận thông báo của MỌI vụ THƯỜNG, không riêng vụ `restricted`).**
 * `supervisorsFor()` là NƠI DUY NHẤT quyết định "quản lý hay admin" cho một vụ việc, dùng chung
 * với `CheckDeadlines`/`SendDeadlineReminderMail::failed()` — không tự quyết lại ở đây. Không tự
 * chọn MỘT manager: SPEC §6.8 (đọc lại theo R3) là "mọi manager được xem vụ đó", và
 * `supervisorsFor()`/`ResolveStaffRecipients::handle()` đã tự lọc `is_active`/`Gate::view()` —
 * một vụ `restricted` tự đổi sang admin ở đúng bước đó.
 *
 * **Fix round 2 (ruling) — lần rà chạy SAU KHI đồng bộ định danh đã commit, dưới CHÍNH khoá
 * `conflict-check` mà `OpenMatter`/`AddMatterParty` dùng.** Bản round 1 gọi
 * `recheckAffectedOpenMatters()` NGAY TRONG `DB::transaction()` của việc ghi lại định danh, không
 * qua khoá nào — hai vấn đề: (1) lần rà đọc dữ liệu `matter_parties`/`clients` SONG SONG với chính
 * giai đoạn kiểm tra+lưu của một `OpenMatter`/`AddMatterParty` khác đang chạy, đúng loại đua tranh
 * mà khoá `conflict-check` (R13g) tồn tại để ngăn — chỉ là ở một Action KHÁC chưa từng được đưa
 * vào cùng khoá đó; (2) một lỗi bất kỳ TRONG lần rà (kể cả không lấy được khoá) ném ra TRONG cùng
 * transaction sẽ CUỐN THEO việc sửa định danh vừa ghi, rollback luôn cả một thao tác lưu hồ sơ
 * khách hàng hợp lệ vì một lý do hoàn toàn không liên quan tới chính hồ sơ đó.
 *
 * **Fix round 3 (N1, Important) — lần rà giờ là một JOB HÀNG ĐỢI, không còn chạy đồng bộ trong
 * request.** Round 2 đã tách lần rà khỏi transaction đồng bộ định danh (đúng), nhưng vẫn chạy nó
 * NGAY trong request (chờ khoá tối đa 10 giây) rồi NUỐT mọi lỗi bằng `report()` — một lần lỡ (khoá
 * bận, hay bất kỳ lỗi nào khác) MẤT VĨNH VIỄN và ÂM THẦM, không dòng audit, không gì re-trigger,
 * và `report()` chỉ ghi `laravel.log` mà trên shared hosting (SPEC §2) không ai đọc. `handle()` giờ
 * chỉ còn lo phần ĐỒNG BỘ (ghi lại ảnh chụp định danh — nhanh, không đụng khoá `conflict-check`),
 * rồi dispatch `RecheckClientIdentityConflicts::dispatch($client->getKey())->afterCommit()` — job
 * đó mới khoá `conflict-check`, để `LockTimeoutException` LỌT RA cho hàng đợi tự thử lại (`$tries`/
 * `backoff()`), và chỉ khi CẢ `$tries` lần đều thất bại mới ghi audit + báo admin qua `failed()` —
 * xem docblock lớp của job đó cho đầy đủ. `afterCommit()` (không phải "gọi tuần tự sau khi closure
 * trả về" như round 2 làm cho chính lần rà) giờ là BẮT BUỘC, không chỉ tiện: một `DB::transaction()`
 * NGOÀI bọc quanh `$client->update()` mà sau đó ROLLBACK phải khiến job KHÔNG BAO GIỜ chạy — job
 * mang `clientId`, và nếu nó chạy trên một client mà lần sửa định danh đã bị huỷ, nó sẽ rà theo
 * định danh CŨ một cách vô nghĩa (hoặc tệ hơn, một client đã rollback về trạng thái trước khi tồn
 * tại). Chỉ cơ chế `afterCommit()` thật của Laravel (gắn vào `DatabaseTransactionsManager`) mới tự
 * huỷ đúng cách khi transaction NGOÀI rollback; gọi tuần tự sau một `DB::transaction()` riêng của
 * CHÍNH Action này (như round 2) không biết gì về một transaction NGOÀI bao quanh cả lời gọi.
 *
 * **Fix round 4 (NB-1, phán quyết (b)) — dispatch lần rà CẢ KHI 0 dòng được đồng bộ.** Bản round 3
 * trả sớm khi khách hàng chưa có dòng `matter_parties` nào, TRƯỚC lời dispatch. Nhưng "chưa có dòng
 * nào" có thể chỉ đúng trong vài mili giây: một `OpenMatter`/`AddMatterParty` khác có thể đang giữ
 * khoá `conflict-check`, đã kiểm tra xung đột trên định danh CŨ, và sắp lưu bên ĐẦU TIÊN của khách
 * hàng này. Lần sửa ở đây commit giữa hai giai đoạn đó thì không thấy gì để đồng bộ, và không ai
 * từng đối chiếu định danh MỚI với các vụ việc đang mở khác. Job chỉ mang `clientId`, khoá CÙNG
 * `conflict-check`, và tự đọc lại mọi thứ khi nó chạy — tức là SAU KHI bên kia đã lưu xong — nên
 * xếp nó cả khi 0 dòng là đủ. Khi khách hàng thật sự không có vụ nào, job chỉ đọc hồ sơ và danh
 * sách bên (rỗng) rồi dừng (`recheckForQueuedClient()`). `OpenMatter`/`AddMatterParty` tự xếp
 * thêm một lần rà từ phía chúng khi lần làm mới dưới khoá thấy định danh đã đổi (phán quyết (a),
 * xem `OpenMatter::refreshOwnClientIdentitiesUnderLock()`) — hai đường độc lập, vì mỗi đường che
 * một lối ghi mà đường kia không thấy.
 */
class SyncClientPartyIdentities
{
    /** @return int Số dòng `matter_parties` đã được đồng bộ lại. */
    public function handle(Client $client): int
    {
        $parties = ConcurrentChange::guard('client', fn (): Collection => DB::transaction(function () use ($client): Collection {
            // Final review wave 2 (phụ lục, mẫu lỗi làn M9): câu ĐẦU TIÊN là một lần đọc CÓ KHOÁ.
            // Một lần đọc thường ở đây đóng băng READ VIEW; nếu một phiên khác (ví dụ
            // `UpdateMatterParty`) đang giữ và sửa một trong các dòng này, câu UPDATE bên dưới đợi
            // khoá rồi ném ERROR 1020 dưới `innodb_snapshot_isolation` của MariaDB 11.8 — một
            // trang lỗi cho người vừa sửa hồ sơ khách. Đo bằng hai phiên thật:
            // `SyncClientPartyIdentitiesLockingTest`.
            $parties = MatterParty::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->where('client_id', $client->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($parties->isEmpty()) {
                return $parties;
            }

            $parties->each(function (MatterParty $party) use ($client): void {
                // Tên cũng là một ảnh chụp, và cũng là tầng so khớp thứ ba của SPEC §6.10 bước 2.
                // Nó chỉ bao giờ cho ra mức vàng nên hậu quả nhẹ hơn hai tầng kia, nhưng lệch vẫn
                // là lệch: một khách hàng đổi tên (doanh nghiệp đổi tên, sửa tên sai chính tả lúc
                // tiếp nhận) sẽ để lại các dòng bên mang tên cũ — vừa không khớp khi kiểm tra
                // xung đột, vừa hiển thị sai ngay trên tab "Các bên". `name_normalized` được
                // MatterParty tính lại ở sự kiện `saving`, không gán tay ở đây.
                $party->name = $client->name;

                $party->identify($client->id_number, $client->phone)->save();
            });

            Audit::record('client_identity_resynced', $client, [
                'parties_resynced' => $parties->count(),
            ]);

            return $parties;
        }));

        // Fix round 3, N1: lần rà giờ là một job hàng đợi, dispatch SAU KHI transaction đồng bộ
        // định danh ở trên THẬT SỰ commit — `afterCommit()`, không phải "gọi tuần tự sau khi
        // closure trả về" (bản round 2 làm vậy cho chính lần rà, nhưng lần rà đó lúc này chưa phải
        // một job nên không cần tới ngữ nghĩa transaction thật). Xem docblock lớp và docblock lớp
        // của `RecheckClientIdentityConflicts` cho lý do đầy đủ.
        //
        // Fix round 4 (NB-1, phán quyết (b)): dispatch CẢ KHI 0 dòng được đồng bộ. Không có dòng
        // nào để ghi lại (nên không có dòng nhật ký đồng bộ nào — closure ở trên đã trả sớm) không
        // có nghĩa là không có gì để rà: xem "Fix round 4" ở docblock lớp.
        RecheckClientIdentityConflicts::dispatch($client->getKey())->afterCommit();

        return $parties->count();
    }

    /**
     * Fix round 3, N1 — điểm vào DUY NHẤT mà `RecheckClientIdentityConflicts::handle()` gọi tới.
     * Nhận ĐÚNG MỘT `clientId` (không phải các bên đã đồng bộ như `recheckAffectedOpenMatters()`
     * nhận) — job chỉ mang id qua hàng đợi (SPEC §10.5), nên ở đây phải tự ĐỌC LẠI mọi thứ từ CSDL
     * ngay lúc job THẬT SỰ chạy, không tin bất kỳ ảnh chụp nào được dựng lúc dispatch. Đọc lại
     * cũng đúng hơn về mặt nghiệp vụ: khách hàng có thể đã đổi định danh THÊM một lần nữa giữa lúc
     * job được xếp hàng và lúc nó chạy (worker bận, backoff), và rà theo giá trị MỚI NHẤT luôn là
     * điều đúng cần làm — không có phiên bản "cũ hơn nhưng vẫn hợp lệ" nào của một câu hỏi xung đột
     * lợi ích (xem docblock lớp `RunConflictCheck`, "đây là kiểm tra LỊCH SỬ").
     *
     * Truy vấn giống hệt truy vấn đồng bộ ở `handle()` (cùng `client_id`, cùng `withTrashed()`,
     * cùng bỏ `ClientPortalScope`, cùng lý do) — nhưng KHÔNG dùng lại kết quả của `handle()`: đây
     * là một lần đọc ĐỘC LẬP, có thể chạy giây/phút sau, trong một tiến trình worker khác hẳn.
     */
    public function recheckForQueuedClient(int $clientId): void
    {
        // Bỏ ClientPortalScope (cùng lý do mọi truy vấn khác của lớp này) — `Client` cũng mang
        // scope này (qua `RestrictedToClientPortal`), một điều tự kiểm tra ban đầu đã bỏ sót: một
        // job chạy trong ngữ cảnh worker bình thường không có phiên nào, nhưng nếu container vẫn
        // còn một `ClientUser` "đang đăng nhập" từ một lần dùng lại tiến trình (hay đúng ngữ cảnh
        // test), dòng `find()` dưới đây sẽ ÂM THẦM trả về null cho một khách hàng có thật, và toàn
        // bộ lần rà bỏ cuộc ngay tại đây — im lặng, không lỗi, không audit.
        $client = Client::withoutGlobalScope(ClientPortalScope::class)->withTrashed()->find($clientId);

        if ($client === null) {
            return;
        }

        $resyncedParties = MatterParty::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->where('client_id', $client->getKey())
            ->get();

        // Khách hàng vẫn chưa có bên nào (fix round 4, phán quyết (b): job giờ được xếp cả khi lần
        // đồng bộ thấy 0 dòng) thì không có định danh nào trong `matter_parties` để đối chiếu.
        if ($resyncedParties->isEmpty()) {
            return;
        }

        $this->recheckAffectedOpenMatters($client, $resyncedParties);
    }

    /**
     * R13(e)/fix round 1 C2: chạy lại `RunConflictCheck` cho mọi vụ việc ĐANG MỞ có MỘT BÊN BẤT
     * KỲ khớp hash/điện thoại MỚI của các bên vừa đồng bộ — xem `matterIdsMatchedByNewIdentity()`
     * và docblock lớp cho lý do KHÔNG còn thu hẹp theo `client_id`.
     *
     * @param  Collection<int, MatterParty>  $resyncedParties
     */
    private function recheckAffectedOpenMatters(Client $client, Collection $resyncedParties): void
    {
        $matterIds = $this->matterIdsMatchedByNewIdentity($resyncedParties);

        if ($matterIds->isEmpty()) {
            return;
        }

        // `->open()` = "đang mở" (R8, `Matter::scopeOpen()` — M6.5 Task 9 thay `whereNull('closed_at')`
        // viết tay bằng scope chung, đúng phán quyết "chỗ DUY NHẤT được viết tường minh là chính
        // TransitionMatterStage"). Bỏ `ClientPortalScope` (fix round 1, minor ruling) cùng lý do đã
        // buộc truy vấn `MatterParty` bên dưới bỏ scope đó: nếu Action lỡ chạy trong lúc guard
        // `client` đang có phiên, scope này chặn SẠCH bảng `matters` và mọi vụ việc vừa dò được
        // biến mất khỏi kết quả một cách im lặng.
        $openMatters = Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereIn('id', $matterIds)
            ->open()
            ->get();

        if ($openMatters->isEmpty()) {
            return;
        }

        $openMatters->each(function (Matter $matter) use ($client): void {
            $result = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

            // Chỉ khớp MỚI (R13c đã lọc khớp đã xác nhận/ghi đè ra khỏi $result->level) mới sinh
            // thông báo — "vàng hoặc đỏ MỚI" của R13e, đúng nguyên văn. Cố ý gọi
            // `supervisorsFor()` BÊN TRONG nhánh này, không nạp sẵn trước vòng lặp: phần lớn các
            // lần sửa hồ sơ khách hàng không lộ ra gì mới (đa số vụ việc của một khách hàng không
            // đối lập với ai), nên đây là đường thường gặp nhất — không có lý do gì để mọi lần sửa
            // hồ sơ khách hàng, kể cả một sửa vô hại, đều phải truy vấn toàn bộ manager/admin của
            // văn phòng.
            if ($result->level === ConflictLevel::Green) {
                return;
            }

            // Vòng sửa 1, M1: KHÔNG còn cộng cả manager LẪN admin không điều kiện — bản trước làm
            // vậy, và một vụ THƯỜNG cũng cho admin Gate::view() qua (matter.viewAny là đủ), nên
            // MỌI admin đang hoạt động nhận thông báo của MỌI vụ THƯỜNG, không riêng vụ
            // restricted. `ResolveStaffRecipients::supervisorsFor()` là NƠI DUY NHẤT quyết định
            // "quản lý hay admin" — dùng lại, không tự quyết theo cách riêng của lớp này (đúng
            // luật R3 mà CheckDeadlines/SendDeadlineReminderMail::failed() cũng dùng).
            $resolver = app(ResolveStaffRecipients::class);
            $preferred = collect([$matter->leadLawyer])->merge($resolver->supervisorsFor($matter))->all();
            $recipients = $resolver->handle($matter, $preferred);

            foreach ($recipients as $recipient) {
                Notification::make()
                    ->title(__('conflicts.resync_notification.title', ['level' => $result->level->label()]))
                    ->body(__('conflicts.resync_notification.body', ['code' => $matter->code]))
                    ->color($result->level === ConflictLevel::Red ? 'danger' : 'warning')
                    ->sendToDatabase($recipient);
            }

            // SPEC §10.5: không ghi số CCCD thô (R14). Dòng này chỉ nói mức, vụ việc nào, khách
            // hàng nào vừa sửa định danh, và ai đã được báo.
            Audit::record('client_identity_conflict_detected', $matter, [
                'level' => $result->level->value,
                'client_id' => $client->getKey(),
                'notified_user_ids' => $recipients->pluck('id')->all(),
            ]);
        });
    }

    /**
     * Fix round 1, C2 (Critical, `conflict-04`): mọi `matter_id` có MỘT BÊN BẤT KỲ (không cần
     * `client_id`, không cần `is_our_client`) mang `id_number_hash`/`phone_normalized` khớp với
     * hash/số điện thoại MỚI của các bên vừa đồng bộ — đúng những gì `RunConflictCheck::
     * matchesFor()` thật sự tìm ở tầng "chắc chắn"/"rất khả nghi" (KHÔNG gồm tầng tên, xem docblock
     * lớp). Bỏ `ClientPortalScope` cùng lý do đã buộc `RunConflictCheck` bỏ scope này ở mọi truy
     * vấn `MatterParty` của nó (docblock lớp). KHÔNG `withTrashed()` (I1/R14): một bên đã GỠ khỏi
     * vụ việc của nó không còn là dữ liệu đối chiếu xung đột ở bất kỳ đâu.
     *
     * @param  Collection<int, MatterParty>  $resyncedParties
     * @return Collection<int, int>
     */
    private function matterIdsMatchedByNewIdentity(Collection $resyncedParties): Collection
    {
        $hashes = $resyncedParties->pluck('id_number_hash')->filter()->unique()->values();
        $phones = $resyncedParties->pluck('phone_normalized')->filter()->unique()->values();

        if ($hashes->isEmpty() && $phones->isEmpty()) {
            return collect();
        }

        return MatterParty::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where(function ($query) use ($hashes, $phones): void {
                $query->when($hashes->isNotEmpty(), fn ($q) => $q->whereIn('id_number_hash', $hashes->all()))
                    ->when($phones->isNotEmpty(), fn ($q) => $q->orWhereIn('phone_normalized', $phones->all()));
            })
            ->pluck('matter_id')
            ->unique()
            ->values();
    }
}
