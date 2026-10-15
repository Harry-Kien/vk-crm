<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Làn kd (2026-10-14, vai "Nhân viên kinh doanh"): ba cột của một lead.
     *
     *  - `consulting_lawyer_id` — luật sư tư vấn mà người theo lead đã "Chuyển cho luật sư"
     *    (`HandIntakeToLawyer`). Trường RIÊNG, không dùng `assigned_to`: người được giao vẫn là người theo
     *    lead (nhân viên kinh doanh vẫn thấy và chăm sóc lead mình được giao), còn luật sư tư vấn thấy lead
     *    qua `IntakeRequest::scopeVisibleTo()`. Nhân sự bị xoá cứng thì về null như `assigned_to`.
     *  - `handed_to_lawyer_at` — lần chuyển gần nhất.
     *  - `next_follow_up_at` — hẹn liên hệ lại, đặt (hay xoá) mỗi lần ghi một lần chăm sóc
     *    (`LogIntakeFollowUp`); widget "Lead đến hẹn liên hệ lại" đọc nó, nên có index.
     */
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->foreignId('consulting_lawyer_id')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->timestamp('handed_to_lawyer_at')->nullable()->after('consulting_lawyer_id');
            $table->dateTime('next_follow_up_at')->nullable()->after('handed_to_lawyer_at');

            $table->index('next_follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->dropIndex(['next_follow_up_at']);
            $table->dropConstrainedForeignId('consulting_lawyer_id');
            $table->dropColumn(['handed_to_lawyer_at', 'next_follow_up_at']);
        });
    }
};
