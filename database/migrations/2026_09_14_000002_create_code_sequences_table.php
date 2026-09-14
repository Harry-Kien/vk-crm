<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Một dòng cho mỗi dãy mã (ví dụ client:2026, matter:2026:DD). Khoá dòng khi lấy số kế tiếp.
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
