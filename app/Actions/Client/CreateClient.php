<?php

namespace App\Actions\Client;

use App\Enums\Permission;
use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\DuplicateClientDetected;
use App\Exceptions\DuplicateClientNotVisible;
use App\Models\Client;
use App\Models\MatterParty;
use App\Models\User;
use App\Support\Audit;
use App\Support\ClientLookupThrottle;
use App\Support\ClientVisibility;
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
 * **Ba nhánh khi tìm thấy trùng, theo quyền của actor VÀ tầm nhìn (R4, fix round 1 C1):**
 *  - Không `client.manage`, hồ sơ trùng THẤY ĐƯỢC (`ClientVisibility::isVisibleTo()`): DÙNG hồ sơ
 *    đã có, im lặng — không hỏi xác nhận, vì actor đó không có quyền tạo một hồ sơ thứ hai dù có
 *    muốn (R4 b: "nếu (b) trùng định danh chính xác với một hồ sơ đã có thì dùng hồ sơ đó, không
 *    tạo bản thứ hai").
 *  - Không `client.manage`, hồ sơ trùng KHÔNG thấy được (ví dụ vụ DUY NHẤT của khách hàng đó là
 *    `restricted` và do người khác phụ trách): ném `DuplicateClientNotVisible` — một câu TRUNG
 *    LẬP không nêu tên ai, không tạo hồ sơ thứ hai, không dùng lại hồ sơ tìm thấy (Review Focus
 *    #1: một vụ `restricted` không được rò rỉ danh tính khách hàng qua đường này).
 *  - Có `client.manage` (trợ lý/trưởng phòng/admin ở màn hình "Khách hàng"): ném
 *    `DuplicateClientDetected` (mang hồ sơ trùng) TRỪ KHI `$confirmDuplicate` — người này được
 *    xem cảnh báo kèm liên kết tới hồ sơ trùng và vẫn tạo được một hồ sơ MỚI nếu xác nhận. Tầm
 *    nhìn không áp cho nhánh này: `client.manage` đã thấy TOÀN BỘ khách hàng của văn phòng.
 *
 * **Giới hạn tần suất (fix round 1, I1).** Với actor không có `client.manage`, chính lần dò trùng
 * này CŨNG là một "oracle" — quan sát kết quả (dùng lại hay tạo mới) cho biết một định danh có
 * khớp ai không, giống hệt `FindClientByIdentifier`. Dùng CHUNG một bộ đếm với Action đó
 * (`App\Support\ClientLookupThrottle`, xem docblock lớp), chỉ khi form thực sự gửi một số điện
 * thoại hoặc số CCCD để dò (không tính một lần tạo khách không có định danh nào).
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

        $phoneNormalized = Normalizer::phone($attributes['phone'] ?? null);
        $idNumberHash = Normalizer::idNumberHash($attributes['id_number'] ?? null);

        // Fix round 1, I1: chỉ đếm một lần "dò" khi có gì đó để dò, và chỉ cho actor mà đây thật
        // sự là một oracle (client.manage đã thấy toàn bộ khách hàng, không cần giới hạn).
        if (! $canManage && ($phoneNormalized !== null || $idNumberHash !== null)) {
            $this->guardThrottle($actor, $attributes);
        }

        $existing = $this->findExistingClient($phoneNormalized, $idNumberHash);

        if ($existing !== null) {
            if (! $canManage) {
                // Fix round 1, C1: chỉ dùng lại khi actor THẤY ĐƯỢC hồ sơ trùng — một khách hàng
                // mà vụ DUY NHẤT là `restricted` do người khác phụ trách không được gắn thẳng vào
                // một vụ việc mới, tên không được lộ ra (xem docblock lớp).
                if (ClientVisibility::isVisibleTo($actor, $existing->getKey())) {
                    return $existing;
                }

                throw DuplicateClientNotVisible::make();
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

    /**
     * Fix round 1, I1: cùng bộ đếm với `FindClientByIdentifier` — xem docblock lớp đó và
     * `App\Support\ClientLookupThrottle` cho lý do một bộ đếm dùng chung cho cả hai đường.
     */
    private function guardThrottle(User $actor, array $attributes): void
    {
        if (! ClientLookupThrottle::tooManyAttempts($actor)) {
            ClientLookupThrottle::hit($actor);

            return;
        }

        // SPEC §10.5/R4: hash, không phải số thô — cùng công thức với `FindClientByIdentifier`.
        $digits = preg_replace('/\D+/', '', (string) ($attributes['phone'] ?? $attributes['id_number'] ?? '')) ?? '';

        Audit::record('client_lookup_throttled', null, [
            'identifier_hash' => $digits !== '' ? hash('sha256', $digits) : null,
        ], $actor);

        throw ClientLookupThrottled::make();
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
