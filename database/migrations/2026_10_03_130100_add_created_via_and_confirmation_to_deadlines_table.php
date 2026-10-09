<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 R5 (Task 7) — mốc hạn tạo qua AI.
 *
 *  - `created_via` (`App\Enums\CreatedVia`, mặc định `web`): tool `create_deadline` (Task 13) ÉP `mcp`.
 *    Mọi mốc có trước migration này do nhân sự nhập trên `/admin` (`AddMatterDeadline` là đường tạo
 *    mốc duy nhất), nên `web` là đúng cho chúng.
 *  - `confirmed_at`, `confirmed_by`: một người trong `/admin` bấm "Xác nhận" trên mốc tạo qua AI
 *    (Task 12). Mốc `mcp` chưa xác nhận mang nhãn "Tạo qua AI, chưa xác nhận" — nhưng vẫn được nhắc
 *    hạn như mốc thường (`CheckDeadlines`): một mốc tố tụng thật bị im lặng là thiệt hại thật.
 *
 * `confirmed_by` theo đúng khuôn của `created_by`/`updated_by` trên bảng này: `nullOnDelete`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->string('created_via', 20)->default('web')->after('reminders_sent');
            $table->timestamp('confirmed_at')->nullable()->after('created_via');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deadlines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['created_via', 'confirmed_at']);
        });
    }
};
