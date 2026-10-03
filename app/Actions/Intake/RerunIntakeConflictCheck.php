<?php

namespace App\Actions\Intake;

use App\Actions\Intake\Concerns\HoldsConflictCheckLock;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\ConflictCheckResult;
use Illuminate\Support\Facades\Gate;

/**
 * Chạy lại kiểm tra xung đột cho một lần tiếp nhận đã có (M10 R1) — sau khi sửa danh tính, hoặc khi
 * người nhập bấm "kiểm tra lại" vì dữ liệu của văn phòng đã đổi (kết quả lúc tiếp nhận có hạn dùng).
 * Cùng đường với lần chạm đầu: dưới khoá `conflict-check`, dựng đầu vào từ dữ liệu đã lưu, ghi
 * `conflict_result` và một dòng `conflict_check_run` mang chủ thể là bản ghi.
 *
 * Xác nhận và ghi đè cũ chỉ bị xoá khi lần chạy này có khớp MỚI hoặc danh tính đã đổi
 * ({@see CheckIntakeConflict}); chạy lại mà không có gì mới thì cổng ô câu chuyện giữ nguyên. Chạy lại
 * KHÔNG BAO GIỜ xử lý được một Đỏ (fix vòng 1, I2 — Đỏ dính): sửa danh tính rồi chạy lại ra Xanh, kể
 * cả khi quản lý chạy, ô vẫn khoá cho tới khi quản lý/admin ghi đè kèm lý do
 * ({@see ResolveIntakeRedConflict}) — R1 chỉ có hai cách: từ chối hoặc ghi đè.
 *
 * Quyền: người nhìn thấy được bản ghi (`IntakeRequestPolicy::update`); bản đã xong việc (đã chuyển
 * thành vụ việc, đã ẩn danh hoặc đã gộp — `IntakeRequest::isClosedToChanges()`, đọc trên dòng vừa khoá)
 * bị từ chối.
 */
class RerunIntakeConflictCheck
{
    use HoldsConflictCheckLock;

    public function handle(User $actor, IntakeRequest $intake): ConflictCheckResult
    {
        Gate::forUser($actor)->authorize('update', $intake);

        return $this->underConflictCheckLock(fn (): ConflictCheckResult => $this->checkAndRecord($actor, $intake));
    }
}
