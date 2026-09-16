<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\MatterParty;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;

/**
 * Đồng bộ lại ảnh chụp định danh (`id_number_hash`, `phone_normalized`) của MỌI bên trỏ về một
 * khách hàng, sau khi hồ sơ khách hàng đó đổi số căn cước hoặc số điện thoại.
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

            $parties->each(
                fn (MatterParty $party) => $party->identify($client->id_number, $client->phone)->save()
            );

            Audit::record('client_identity_resynced', $client, [
                'parties_resynced' => $parties->count(),
            ]);

            return $parties->count();
        });
    }
}
