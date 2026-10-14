<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục A6/A7): version nhóm A mà văn phòng ĐÃ NHẬN gần nhất
     * cho một đầu mục danh mục. Trước cột này, "bản đã nhận" không được ghi ở đâu: khách gửi thêm một
     * ảnh mờ vào một đầu mục đã nhận thì đầu mục thành "chờ duyệt" trên version mới, và văn phòng không
     * có đường nào quay về bản đã nhận (nhận v2 thì ảnh mờ vào gói bàn giao; từ chối v2 thì khách nhận
     * thư "bị từ chối" về giấy văn phòng đã có).
     *
     * Ai ghi: `ReviewChecklistItem` (nhận → version đang hiện; từ chối → null), `UploadStaffDocument`
     * (nộp thay nhóm A → version vừa tạo), `MarkChecklistItemNotApplicable` và `ReopenChecklistItem`
     * (→ null), `RetractDocument` khi rút bản hiện tại đưa đầu mục về "Còn thiếu" (→ null).
     * `SubmitClientDocument` KHÔNG đụng: khách gửi thêm không xoá bản văn phòng đã nhận.
     * `CollectHandoverEntries` đọc nó (gói bàn giao mang đúng bản đã nhận); null thì về luật cũ.
     *
     * Điền ngược cho các đầu mục ĐANG "đã nhận": version lớn nhất của chuỗi nhóm A — đúng thứ luật cũ
     * của gói bàn giao đã chọn, nên gói của chúng không đổi. Đầu mục đang "chờ duyệt" thì không biết được
     * trước đó có bản đã nhận hay không: để null (nút "Từ chối bản mới, giữ bản đã nhận" không hiện).
     */
    public function up(): void
    {
        Schema::table('matter_checklist_items', function (Blueprint $table) {
            $table->unsignedInteger('accepted_version')->nullable()->after('reviewed_at');
        });

        DB::table('matter_checklist_items')
            ->where('status', 'accepted')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $item): void {
                $version = DB::table('documents')
                    ->where('matter_checklist_item_id', $item->id)
                    ->where('group', 'A')
                    ->whereNull('deleted_at')
                    ->max('version');

                if ($version !== null) {
                    DB::table('matter_checklist_items')->where('id', $item->id)->update(['accepted_version' => (int) $version]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('matter_checklist_items', function (Blueprint $table) {
            $table->dropColumn('accepted_version');
        });
    }
};
