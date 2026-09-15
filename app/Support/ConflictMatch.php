<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use App\Enums\PartyRole;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Một bản ghi `matter_parties` trùng, tìm thấy khi chạy `RunConflictCheck` (SPEC §6.10).
 *
 * **Ranh giới lộ thông tin có chủ đích** (SPEC §6.10 đoạn cuối, §11 "Xung đột lợi ích"): đây là
 * TOÀN BỘ những gì được phép mang ra khỏi Action — mã hồ sơ, tên loại vụ việc, vai trò và tên của
 * bên trùng (như bên đó xuất hiện trong hồ sơ kia), và mức khớp. Object này KHÔNG được thêm bất
 * kỳ trường nào khác (tiêu đề, mô tả, id vụ việc, id tài liệu, ...) — `RunConflictCheck` cố ý
 * truy vấn `matter_parties` mà không qua `Matter::listableBy`, nên đây là điểm rò rỉ duy nhất
 * cần canh giữ: đủ để nhận ra xung đột, không đủ để lộ bí mật hồ sơ mà người dùng không có quyền
 * xem. `readonly` để không ai vô tình gắn thêm thuộc tính sau khi tạo.
 */
final readonly class ConflictMatch implements Arrayable
{
    public function __construct(
        public string $matterCode,
        public string $matterTypeName,
        public PartyRole $partyRole,
        public string $partyName,
        public ConflictLevel $level,
    ) {}

    public function toArray(): array
    {
        return [
            'matter_code' => $this->matterCode,
            'matter_type_name' => $this->matterTypeName,
            'party_role' => $this->partyRole->value,
            'party_name' => $this->partyName,
            'level' => $this->level->value,
        ];
    }
}
