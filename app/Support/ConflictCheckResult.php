<?php

namespace App\Support;

use App\Enums\ConflictLevel;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Kết quả một lần chạy `RunConflictCheck` (SPEC §6.10). `level` là mức cao nhất trong số các
 * `matches` tìm được — `Green` nếu `matches` rỗng.
 *
 * `incompleteParties`: tên các bên ĐÃ KIỂM TRA nhưng không có `id_number_hash` lẫn
 * `phone_normalized` (chỉ so khớp được theo tên — mức tin cậy thấp nhất, xem `RunConflictCheck`).
 * Đây không phải là lỗi để im lặng bỏ qua: một bên thiếu định danh có thể đang che giấu một xung
 * đột thật (trùng số căn cước với ai đó) mà Action không có cách nào phát hiện vì `identify()`
 * chưa từng được gọi với dữ liệu gốc. Phải hiện rõ cho người xem xét, không được lặn vào mức xanh
 * lặng lẽ.
 *
 * **`matches` và `confirmedMatches` (M6.5 Task 8, R13c/`conflict-01`).** Trước bản sửa này chỉ có
 * một `matches` duy nhất, và `RunConflictCheck` đối chiếu lại MỌI bên đã có ở mỗi lần chạy (có
 * chủ đích — xem docblock `RunConflictCheck`): hệ quả là một khi mức đỏ đã bị ghi đè (hoặc mức
 * vàng đã được xác nhận), CHÍNH khớp đó lại xuất hiện và lại đòi ghi đè/xác nhận ở MỌI lần thêm
 * bên sau đó trên cùng vụ việc — một cái cổng phải bấm qua mỗi lần dạy người dùng bấm cho xong,
 * và với `AddMatterParty` (không có cổng ghi đè cho vai luật sư phụ trách) đây còn là CHẶN CỨNG
 * vĩnh viễn (`conflict-01`). `matches` giờ chỉ còn những khớp MỚI — thứ thật sự cần một quyết
 * định ở lần chạy này, và là thứ `level`/`isBlocking()`/`requiresAcknowledgement()` bên dưới tính
 * trên đó. `confirmedMatches` là những khớp đã được xác nhận/ghi đè ở một lần chạy TRƯỚC trên
 * CÙNG vụ việc (nhận ra qua `ConflictMatch::$pairKey`, xem docblock ở đó và ở
 * `RunConflictCheck::confirmedPairKeys()`): R13c bắt buộc chúng "vẫn hiện, nhưng không chặn lại"
 * — vẫn nằm trong kết quả để người xem xét thấy đủ bức tranh, chỉ không còn tính vào `level`. Dùng
 * `allMatches()` khi cần hiển thị cả hai gộp lại theo đúng thứ tự đã tìm thấy.
 */
final class ConflictCheckResult implements Arrayable
{
    /**
     * Id của dòng `activity_log` sự kiện `conflict_check_run` mà CHÍNH lần chạy này vừa ghi
     * (M6.5 Task 8, R13g/`conflict-06`). Trường DUY NHẤT không `readonly` của lớp này, và không
     * có mặt trong `toArray()` — không bao giờ tới trình duyệt, chỉ dùng nội bộ giữa các Action.
     *
     * Không truyền được qua constructor: `RunConflictCheck::handle()` phải ghi dòng nhật ký
     * TRƯỚC (bằng chính `$result->toArray()`), rồi mới biết id của dòng vừa ghi — gán sau khi
     * dựng xong là cách duy nhất. `OpenMatter` đọc trường này để gắn `subject` của dòng log đó
     * vào vụ việc SAU KHI vụ việc được lưu: tại lúc kiểm tra chạy (giai đoạn 3), vụ việc CHƯA tồn
     * tại nên dòng `conflict_check_run` ban đầu có `subject` rỗng — đúng lỗ hổng `conflict-06`
     * ("bằng chứng kiểm tra lúc mở vụ không gắn với vụ việc").
     */
    public ?int $auditLogId = null;

    /**
     * @param  Collection<int, ConflictMatch>  $matches  Khớp MỚI — chưa từng được xác nhận/ghi đè
     *                                                   trên vụ việc này. Quyết định `level`.
     * @param  Collection<int, ConflictMatch>  $confirmedMatches  Khớp đã xác nhận/ghi đè ở một
     *                                                            lần chạy trước — vẫn hiện, không
     *                                                            chặn lại (R13c).
     * @param  Collection<int, string>  $incompleteParties
     */
    public function __construct(
        public readonly ConflictLevel $level,
        public readonly Collection $matches,
        public readonly Collection $confirmedMatches,
        public readonly Collection $incompleteParties,
    ) {}

    /** Cả khớp mới lẫn khớp đã xác nhận trước đó, theo đúng thứ tự tìm thấy — dùng để HIỂN THỊ. */
    public function allMatches(): Collection
    {
        return $this->matches->concat($this->confirmedMatches);
    }

    /** Đỏ — chặn lưu, chỉ `manager`/`admin` ghi đè kèm lý do (quyết định ở OpenMatter, không ở đây). */
    public function isBlocking(): bool
    {
        return $this->level === ConflictLevel::Red;
    }

    /**
     * Vàng, đỏ, hoặc có bên thiếu định danh (`hasIncompleteParties()`) — người tạo phải tích xác
     * nhận đã xem xét trước khi lưu. Một kết quả xanh với bên thiếu định danh KHÔNG đáng tin cậy
     * bằng xanh thật (xem docblock `RunConflictCheck` và `incompleteParties`): nếu chỉ nhìn
     * `requiresAcknowledgement()`/`isBlocking()`, caller không được phép hiện một form xanh trơn
     * mà giấu đi cảnh báo thiếu định danh — fix round 2.
     */
    public function requiresAcknowledgement(): bool
    {
        return $this->level !== ConflictLevel::Green || $this->hasIncompleteParties();
    }

    /** @return array<int, string> */
    public function incompleteParties(): array
    {
        return $this->incompleteParties->all();
    }

    public function hasIncompleteParties(): bool
    {
        return $this->incompleteParties->isNotEmpty();
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level->value,
            'matches' => $this->matches->map(fn (ConflictMatch $match) => $match->toArray())->all(),
            'confirmed_matches' => $this->confirmedMatches->map(fn (ConflictMatch $match) => $match->toArray())->all(),
            'incomplete_parties' => $this->incompleteParties->all(),
        ];
    }
}
