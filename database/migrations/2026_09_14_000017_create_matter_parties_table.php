<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tồn tại vì kiểm tra xung đột lợi ích (SPEC §4.16). Không có cột số căn cước gốc, chỉ hash.
        Schema::create('matter_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_our_client')->default(false);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('name_normalized', 200)->nullable();
            $table->string('id_number_hash', 64)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('address', 300)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('id_number_hash');
            $table->index('phone_normalized');
            $table->index('name_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_parties');
    }
};
