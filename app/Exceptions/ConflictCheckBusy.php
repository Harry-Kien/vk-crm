<?php

namespace App\Exceptions;

use DomainException;

/**
 * `Cache::lock('conflict-check', ...)->block(...)` không lấy được khoá trong thời gian chờ (R13g,
 * M6.5 Task 8, fix round 1 minor ruling). Bản round 0 cố ý KHÔNG bắt `LockTimeoutException` —
 * chủ nhiệm đã đảo phán quyết đó: một khoá không lấy được KHÔNG được phép là một trang lỗi 500,
 * cùng lý do mọi luật nghiệp vụ khác của tầng Action đã có một `DomainException` riêng
 * (`OurClientPartyNeedsClient`) thay vì để ngoại lệ hạ tầng lộ ra ngoài.
 *
 * `OpenMatter`/`AddMatterParty` bắt `LockTimeoutException` quanh lời gọi `block()` và ném lớp
 * này thay — hai màn hình (`CreateMatter`, `PartiesRelationManager`) đã có sẵn một `catch
 * (DomainException $exception)` chung cho MỌI luật nghiệp vụ chưa lường trước ở tầng Action, nên
 * không cần sửa gì ở màn hình: throw đúng loại là đủ để thông điệp tiếng Việt này hiện ra như một
 * lỗi form, không phải một trang lỗi.
 */
class ConflictCheckBusy extends DomainException
{
    public static function make(): self
    {
        return new self(__('exceptions.conflict_check_busy'));
    }
}
