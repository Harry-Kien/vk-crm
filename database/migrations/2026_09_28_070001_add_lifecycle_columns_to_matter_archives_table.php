<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7 Task 3, R1 + Task 6 brief. Hai việc trong một migration vì cả hai đều thuộc "chuẩn bị bảng
 * `matter_archives` cho vòng đời lưu trữ", và Task 6 (ghi quyết định tiêu huỷ) sẽ dùng ngay ba
 * cột ghi quyết định — không thêm một migration thứ hai chỉ để tách chúng ra.
 *
 * `handover_document_id` (R1 — phán quyết của chủ nhiệm): gói bàn giao là một bản ghi `Document`
 * (nhóm B, đĩa `private`, qua `PublishDocument`), không phải một chuỗi đường dẫn. `nullable()` vì
 * cột được ĐIỀN sau, ở Task 4/11 khi job sinh gói chạy lần đầu — một vụ vừa đóng chưa có gói nào.
 * `nullOnDelete()`: gói là một `Document` bình thường, có thể bị xoá mềm/vĩnh viễn theo đúng vòng
 * đời tài liệu; mất gói không kéo theo mất bản ghi lưu trữ (ngày đóng, hạn truy cập, hạn lưu vẫn
 * là dữ liệu cần giữ).
 *
 * `handover_package_path` NGỪNG DÙNG (R1): đường tải duy nhất trong hệ thống
 * (`routes/web.php`, `documents.download`) nhận id của một `Document`, không nhận một đường dẫn
 * trần. Giữ cột lại (không `dropColumn`) vì đây là một hệ thống đã seed dữ liệu và xoá cột đang có
 * dữ liệu là một thao tác phá huỷ không cần thiết cho một cột đã NULL ở mọi dòng hiện có; bỏ nó
 * khỏi `MatterArchive::$fillable` là đủ để không còn đường ghi nào chạm tới nó nữa — xem đính
 * chính SPEC §4.19 trong `docs/SPEC.md`.
 *
 * `destruction_reason`/`destruction_record_no`/`destroyed_by` (Task 6 brief, R5 — không bao giờ
 * `forceDelete()`): quyết định tiêu huỷ hồ sơ được GHI LẠI, không thực hiện bằng xoá. Cả ba
 * `nullable()` vì Task 3 chỉ chuẩn bị cột; Action ghi quyết định là việc của Task 6.
 * `destruction_record_no` dài 50 ký tự — đủ cho một số biên bản kiểu "BB-TH-2026-000123" cộng dư,
 * không cần một cột `text`; Task 6 dùng ĐÚNG con số này cho `maxLength()` của form (CLAUDE.md:
 * `maxLength` phải khớp độ dài cột DB, MariaDB strict mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_archives', function (Blueprint $table) {
            $table->foreignId('handover_document_id')->nullable()->after('handover_generated_at')
                ->constrained('documents')->nullOnDelete();
            $table->text('destruction_reason')->nullable()->after('destroyed_at');
            $table->string('destruction_record_no', 50)->nullable()->after('destruction_reason');
            $table->foreignId('destroyed_by')->nullable()->after('destruction_record_no')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matter_archives', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_document_id');
            $table->dropConstrainedForeignId('destroyed_by');
            $table->dropColumn(['destruction_reason', 'destruction_record_no']);
        });
    }
};
