<?php

namespace App\Exceptions;

use DomainException;

/**
 * Một dòng `matter_parties` tuyên bố `is_our_client = true` nhưng không chỉ ra hồ sơ `Client` nào
 * (SPEC §6.10 bước 1–2; review fix round 4, Important I-2).
 *
 * Tuyên bố "bên này là khách hàng của văn phòng" là thứ `RunConflictCheck` dựa vào để xếp vai đối
 * lập, và `BuildsMatterParties` chỉ giữ được lời hứa "tên và định danh lấy từ hồ sơ thật" khi có
 * một hồ sơ để lấy. Không có `client_id`, định danh rơi về dữ liệu gõ tay: số căn cước băm ra một
 * hash mà hồ sơ `Client` thật không bao giờ băm ra, nên lần kiểm tra xung đột SAU không nhìn thấy
 * bên này — im lặng, đúng hạng lỗi mà cả trait tồn tại để ngăn. Và vì
 * `SyncClientPartyIdentities` lọc theo `client_id`, dòng đó nằm ngoài vòng đồng bộ vĩnh viễn: nó
 * không tự sửa lại vào một ngày nào đó.
 */
class OurClientPartyNeedsClient extends DomainException
{
    public static function make(?string $name): self
    {
        return new self(__('exceptions.our_client_party_needs_client', [
            'name' => filled($name) ? $name : __('exceptions.unnamed_party'),
        ]));
    }
}
