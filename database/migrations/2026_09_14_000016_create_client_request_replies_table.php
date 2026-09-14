<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_request_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('client_requests')->cascadeOnDelete();
            $table->string('author_type');
            $table->unsignedBigInteger('author_id');
            $table->text('content');
            $table->timestamps();

            $table->index(['author_type', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_request_replies');
    }
};
