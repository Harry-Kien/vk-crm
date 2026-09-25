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
 *
 * **`ourPartyRole`/`ourPartyName` (M6.5 Task 8, R13d/`conflict-07`).** Vai và tên của bên PHÍA
 * MÌNH — bên đang được `RunConflictCheck::matchesFor()` xét, tức bên gây ra khớp này — không phải
 * của bên tìm thấy. Cùng ranh giới lộ thông tin như sáu trường kia: đây là dữ liệu người dùng vừa
 * tự nhập vào chính form của họ (hoặc một bên đã có sẵn của vụ việc họ đang xem), không phải nội
 * dung của hồ sơ kia, nên không mở rộng gì cả. Thiếu hai trường này là đúng lỗ hổng `conflict-07`:
 * một bảng nhiều bên mà không nói RÕ bên nào của form gây khớp, người dùng phải đoán — và
 * `conflict-01` cho thấy đoán sai thì đổ lỗi nhầm cho bên vô can.
 *
 * **`pairKey` (R13c/`conflict-01`) — KHÔNG có trong `toArray()`, không bao giờ tới trình duyệt.**
 * Chữ ký nội bộ "bên phía mình ↔ bản ghi tìm thấy", dùng để `RunConflictCheck::handle()` nhận ra
 * một khớp đã từng được xác nhận/ghi đè ở một lần chạy TRƯỚC trên CÙNG vụ việc (xem docblock
 * `RunConflictCheck`). `null` cho khớp "cùng vụ việc, hai phía đối lập" (`ConflictMatchTier::
 * SameMatter`) — R13c cố ý KHÔNG áp cho loại khớp đó, xem docblock `RunConflictCheck::
 * sameMatterOppositionMatches()`.
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
        public PartyRole $ourPartyRole,
        public string $ourPartyName,
        public ?string $pairKey = null,
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
            'our_party_role' => $this->ourPartyRole->value,
            'our_party_name' => $this->ourPartyName,
        ];
    }
}
