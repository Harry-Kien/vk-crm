<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('counterpart', 200);
            $table->text('summary');
            $table->boolean('is_visible_to_client')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['matter_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
