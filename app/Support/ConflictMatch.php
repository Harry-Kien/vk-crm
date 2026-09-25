<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use App\Enums\ConflictMatchTier;
use App\Enums\PartyRole;
use App\Models\MatterParty;
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
 * cờ. `readonly` để không ai vô tình gắn thêm thuộc tính sau khi tạo (bản thân hai model bên dưới
 * VẪN mutable như mọi Eloquent model khác — `readonly` chỉ khoá việc GÁN LẠI hai thuộc tính đó).
 *
 * **`ourPartyRole`/`ourPartyName` (M6.5 Task 8, R13d/`conflict-07`).** Vai và tên của bên PHÍA
 * MÌNH — bên đang được `RunConflictCheck::matchesFor()` xét, tức bên gây ra khớp này — không phải
 * của bên tìm thấy. Cùng ranh giới lộ thông tin như sáu trường kia: đây là dữ liệu người dùng vừa
 * tự nhập vào chính form của họ (hoặc một bên đã có sẵn của vụ việc họ đang xem), không phải nội
 * dung của hồ sơ kia, nên không mở rộng gì cả. Thiếu hai trường này là đúng lỗ hổng `conflict-07`:
 * một bảng nhiều bên mà không nói RÕ bên nào của form gây khớp, người dùng phải đoán — và
 * `conflict-01` cho thấy đoán sai thì đổ lỗi nhầm cho bên vô can.
 *
 * **`ourPartyRecord`/`foundPartyRecord` VÀ `pairKey()` — KHÔNG có trong `toArray()`, không bao giờ
 * tới trình duyệt (M6.5 Task 8, fix round 1, C1/`conflict-01`).** Bản trước dùng một CHỮ KÝ ĐỊNH
 * DANH ("hash:xxx"/"phone:xxx"/"name:xxx" của bên phía mình, nối với id thật của bên tìm thấy) làm
 * `pairKey`. Đó là một lỗi thật (Critical, fix round 1): chữ ký định danh không phân biệt được HAI
 * DÒNG `matter_parties` KHÁC NHAU của cùng một người — nếu Y được thêm hai lần (một lần vai
 * `related`, khớp vàng, được xác nhận; một lần khác — dòng MỚI, vai `defendant`, khớp ĐỎ) thì cả
 * hai lần đều băm ra CÙNG một chữ ký (chữ ký chỉ phụ thuộc định danh của Y, không phụ thuộc dòng
 * nào hay vai gì), nên lần ĐỎ bị nhận nhầm là "đã xác nhận" và hiện XANH — chính lỗ hổng nó được
 * sinh ra để chặn. `pairKey()` giờ tính từ ID THẬT của HAI DÒNG (`ourPartyRecord->getKey()` ↔
 * `foundPartyRecord->getKey()`), không phải từ định danh. Hai model được giữ NGUYÊN THAM CHIẾU
 * (không copy id lúc dựng `ConflictMatch`) vì bên phía mình có thể CHƯA LƯU tại thời điểm
 * `RunConflictCheck` dựng khớp này (giai đoạn kiểm tra chạy TRƯỚC giai đoạn lưu) — `getKey()` của
 * nó là `null` lúc này, và `pairKey()` phải trả về `null` (không thể là "đã xác nhận" nếu chưa từng
 * có id để so). Vì đây là THAM CHIẾU cùng object, khi `OpenMatter`/`AddMatterParty` gọi
 * `$party->save()` ở giai đoạn lưu, CHÍNH đối tượng đó (không phải một bản sao) nhận id thật — gọi
 * lại `pairKey()` sau đó (đúng lúc ghi `confirmed_pairs`) trả về chữ ký thật, đúng cặp DÒNG vừa
 * được chấp nhận. `foundPartyRecord` (khớp lịch sử) LUÔN đã tồn tại trong DB (truy vấn của
 * `matchesFor()` chỉ đọc các dòng đã lưu) nên `getKey()` của nó luôn có giá trị — chỉ phía
 * `ourPartyRecord` mới có thể null lúc tính. `null` cho CẢ `pairKey()` VÀ `toArray()`: hai model
 * này không được lộ ra ngoài Action dưới bất kỳ hình thức nào (không id, không thuộc tính khác).
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
        public MatterParty $ourPartyRecord,
        public MatterParty $foundPartyRecord,
    ) {}

    /**
     * Chữ ký nội bộ "bên phía mình ↔ bản ghi tìm thấy", theo ID THẬT của hai dòng `matter_parties`
     * — xem docblock lớp cho lý do không dùng định danh nữa. `null` khi `ourPartyRecord` CHƯA LƯU
     * (giai đoạn kiểm tra, trước khi giai đoạn lưu chạy) — một dòng chưa có id không thể là "đã
     * từng được xác nhận" ở một lần chạy trước, vì nó chưa từng tồn tại để mà xác nhận.
     */
    public function pairKey(): ?string
    {
        $ourId = $this->ourPartyRecord->getKey();
        $foundId = $this->foundPartyRecord->getKey();

        return ($ourId !== null && $foundId !== null) ? "{$ourId}::{$foundId}" : null;
    }

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
