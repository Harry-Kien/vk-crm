<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Giai đoạn là dữ liệu cấu hình, không hardcode (SPEC §4.5).
        Schema::create('matter_type_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_type_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label', 120);
            $table->string('client_label', 120);
            $table->text('client_description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->json('allowed_next');
            $table->unsignedSmallInteger('default_next_update_days')->default(14);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['matter_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_type_stages');
    }
};
