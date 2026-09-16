<?php

namespace App\Actions\Concerns;

use App\Enums\PartyRole;
use App\Models\Client;
use App\Models\MatterParty;

/**
 * Dựng một dòng `matter_parties` CHƯA LƯU từ dữ liệu form — đường dựng bên DUY NHẤT của cả
 * `OpenMatter` (lúc mở vụ việc) lẫn `AddMatterParty` (lúc thêm bên vào vụ việc đang chạy), hai
 * thời điểm bắt buộc chạy kiểm tra xung đột lợi ích của SPEC §6.10.
 *
 * **Vì sao gộp một chỗ thay vì hai bản sao gần giống nhau.** Hai Action từng có hai bản `buildParty()`
 * riêng và đã LỆCH NHAU hai lần: fix round 2 (finding C) phải sửa `AddMatterParty` để nó lấy định
 * danh từ hồ sơ `Client` như `OpenMatter` đã làm, commit 3859c7a phải sửa tiếp phần TÊN, rồi bản
 * xem xét sau đó phát hiện `OpenMatter` chỉ áp quy tắc ấy cho khách hàng CHÍNH của vụ việc còn bên
 * trong danh sách bên thì vẫn tin form. Ba lần cùng một lỗi ở cùng một chỗ là dấu hiệu quy tắc
 * phải có một nơi ở, không phải một quy ước cần nhớ.
 *
 * **Quy tắc: `is_our_client = true` VỚI `client_id` thì TÊN và ĐỊNH DANH dựng lại từ hồ sơ `Client`
 * thật, không lấy từ form.** `clients.id_number` dùng cast `encrypted` và không có cột hash, nên
 * `matter_parties.id_number_hash` là cây cầu ĐÁNG TIN CẬY DUY NHẤT để `RunConflictCheck` nhìn thấy
 * định danh của một khách hàng. Một bên "là khách hàng của văn phòng" mang định danh gõ từ form
 * (gõ sai, gõ khác hồ sơ gốc, hoặc cố tình) sẽ băm ra một hash KHÔNG khớp hồ sơ thật — và một hash
 * sai chính là cách một lần kiểm tra xung đột trong tương lai bỏ sót bên này, im lặng, không ai
 * biết. Tên cũng vậy: tên là tầng so khớp thứ ba của SPEC §6.10 bước 2, nên một cái tên gõ khác hồ
 * sơ gốc làm lệch đúng cột mà lần kiểm tra sau sẽ tra.
 *
 * **`address`/`note` thì KHÔNG lấy từ hồ sơ khách hàng, có chủ đích.** Hai cột đó không phải tầng
 * so khớp nào cả, và địa chỉ của một bên trong một vụ việc cụ thể (địa chỉ nhận tống đạt, trụ sở
 * chi nhánh) có thể khác hồ sơ gốc một cách hoàn toàn hợp lệ. Chỉ những gì phép kiểm tra ĐỌC mới bị
 * ép về hồ sơ gốc.
 *
 * **`client_id` mồ côi bị bỏ.** Khi `is_our_client = false`, `client_id` do form gửi lên bị đặt
 * null: một bên đối lập không phải khách hàng của văn phòng thì không được mang liên kết tới hồ sơ
 * khách hàng nào — nếu không, `SyncClientPartyIdentities` sẽ ghi tên và định danh của khách hàng đó
 * đè lên dòng của bên đối lập ở lần khách hàng kia sửa hồ sơ.
 */
trait BuildsMatterParties
{
    /**
     * @param  array<string, mixed>  $data  `role` (`PartyRole|string`), `name`, và tuỳ chọn
     *                                      `id_number`, `phone`, `address`, `note`,
     *                                      `is_our_client`, `client_id`.
     * @param  int|null  $matterId  Vụ việc đã tồn tại; `null` khi vụ việc chưa được lưu
     *                              (`OpenMatter` gán qua `$matter->parties()->save()` sau).
     * @param  Client|null  $lockedClient  Hồ sơ khách hàng mà caller ĐÃ khoá dòng sẵn, để không
     *                                     khoá lại lần hai trong cùng một transaction. Nếu không
     *                                     truyền (hoặc truyền một hồ sơ khác), phương thức tự khoá
     *                                     — quy tắc "lấy từ hồ sơ thật" không được phép phụ thuộc
     *                                     vào việc caller có nhớ khoá trước hay không.
     */
    protected function buildMatterParty(array $data, ?int $matterId = null, ?Client $lockedClient = null): MatterParty
    {
        $role = $data['role'] instanceof PartyRole ? $data['role'] : PartyRole::from($data['role']);
        $isOurClient = (bool) ($data['is_our_client'] ?? false);
        $clientId = $isOurClient ? ($data['client_id'] ?? null) : null;

        $party = new MatterParty([
            'matter_id' => $matterId,
            'role' => $role,
            'is_our_client' => $isOurClient,
            'client_id' => $clientId,
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        if ($clientId === null) {
            return $party->identify($data['id_number'] ?? null, $data['phone'] ?? null);
        }

        $client = ($lockedClient !== null && (int) $lockedClient->getKey() === (int) $clientId)
            ? $lockedClient
            : $this->lockClient($clientId);

        $party->name = $client->name;

        return $party->identify($client->id_number, $client->phone);
    }

    /**
     * Khoá dòng khách hàng trong lúc kiểm tra xung đột đọc nó (SPEC §6.10, brief M3): ngăn một lần
     * sửa `name`/`id_number`/`phone` xen ngang đúng lúc ảnh chụp định danh đang được dựng.
     */
    protected function lockClient(int|string $clientId): Client
    {
        return Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();
    }
}
