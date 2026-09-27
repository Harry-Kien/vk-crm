<?php

namespace App\Actions\Client;

use App\Models\Client;
use App\Models\User;
use App\Support\Audit;
use App\Support\Normalizer;

/**
 * Tra ĐÚNG một hồ sơ `Client` theo số điện thoại hoặc số CCCD đã nhập (M6.5 Task 6, R4(a) —
 * findings `intake/intake-03`, `roles/roles-04`).
 *
 * **Vì sao tồn tại.** Luật sư có `matter.create` nhưng không có `client.manage` (SPEC §5), nên
 * `VisibleClientOptions` không bao giờ liệt kê một khách hàng CHƯA có vụ việc nào cho họ — kể cả
 * khi trợ lý vừa tạo đúng hồ sơ đó (`intake-03`). Đây là lối DUY NHẤT một luật sư tìm lại được một
 * hồ sơ khách hàng đã có mà không cần nhìn thấy danh sách khách hàng của văn phòng.
 *
 * **Ranh giới lộ thông tin — "danh sách khách đã rò rỉ hai lần ở M3" (R4).** `handle()` chỉ trả
 * về MỘT `Client` khi khớp TUYỆT ĐỐI, hoặc `null`. Không có gợi ý theo tên, không có số lượng bản
 * ghi gần đúng, không có danh sách. Một số gần đúng (sai một chữ số) trả về `null` giống hệt một
 * số hoàn toàn không tồn tại — không có cách nào phân biệt hai trường hợp đó từ bên ngoài.
 *
 * **Vì sao quét TOÀN BỘ bảng `clients` thay vì một câu `WHERE`.** `clients.phone` được lưu
 * NGUYÊN VĂN (không chuẩn hoá — SPEC §4.2), và `clients.id_number` dùng cast `encrypted` (SPEC
 * §10.5) — mã hoá không tất định, nên không thể `WHERE id_number = ...`. Không có cột hash/chuẩn
 * hoá nào trên `clients` để truy vấn trực tiếp (khác `matter_parties.id_number_hash`/
 * `phone_normalized`, chỉ tồn tại SAU khi một bên được thêm vào một vụ việc — chính xác cái còn
 * thiếu cho một khách hàng vừa tạo, chưa có vụ nào). Vòng lặp so từng dòng qua `Normalizer` là
 * đúng chi phí chấp nhận được cho quy mô một văn phòng luật nhỏ (SPEC §2) — không phải một câu
 * truy vấn có thể tối ưu thêm mà không thêm cột.
 *
 * **Audit `client_lookup` không bao giờ chứa số thô (R4).** Chỉ ghi ai tra, trúng hay trượt, và
 * một hash của định danh đã nhập — không phải chính số đó. Khớp thì ghi thêm id khách hàng khớp
 * được (không phải tên/số của họ) để phục vụ tra cứu nội bộ sau này; không mở rộng ranh giới lộ
 * thông tin, vì id một mình không đọc được là ai.
 */
class FindClientByIdentifier
{
    public function handle(User $actor, ?string $identifier): ?Client
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return null;
        }

        $match = $this->searchClients($identifier);

        // SPEC §10.5/R4: hash, không phải số thô. Chỉ chữ số mang thông tin định danh — bỏ mọi
        // ký tự khác (khoảng trắng, dấu ngoặc, dấu gạch) trước khi băm, để hai cách gõ khác nhau
        // của CÙNG một số cho ra CÙNG một hash trong nhật ký.
        $digits = preg_replace('/\D+/', '', $identifier) ?? '';

        Audit::record('client_lookup', null, [
            'hit' => $match !== null,
            'identifier_hash' => hash('sha256', $digits !== '' ? $digits : $identifier),
            'matched_client_id' => $match?->getKey(),
        ], $actor);

        return $match;
    }

    private function searchClients(string $identifier): ?Client
    {
        $phoneNormalized = Normalizer::phone($identifier);
        $idNumberHash = Normalizer::idNumberHash($identifier);

        if ($phoneNormalized === null && $idNumberHash === null) {
            return null;
        }

        $match = null;

        // Cột tối thiểu: không nạp address/note/representative_name — không cần cho việc so khớp,
        // và giảm lượng dữ liệu nhạy cảm đi qua bộ nhớ cho một thao tác chạy trên MỌI hồ sơ.
        Client::query()
            ->select(['id', 'code', 'name', 'phone', 'id_number'])
            ->orderBy('id')
            ->chunkById(200, function ($clients) use (&$match, $phoneNormalized, $idNumberHash): bool {
                foreach ($clients as $client) {
                    /** @var Client $client */
                    if ($phoneNormalized !== null && Normalizer::phone($client->phone) === $phoneNormalized) {
                        $match = $client;

                        return false;
                    }

                    if ($idNumberHash !== null && Normalizer::idNumberHash($client->id_number) === $idNumberHash) {
                        $match = $client;

                        return false;
                    }
                }

                return true;
            });

        return $match;
    }
}
