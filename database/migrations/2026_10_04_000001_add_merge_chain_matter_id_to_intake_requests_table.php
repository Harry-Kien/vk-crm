<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rà soát cuối M10, vòng sửa 1 (FC1). Gộp (R4) để tên, SĐT, email và câu chuyện ở lại BẢN NGUỒN; khi
     * bản đích về sau thành vụ việc, bản nguồn là một phần hồ sơ của khách (Task 7). Luật "bản ghi đã
     * chuyển thành vụ `restricted` chỉ thấy được với người xem được vụ đó" (SPEC §5, bổ sung 2026-09-30)
     * chỉ đọc `matter_id` của CHÍNH bản ghi, nên bản nguồn — cùng người, cùng câu chuyện — vẫn hiện với
     * trưởng phòng và người đã ghi nó.
     *
     * `merge_chain_matter_id`: vụ việc mà bản cuối của chuỗi gộp chứa bản này đã thành. Chỉ đặt trên các
     * bản ĐÃ GỘP (bản đã chuyển đổi mang vụ ở `matter_id`), chỉ `ConvertIntakeToMatter` đặt — trong cùng
     * transaction liên kết, cùng chỗ đã xoá hạn lưu của chúng. Chuỗi đóng băng từ lúc đó: không ai gộp vào
     * một bản đã chuyển đổi hay đã gộp đi (`MergeIntake`). `IntakeRequest::scopeVisibleTo()` áp vế
     * `restricted` cho cột này đúng như cho `matter_id`. Không phải dữ liệu cá nhân: ẩn danh giữ nguyên.
     *
     * Không có trong bảng "Mô hình dữ liệu" của kế hoạch — ghi ở Ghi chú M10.
     *
     * Điền ngược cho các chuỗi đã chuyển đổi trước cột này (dữ liệu dev, dữ liệu mẫu): đi cây ngược của
     * `merged_into_id` từ mỗi bản có `matter_id`, đọc thẳng `DB::table()` (không model, không scope).
     */
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->foreignId('merge_chain_matter_id')->nullable()->after('merged_into_id')
                ->constrained('matters')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Điền ngược (docblock `up()`). Tách riêng để test chạy được nó trên dữ liệu thật mà không phải
     * `down()` — trên SQLite, bỏ một khoá ngoại là dựng lại cả bảng.
     */
    public function backfill(): void
    {
        DB::table('intake_requests')
            ->whereNotNull('matter_id')
            ->orderBy('id')
            ->get(['id', 'matter_id'])
            ->each(function (object $converted): void {
                $seen = [(int) $converted->id];
                $frontier = [(int) $converted->id];

                while ($frontier !== []) {
                    $frontier = DB::table('intake_requests')
                        ->whereIn('merged_into_id', $frontier)
                        ->whereNotIn('id', $seen)
                        ->pluck('id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->all();

                    if ($frontier !== []) {
                        DB::table('intake_requests')->whereIn('id', $frontier)->update(['merge_chain_matter_id' => $converted->matter_id]);
                        $seen = [...$seen, ...$frontier];
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merge_chain_matter_id');
        });
    }
};
