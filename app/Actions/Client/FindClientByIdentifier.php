<?php

namespace App\Actions\Client;

use App\Exceptions\ClientLookupThrottled;
use App\Exceptions\DuplicateClientNotVisible;
use App\Models\Client;
use App\Models\User;
use App\Support\Audit;
use App\Support\ClientLookupThrottle;
use App\Support\ClientVisibility;
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
 *
 * **Giới hạn tần suất (fix round 1, I1, `intake` review).** Không giới hạn, ô tra này là một
 * "oracle" cho toàn bộ danh sách khách hàng của văn phòng — dò từng số một, không giới hạn, cuối
 * cùng dựng lại được cả danh sách mà `VisibleClientOptions` cố tình không cho luật sư thấy. Dùng
 * chung một bộ đếm với `App\Actions\Client\CreateClient` (`App\Support\ClientLookupThrottle`, xem
 * docblock lớp đó) — 20 lần/giờ/nhân sự, cả hai đường CỘNG LẠI, không phải hai bộ đếm riêng.
 *
 * **Ranh giới `restricted` (fix round 2, E1, re-review).** Khớp TUYỆT ĐỐI không còn là đủ để trả
 * về một `Client` — round 1 vá đúng nhánh "tạo khách mới" (`CreateClient::findExistingClient()`,
 * qua `ClientVisibility::isVisibleTo()`) nhưng bỏ sót nhánh TRA này: một luật sư B gõ đúng CCCD
 * của khách hàng C mà vụ DUY NHẤT là `restricted` do luật sư A phụ trách vẫn tra ra được tên và
 * mã hồ sơ của C qua đây, dù `isVisibleTo()` đã chặn đúng con đường kia. `searchClients()` tìm
 * thấy một khớp thì phải hỏi thêm `ClientVisibility::isOfferableByLookup()` (luật NHẸ HƠN
 * `isVisibleTo()` — xem docblock hàm đó cho hai trường hợp còn tra được: chưa có vụ nào, hoặc có
 * ít nhất một vụ KHÔNG `restricted`) trước khi trả `Client` đó ra — không thấy được thì ném
 * `DuplicateClientNotVisible`, CÙNG câu trung lập với nhánh "tạo khách mới", để hai đường không
 * lộ ra hai câu khác nhau tiết lộ có tồn tại một sự phân biệt nào đó.
 */
class FindClientByIdentifier
{
    public function handle(User $actor, ?string $identifier): ?Client
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return null;
        }

        if (ClientLookupThrottle::tooManyAttempts($actor)) {
            $digits = preg_replace('/\D+/', '', $identifier) ?? '';

            Audit::record('client_lookup_throttled', null, [
                'identifier_hash' => hash('sha256', $digits !== '' ? $digits : $identifier),
            ], $actor);

            throw ClientLookupThrottled::make();
        }

        ClientLookupThrottle::hit($actor);

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

        // Fix round 2, E1: dòng audit ở trên GHI SỰ THẬT (đã khớp, khớp với id nào) trước khi từ
        // chối — bằng chứng nội bộ không được xoá chỉ vì actor không được PHÉP nhận câu trả lời
        // đó. Ném ngoại lệ SAU khi ghi, không phải thay cho việc ghi.
        if ($match !== null && ! ClientVisibility::isOfferableByLookup($actor, $match->getKey())) {
            throw DuplicateClientNotVisible::make();
        }

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
