<?php

namespace App\Exceptions;

use DomainException;

/**
 * Final review X2 (A-I1): một lượt SỬA định tắt "là khách hàng của văn phòng" hoặc trỏ sang một
 * khách hàng khác trên bên của CHÍNH khách hàng vụ việc (`is_our_client`, `client_id` =
 * `matters.client_id`). `RemoveMatterParty` đã không cho gỡ bên này; không chặn ở đường sửa thì
 * hai thao tác sửa đạt đúng kết quả của một lần gỡ bị cấm — dữ liệu xung đột (R13) và cổng khách
 * hàng nói hai điều khác nhau về ai là khách của vụ.
 *
 * `DomainException`, nên `PartiesRelationManager::editParty()` đã có sẵn lưới bắt và gắn câu vào ô
 * `client_id`.
 */
class OwnClientPartyLocked extends DomainException
{
    public static function make(): self
    {
        return new self(__('matters.update_parties.own_client_locked'));
    }
}
