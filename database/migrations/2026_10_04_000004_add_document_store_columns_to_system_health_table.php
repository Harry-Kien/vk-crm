<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Task 1 — sức khoẻ kho tài liệu trên dòng duy nhất của `system_health` (kế hoạch M14, R9, R10,
 * R13, "Mô hình dữ liệu"):
 *
 * - `document_store_status` (`App\Enums\DocumentStoreStatus`), `document_store_checked_at`,
 *   `document_store_detail`: kết quả lần kiểm kho gần nhất. `document_store_detail` là câu tiếng
 *   Việt — KHÔNG mã tệp Drive, không bí mật.
 * - `last_office_receipt_at`: lúc nhập biên nhận hợp lệ gần nhất của máy văn phòng (bản thứ hai,
 *   R10); `last_office_receipt_error`: biên nhận bị từ chối hay lỗi phía văn phòng, câu tiếng Việt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_health', function (Blueprint $table) {
            $table->string('document_store_status', 20)->nullable();
            $table->timestamp('document_store_checked_at')->nullable();
            $table->text('document_store_detail')->nullable();
            $table->timestamp('last_office_receipt_at')->nullable();
            $table->text('last_office_receipt_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('system_health', function (Blueprint $table) {
            $table->dropColumn([
                'document_store_status',
                'document_store_checked_at',
                'document_store_detail',
                'last_office_receipt_at',
                'last_office_receipt_error',
            ]);
        });
    }
};
