<?php

namespace App\Actions;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\ConflictLevel;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
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
 * **Bất đối xứng có chủ đích, giống hệt bản thân phép kiểm tra:** đồng bộ cả các bên đã xoá mềm
 * (`withTrashed()`). `RunConflictCheck` cố tình đọc cả lịch sử đã xoá mềm, nên một dòng đã xoá
 * mang hash cũ vẫn tham gia so khớp; bỏ nó lại chính là để nguyên lỗ hổng ở đúng những dòng khó
 * nhìn thấy nhất. Một dòng cũ gây cảnh báo vàng thừa rẻ hơn rất nhiều so với một xung đột lợi
 * ích bị bỏ sót.
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
 * **"Đang mở"** đọc là `closed_at IS NULL` (R8) — ở nhánh này chưa có `Matter::scopeOpen()` chung
 * (task khác của M6.5 dựng nó), nên điều kiện viết thẳng bằng `whereNull('closed_at')`; task đó
 * nên thay bằng scope chung khi nó tồn tại, không đổi ý nghĩa.
 *
 * **Vì sao dùng THẲNG `$result->level` của `RunConflictCheck` mà không tự so "trước/sau".** R13c
 * đã dạy `RunConflictCheck` phân biệt khớp MỚI với khớp đã xác nhận/ghi đè trên CHÍNH vụ việc đó
 * (`ConflictCheckResult::$matches` chỉ còn khớp mới, `$confirmedMatches` là phần còn lại) — "vàng
 * hoặc đỏ MỚI" của R13e chính xác là `$result->level` sau khi lọc đó, không cần tự dựng lại một
 * phép so sánh trạng thái trước/sau nào khác. Một cặp bên đã từng bị chặn rồi được một manager ghi
 * đè (hay một vòng vàng đã được xác nhận) sẽ KHÔNG sinh thông báo lặp lại chỉ vì `id_number_hash`
 * của khách hàng vừa được viết lại — nó vẫn là "cặp bên đó", chữ ký `ConflictMatch::$pairKey` dựa
 * trên định danh (không phải thời điểm ghi) nên sống sót qua một lần resync.
 *
 * **Người nhận: `$matter->leadLawyer` + mọi manager + mọi admin đang hoạt động, đưa hết vào
 * `$preferred` của `ResolveStaffRecipients`.** Không tự chọn MỘT manager: SPEC §6.8 (đọc lại theo
 * R3) là "mọi manager được xem vụ đó", và `ResolveStaffRecipients::handle()` đã tự lọc
 * `is_active`/`Gate::view()` — một vụ `restricted` tự loại các manager thường ở đúng bước lọc đó,
 * không cần lớp này biết gì về `confidentiality`.
 */
class SyncClientPartyIdentities
{
    /** @return int Số dòng `matter_parties` đã được đồng bộ lại. */
    public function handle(Client $client): int
    {
        return DB::transaction(function () use ($client): int {
            $parties = MatterParty::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->where('client_id', $client->getKey())
                ->get();

            if ($parties->isEmpty()) {
                // Không có gì để đồng bộ thì cũng không có sự kiện nào để kể lại; ghi nhật ký ở
                // đây chỉ làm loãng nhật ký bằng một dòng cho mỗi lần sửa hồ sơ khách hàng.
                return 0;
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

            $this->recheckAffectedOpenMatters($client, $parties);

            return $parties->count();
        });
    }

    /**
     * R13(e): chạy lại `RunConflictCheck` cho mọi vụ việc ĐANG MỞ có ít nhất một bên (vừa đồng bộ
     * ở trên) trỏ về `$client`. Xem docblock lớp cho toàn bộ lý lẽ.
     *
     * @param  Collection<int, MatterParty>  $resyncedParties
     */
    private function recheckAffectedOpenMatters(Client $client, $resyncedParties): void
    {
        $matterIds = $resyncedParties->pluck('matter_id')->unique()->values();

        // `whereNull('closed_at')` = "đang mở" (R8) — global scope mặc định của Matter đã loại
        // vụ việc xoá mềm, nên không cần lặp lại điều kiện đó ở đây.
        $openMatters = Matter::query()->whereIn('id', $matterIds)->whereNull('closed_at')->get();

        if ($openMatters->isEmpty()) {
            return;
        }

        $openMatters->each(function (Matter $matter) use ($client): void {
            $result = app(RunConflictCheck::class)->handle($matter->parties()->get(), $matter);

            // Chỉ khớp MỚI (R13c đã lọc khớp đã xác nhận/ghi đè ra khỏi $result->level) mới sinh
            // thông báo — "vàng hoặc đỏ MỚI" của R13e, đúng nguyên văn. Cố ý truy vấn
            // manager/admin BÊN TRONG nhánh này, không nạp sẵn trước vòng lặp: phần lớn các lần
            // sửa hồ sơ khách hàng không lộ ra gì mới (đa số vụ việc của một khách hàng không đối
            // lập với ai), nên đây là đường thường gặp nhất — không có lý do gì để mọi lần sửa hồ
            // sơ khách hàng, kể cả một sửa vô hại, đều phải truy vấn toàn bộ manager/admin của văn
            // phòng.
            if ($result->level === ConflictLevel::Green) {
                return;
            }

            $managers = User::query()->where('is_active', true)->role(Role::Manager->value)->get();
            $admins = User::query()->where('is_active', true)->role(Role::Admin->value)->get();

            $preferred = collect([$matter->leadLawyer])->merge($managers)->merge($admins)->all();
            $recipients = app(ResolveStaffRecipients::class)->handle($matter, $preferred);

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
}
