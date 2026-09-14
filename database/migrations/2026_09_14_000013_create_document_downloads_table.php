<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ghi mọi lượt tải, cả nội bộ lẫn khách (SPEC §4.12). Không xoá.
        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('downloader_type');
            $table->unsignedBigInteger('downloader_id');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('downloaded_at');
            $table->timestamps();

            $table->index(['downloader_type', 'downloader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
    }
};
