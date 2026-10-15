<?php

namespace App\Support\Performance;

use App\Actions\Performance\BuildTaskWorkload;

/**
 * Phần "giao – nhận nội bộ" của MỘT dòng "Theo dõi đội ngũ" (làn tk, Task 2): việc và hồ sơ người này
 * đang giữ hay đang chờ họ, như MỘT người xem đọc được — mọi con số đã tính trên `Task::visibleTo($viewer)`
 * (việc) và trên vụ đang mở `Matter::listableBy($viewer)` (hồ sơ). Dựng bởi {@see BuildTaskWorkload};
 * định nghĩa từng cột ở khoá `tasks.workload.explain.*`.
 *
 * Lớp riêng, không thêm trường vào `TeamWorkloadRow`: số của M13 (N1–N11) giữ nguyên hình dạng, và ảnh
 * chụp đã lưu trong bộ nhớ đệm (`PerformanceCache`) của nó không gặp một lớp đổi hình dạng sau lần nâng cấp.
 */
final readonly class TaskWorkloadRow
{
    public function __construct(
        public int $userId,
        /** Việc người này đã nhận, đang làm. */
        public int $tasksInProgress,
        /** Việc giao cho người này chưa bấm "Đã nhận". */
        public int $tasksAwaiting,
        /** Việc người này đang giữ (chờ nhận hay đang làm) đã qua hạn. */
        public int $tasksOverdue,
        /** Việc người này đã giao bị trả lại, chờ họ giao lại hay huỷ. */
        public int $tasksReturned,
        /** Hồ sơ (ghế trong đội ngũ vụ đang mở) giao cho người này chưa bấm "Đã nhận hồ sơ". */
        public int $seatsAwaiting,
        /** Hồ sơ người này đã trả lại — cần quản lý hay luật sư phụ trách phân công lại. */
        public int $seatsReturned,
    ) {}
}
