<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14 Task 6 — sổ tên các tệp biên nhận văn phòng mà CRM đã xử lý (kế hoạch M14, R10; rà soát Task 7,
 * r3). `ImportOfficeReceipts` đọc theo cursor (tên lớn nhất đã qua); một biên nhận ĐẾN MUỘN, tên nhỏ hơn
 * cursor (hai lượt kéo chồng nhau ở máy văn phòng), trước đây bị bỏ qua im lặng — và các tệp của nó
 * không bao giờ có biên nhận lại, vì máy văn phòng đã ghi chúng vào `receipted.txt`. Tên không có
 * trong sổ là chưa xử lý.
 *
 * Bảng hạ tầng: không model, không màn hình (chỉ `DB::table` trong `ImportOfficeReceipts`). Khoảng một
 * dòng mỗi đêm. Tên chỉ mang giờ UTC (`receipt-<UTC>.json`, 29 ký tự), không dữ liệu khách. Cột
 * `outcome`: enum `App\Enums\OfficeReceiptOutcome`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_receipt_imports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('outcome', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_receipt_imports');
    }
};
