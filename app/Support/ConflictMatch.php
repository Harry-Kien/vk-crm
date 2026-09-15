<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Một bản ghi `matter_parties` trùng, tìm thấy khi chạy `RunConflictCheck` (SPEC §6.10).
 *
 * **Ranh giới lộ thông tin có chủ đích** (SPEC §6.10 đoạn cuối, §11 "Xung đột lợi ích"): đây là
 * TOÀN BỘ những gì được phép mang ra khỏi Action — mã hồ sơ, tên loại vụ việc, vai trò và tên của
 * bên trùng (như bên đó xuất hiện trong hồ sơ kia), mức khớp, và tiêu chí đã khớp (`tier`). Object
 * này KHÔNG được thêm bất kỳ trường nào khác (tiêu đề, mô tả, id vụ việc, id tài liệu, ...) —
 * `RunConflictCheck` cố ý truy vấn `matter_parties` mà không qua `Matter::listableBy`, nên đây là
 * điểm rò rỉ duy nhất cần canh giữ: đủ để nhận ra xung đột, không đủ để lộ bí mật hồ sơ mà người
 * dùng không có quyền xem. `tier` là ngoại lệ an toàn: nó mô tả CÁCH chúng ta so khớp (số căn
 * cước/điện thoại/tên), không phải nội dung của hồ sơ kia, nên không mở rộng ranh giới lộ thông
 * tin — nhưng cho người xem xét biết một mức vàng là khớp điện thoại mạnh hay chỉ trùng tên tình
 * cờ. `readonly` để không ai vô tình gắn thêm thuộc tính sau khi tạo.
 */
final readonly class ConflictMatch implements Arrayable
{
    public function __construct(
        public string $matterCode,
        public string $matterTypeName,
        public PartyRole $partyRole,
        public string $partyName,
        public ConflictLevel $level,
        public ConflictMatchTier $tier,
    ) {}

    public function toArray(): array
    {
        return [
            'matter_code' => $this->matterCode,
            'matter_type_name' => $this->matterTypeName,
            'party_role' => $this->partyRole->value,
            'party_name' => $this->partyName,
            'level' => $this->level->value,
            'tier' => $this->tier->value,
        ];
    }
}
