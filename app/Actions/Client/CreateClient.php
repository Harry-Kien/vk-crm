<?php

namespace App\Actions\Client;

use App\Enums\Permission;
use App\Exceptions\DuplicateClientDetected;
use App\Models\Client;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Normalizer;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;

/**
 * Tạo một hồ sơ `Client` mới — điểm vào DUY NHẤT cho cả hai đường tạo khách hàng của M6.5 Task 6
 * (finding `intake/intake-03`/`roles-04`/`intake-07`, phán quyết R4):
 *
 *  - Màn hình "Khách hàng" → "Tạo mới" (`Clients\Pages\CreateClient`), chỉ ai có `client.manage`
 *    mở được trang (`ClientPolicy::create` — KHÔNG đổi, luật sư vẫn không dùng được đường này).
 *  - Khối "Khách hàng" của form "Mở vụ việc mới" (`Matters\Pages\CreateMatter`), khi actor KHÔNG
 *    có `client.manage`: luật sư tạo khách ngay trong form, được `matter.create` cho phép — đây
 *    là lối DUY NHẤT R4 mở cho một luật sư có một khách hoàn toàn mới.
 *
 * **Cổng quyền không phải `Gate::authorize('create', Client::class)`.** `ClientPolicy::create()`
 * chỉ đúng `client.manage` — cố ý giữ nguyên (chủ nhiệm: "lawyers still cannot use the Clients
 * screen to create clients"). Nhưng Action này PHẢI cho phép actor thứ hai (`matter.create`,
 * không `client.manage`) — một luật riêng của chính lớp này, không phải luật của `ClientPolicy`,
 * nên viết tường minh ở đây thay vì gọi `Gate::authorize()` (sẽ chặn nhầm actor thứ hai).
 *
 * **Dò trùng SO VỚI CÁC BÊN `is_our_client` ĐÃ LƯU, không so toàn bảng `clients` (R4/`intake-07`,
 * phán quyết của chủ nhiệm).** `clients.phone` không chuẩn hoá khi lưu và `clients.id_number` mã
 * hoá không tất định — không có gì để `WHERE` trực tiếp trên bảng đó (xem cũng docblock
 * `FindClientByIdentifier`, cùng lý do). `matter_parties.id_number_hash`/`phone_normalized` là
 * ảnh chụp CÓ THỂ truy vấn, và mọi bên `is_our_client` đều được đồng bộ theo đúng hồ sơ `Client`
 * của nó (`Client::booted()` → `SyncClientPartyIdentities`, `OpenMatter::buildOwnClientParty()`).
 * Phạm vi dò trùng này HẸP HƠN một dò trùng toàn bảng `clients` thật (một khách hàng CHƯA từng có
 * vụ việc không để lại dấu vết ở đây) — có chủ đích: dò trùng liên hệ đầy đủ trên toàn bảng
 * `clients` đã được lên kế hoạch riêng, sau M6.5 (M10, `intake-07` bản rà soát cuối).
 *
 * **Hai nhánh khi tìm thấy trùng, theo quyền của actor (R4):**
 *  - Không `client.manage` (luật sư): DÙNG hồ sơ đã có, im lặng — không hỏi xác nhận, vì actor đó
 *    không có quyền tạo một hồ sơ thứ hai dù có muốn (R4 b: "nếu (b) trùng định danh chính xác với
 *    một hồ sơ đã có thì dùng hồ sơ đó, không tạo bản thứ hai").
 *  - Có `client.manage` (trợ lý/trưởng phòng/admin ở màn hình "Khách hàng"): ném
 *    `DuplicateClientDetected` (mang hồ sơ trùng) TRỪ KHI `$confirmDuplicate` — người này được
 *    xem cảnh báo kèm liên kết tới hồ sơ trùng và vẫn tạo được một hồ sơ MỚI nếu xác nhận.
 */
class CreateClient
{
    /**
     * @param  array<string, mixed>  $attributes  Thuộc tính `Client` (SPEC §4.2): `type`, `name`,
     *                                            `id_number` (nullable), `phone`, `email`,
     *                                            `representative_name`, `address`, `note`.
     * @param  bool  $confirmDuplicate  Chỉ có nghĩa cho actor có `client.manage`: đã xem cảnh báo
     *                                  trùng và vẫn quyết định tạo một hồ sơ mới.
     */
    public function handle(User $actor, array $attributes, bool $confirmDuplicate = false): Client
    {
        $canManage = $actor->can(Permission::ClientManage->value);

        abort_unless($canManage || $actor->can(Permission::MatterCreate->value), 403);

        $existing = $this->findExistingClient(
            Normalizer::phone($attributes['phone'] ?? null),
            Normalizer::idNumberHash($attributes['id_number'] ?? null),
        );

        if ($existing !== null) {
            if (! $canManage) {
                return $existing;
            }

            if (! $confirmDuplicate) {
                throw DuplicateClientDetected::make($existing);
            }

            // Đã xác nhận: tạo một hồ sơ MỚI dù trùng định danh — rơi xuống dưới, không return.
        }

        return DB::transaction(function () use ($actor, $attributes): Client {
            $client = new Client([
                'type' => $attributes['type'] ?? null,
                'name' => $attributes['name'] ?? null,
                'id_number' => $attributes['id_number'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'email' => $attributes['email'] ?? null,
                'representative_name' => $attributes['representative_name'] ?? null,
                'address' => $attributes['address'] ?? null,
                'note' => $attributes['note'] ?? null,
            ]);
            $client->blameOn($actor)->save();

            return $client;
        });
    }

    private function findExistingClient(?string $phoneNormalized, ?string $idNumberHash): ?Client
    {
        if ($phoneNormalized === null && $idNumberHash === null) {
            return null;
        }

        $party = MatterParty::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('is_our_client', true)
            ->whereNotNull('client_id')
            ->matchingIdentity($idNumberHash, $phoneNormalized)
            ->first();

        if ($party === null) {
            return null;
        }

        return $party->client()->withoutGlobalScope(ClientPortalScope::class)->first();
    }
}
