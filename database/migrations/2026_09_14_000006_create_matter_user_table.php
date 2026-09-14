<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng quyết định luật sư nào thấy vụ việc nào (SPEC §4.7).
        Schema::create('matter_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_in_matter', 20);
            $table->timestamps();

            $table->unique(['matter_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_user');
    }
};
