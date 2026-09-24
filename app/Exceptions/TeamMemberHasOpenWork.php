<?php

namespace App\Exceptions;

use App\Models\Matter;
use App\Models\User;
use App\Support\OpenWorkResult;
use DomainException;

/**
 * Gỡ một thành viên khỏi đội ngũ vụ việc trong khi họ còn đứng tên mốc hạn chưa xong, yêu cầu
 * khách chưa đóng, hoặc còn là luật sư phụ trách của chính vụ việc ĐANG MỞ này (R6) — bị từ chối,
 * kèm NGUYÊN VĂN danh sách cần chuyển trước, để người thao tác không phải đi dò từng tab để biết
 * vì sao nút "Gỡ" không làm gì.
 */
class TeamMemberHasOpenWork extends DomainException
{
    public static function make(User $member, Matter $matter, OpenWorkResult $openWork): self
    {
        return new self(__('exceptions.team_member_has_open_work', [
            'name' => $member->name,
            'code' => $matter->code,
            'items' => implode('; ', $openWork->describe()),
        ]));
    }
}
