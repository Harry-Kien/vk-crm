<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khi khách nói "tôi không nhận được thông báo", tra ở đây (SPEC §4.15).
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);
            $table->string('recipient', 200);
            $table->string('template', 80);
            $table->json('payload')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status', 10)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};
