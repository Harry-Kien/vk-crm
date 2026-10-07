<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M10 (kế hoạch 2026-09-22, "Mô hình dữ liệu"): MỘT lần có người liên hệ văn phòng — chưa phải
        // khách hàng (R2), nên không phải hàng `clients`. Không màn hình nào xoá dòng: xoá là ẩn
        // danh (R7), tức các cột cá nhân về null và dòng, mã, nguồn, trạng thái, mốc thời gian ở lại.
        // Vì vậy MỌI cột cá nhân đều nullable.
        //
        // Không có cột số CCCD thô: chỉ dấu băm `contact_id_number_hash` (R7, SPEC §10.5).
        //
        // `contact_name_normalized` KHÔNG có trong bảng của kế hoạch — thêm ở Task 1 vì nguồn dò thứ
        // hai của `RunConflictCheck` (R1) khớp người liên hệ theo tên đã chuẩn hoá giống hệt cách nó
        // khớp `matter_parties.name_normalized`; thiếu cột này thì phải chuẩn hoá từng dòng bằng PHP.
        // Cột cá nhân, nên bị ẩn danh về null cùng `contact_name`.
        Schema::create('intake_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();

            $table->string('contact_name', 200)->nullable();
            $table->string('contact_name_normalized', 200)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_phone_normalized', 20)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_id_number_hash', 64)->nullable();
            $table->string('contact_role', 20)->nullable();

            $table->string('source', 20);
            $table->string('referred_by', 200)->nullable();
            $table->foreignId('matter_type_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('quoted_amount')->nullable();

            $table->string('conflict_level', 10)->nullable();
            $table->timestamp('conflict_checked_at')->nullable();
            $table->json('conflict_result')->nullable();
            $table->foreignId('conflict_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conflict_acknowledged_at')->nullable();
            $table->foreignId('conflict_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('conflict_override_reason')->nullable();

            $table->string('privacy_notice_version', 20)->nullable();
            $table->timestamp('privacy_notice_acknowledged_at')->nullable();
            $table->foreignId('privacy_notice_recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // `dateTime`, không `timestamp`: MariaDB có thể gán DEFAULT CURRENT_TIMESTAMP ON UPDATE cho
            // cột `timestamp` NOT NULL đầu tiên nếu `explicit_defaults_for_timestamp` tắt.
            $table->dateTime('received_at');
            $table->timestamp('first_response_at')->nullable();

            $table->text('decline_reason')->nullable();
            $table->boolean('decline_reason_is_conflict')->default(false);

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->constrained('intake_requests')->nullOnDelete();

            $table->date('retention_until')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->foreignId('anonymised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('anonymised_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('contact_phone_normalized');
            $table->index('contact_id_number_hash');
            $table->index('contact_name_normalized');
            $table->index('retention_until');
            $table->index(['status', 'received_at']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_requests');
    }
};
