<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 200);
            $table->text('content');
            $table->string('status', 20)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
    }
};
