<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bằng chứng khách đã đọc, ghi một lần cho mỗi cặp (SPEC §4.18).
        Schema::create('stage_log_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('viewed_at');
            $table->string('ip', 45);
            $table->timestamps();

            $table->unique(['stage_log_id', 'client_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_log_views');
    }
};
