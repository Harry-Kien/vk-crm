<?php

namespace App\Actions\Concerns;

use App\Enums\PartyRole;
use App\Exceptions\OurClientPartyNeedsClient;
use App\Models\Client;
use App\Models\MatterParty;
use InvalidArgumentException;

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
 *
 * **Và chiều ngược lại bị TỪ CHỐI, không im lặng cho qua (review fix round 4, Important I-2).**
 * `is_our_client = true` mà KHÔNG có `client_id` từng trả sớm xuống `identify()` với dữ liệu gõ
 * tay — tức đúng sự mù im lặng mà cả trait này tồn tại để ngăn, chỉ khác đường vào: định danh gõ
 * tay băm ra một hash mà hồ sơ `Client` thật không bao giờ băm ra, nên lần kiểm tra xung đột SAU
 * không nhìn thấy bên này; và vì `SyncClientPartyIdentities` lọc theo `client_id`, dòng đó nằm
 * ngoài vòng đồng bộ vĩnh viễn. Giờ ném `OurClientPartyNeedsClient`.
 *
 * **Vì sao luật ở ĐÂY chứ không chỉ ở hai form.** Đây không phải một ranh giới hiển thị của panel
 * (so với `client_id` giả mạo, vốn thuộc về màn hình — xem docblock `CreateMatter`), mà là một câu
 * hỏi về Ý NGHĨA của chính dòng dữ liệu: "bên này là khách hàng của văn phòng" là một tuyên bố mà
 * `RunConflictCheck` dựa vào để xếp vai đối lập, và nó chỉ có nghĩa khi chỉ ra được hồ sơ nào. Câu
 * đó đúng y như vậy khi lời gọi đến từ một seeder, một job hay một lệnh console, nơi không có form
 * nào để `required()`. Hai form vẫn thêm `required()` — nhưng để GIẢI THÍCH luật tại chỗ bằng một
 * lỗi gắn đúng ô, không phải để thi hành nó.
 */
trait BuildsMatterParties
{
    /**
     * @param  array<string, mixed>  $data  `role` (`PartyRole|string`), `name`, và tuỳ chọn
     *                                      `id_number`, `phone`, `address`, `note`,
     *                                      `is_our_client`, `client_id`; và (M10 Task 4)
     *                                      `id_number_hash` thay cho `id_number` khi chỉ có dấu
     *                                      băm — xem `applyMatterPartyData()`.
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
        return $this->applyMatterPartyData(new MatterParty(['matter_id' => $matterId]), $data, $lockedClient);
    }

    /**
     * Gán vai trò, "là khách hàng của văn phòng" + `client_id`, và định danh (tên + hash CCCD/điện
     * thoại) lên MỘT bên — tách ra từ `buildMatterParty()` (M6.5 Task 9) để dùng CHUNG cho cả việc
     * DỰNG một bên mới (`buildMatterParty()`, gọi trên một `MatterParty` rỗng) LẪN việc SỬA một bên
     * đã tồn tại (`UpdateMatterParty`, gọi thẳng trên bản ghi đã khoá): luật "định danh của bên
     * `is_our_client` luôn lấy từ hồ sơ `Client` thật, không lấy từ form" phải áp CẢ hai đường như
     * nhau — trước Task 9 luật này chưa từng cần áp cho một bản ghi ĐÃ tồn tại, vì không Action nào
     * sửa được một bên (`conflict-05`).
     *
     * **`$keepIdentityWhenBlank` (Task 9) — vì sao SỬA không dùng lại `identify()` như lúc TẠO.**
     * Số căn cước/điện thoại GỐC không bao giờ được lưu (SPEC §10.5); chỉ hash/số đã chuẩn hoá còn
     * lại. Form "sửa một bên" vì vậy không có gì để điền sẵn vào hai ô đó — chúng LUÔN bắt đầu
     * trống, kể cả khi bên này đã có định danh từ trước. Nếu `false` (mặc định — đường TẠO), hai ô
     * trống nghĩa là "không có định danh nào" và `identify(null, null)` xoá sạch cả hai, đúng ý
     * nghĩa lúc tạo mới. Nếu `true` (đường SỬA, `UpdateMatterParty` luôn truyền `true`), hai ô
     * trống nghĩa là "không đổi" và `MatterParty::identifyKeepingWhenBlank()` giữ nguyên giá trị
     * cũ — không làm vậy, một lượt sửa chỉ đổi `address` (không đụng gì tới định danh) sẽ vô tình
     * xoá sạch định danh đã có, đúng hạng lỗi `conflict-05` mô tả nhưng đảo chiều. Cờ này chỉ có ý
     * nghĩa ở nhánh KHÔNG `is_our_client`: bên `is_our_client` không bao giờ đọc `id_number`/`phone`
     * của form, luôn lấy thẳng từ hồ sơ `Client` (xem bên dưới) bất kể `$keepIdentityWhenBlank`.
     */
    protected function applyMatterPartyData(
        MatterParty $party,
        array $data,
        ?Client $lockedClient = null,
        bool $keepIdentityWhenBlank = false,
    ): MatterParty {
        $role = $data['role'] instanceof PartyRole ? $data['role'] : PartyRole::from($data['role']);
        $isOurClient = (bool) ($data['is_our_client'] ?? false);
        $clientId = $isOurClient ? ($data['client_id'] ?? null) : null;

        // Trước khi gán bất cứ thứ gì: một bên tự nhận là khách hàng của văn phòng mà không chỉ
        // ra hồ sơ nào thì không có dòng hợp lệ nào để dựng/sửa cả (I-2, xem docblock trait).
        //
        // `blank()`, không `=== null` (Minor, review gộp nhánh M3): một `client_id` là chuỗi rỗng
        // hay chuỗi toàn khoảng trắng — đúng thứ một mảng dựng tay trong seeder, job hay lệnh
        // console dễ mang theo nhất — từng lọt qua cổng này rồi chết ở `lockClient('')` bằng
        // `ModelNotFoundException`, một câu không nói gì về luật vừa bị vi phạm. Trait này TỰ NHẬN
        // là nơi thi hành luật cho những đường không có form (xem docblock), nên nó phải từ chối
        // bằng chính câu của luật đó. Ghi nhận trung thực: `blank(0)` là `false`, nên một id bằng
        // 0 vẫn đi tiếp tới `firstOrFail()` — ở đó "không có khách hàng nào mang id này" là câu
        // trả lời ĐÚNG, khác hẳn với "bên này chưa chỉ ra hồ sơ nào".
        if ($isOurClient && blank($clientId)) {
            throw OurClientPartyNeedsClient::make($data['name'] ?? null);
        }

        $party->role = $role;
        $party->is_our_client = $isOurClient;
        $party->client_id = $clientId;
        $party->address = $data['address'] ?? null;
        $party->note = $data['note'] ?? null;

        if ($clientId === null) {
            $party->name = $data['name'];

            // M10 Task 4 — định danh ĐÃ BĂM SẴN, có kiểm soát: bên đối lập của một lần tiếp nhận
            // chỉ còn dấu băm CCCD (R7, không số thô), và `ConvertIntakeToMatter` mang nó sang qua
            // khoá `id_number_hash` thay cho `id_number` (`MatterParty::identifyWithKnownHash()` kiểm
            // hình dạng). Có khoá này thì nó là định danh — gửi KÈM một số thô là hai định danh cho
            // một bên, không biết tin cái nào, nên từ chối (lỗi lập trình, không form nào gửi khoá
            // này: các form dựng mảng tường minh, `CreateMatter::partiesPayload()`).
            if (array_key_exists('id_number_hash', $data)) {
                if (filled($data['id_number'] ?? null)) {
                    throw new InvalidArgumentException('Một bên chỉ nhận id_number HOẶC id_number_hash, không cả hai.');
                }

                return $party->identifyWithKnownHash($data['id_number_hash'], $data['phone'] ?? null);
            }

            return $keepIdentityWhenBlank
                ? $party->identifyKeepingWhenBlank($data['id_number'] ?? null, $data['phone'] ?? null)
                : $party->identify($data['id_number'] ?? null, $data['phone'] ?? null);
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

    /**
     * Gán lại tên và định danh của một bên CHƯA LƯU từ hồ sơ `Client` vừa khoá và đọc lại, ngay
     * trước khi lưu (fix round 3, đua tranh hash cũ — xem
     * `OpenMatter::refreshOwnClientIdentitiesUnderLock()`), và cho biết ảnh chụp đó có KHÁC ảnh
     * chụp mà lần kiểm tra xung đột vừa dùng hay không.
     *
     * **Vì sao trả về cờ "đã đổi" (fix round 4, NB-1).** Lưu đúng hash chưa đủ: kết quả kiểm tra
     * (và xác nhận của người dùng) được tính trên ảnh chụp CŨ, nên định danh MỚI chưa từng được đối
     * chiếu với các vụ việc đang mở khác. Khi cờ này là `true`, caller xếp
     * `RecheckClientIdentityConflicts` sau khi commit. So CẢ BA trường mà `RunConflictCheck` đọc —
     * `name` (tầng tên), `id_number_hash` (tầng chắc chắn), `phone_normalized` (tầng điện thoại) —
     * vì đổi bất kỳ trường nào cũng có thể làm lộ một khớp mới. Một chỗ so duy nhất cho cả
     * `OpenMatter` lẫn `AddMatterParty`, cùng lý do trait này tồn tại (docblock trait).
     */
    protected function reapplyFreshClientIdentity(MatterParty $party, Client $freshClient): bool
    {
        $checked = $this->conflictIdentityOf($party);

        $party->name = $freshClient->name;
        $party->identify($freshClient->id_number, $freshClient->phone);

        return $this->conflictIdentityOf($party) !== $checked;
    }

    /**
     * Đúng những trường của một bên mà `RunConflictCheck` đối chiếu.
     *
     * @return array{name: ?string, id_number_hash: ?string, phone_normalized: ?string}
     */
    private function conflictIdentityOf(MatterParty $party): array
    {
        return [
            'name' => $party->name,
            'id_number_hash' => $party->id_number_hash,
            'phone_normalized' => $party->phone_normalized,
        ];
    }
}
