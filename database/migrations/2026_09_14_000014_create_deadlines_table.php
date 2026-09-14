<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->date('due_date');
            $table->string('severity', 20)->default('normal');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_published')->default(false);
            $table->json('reminders_sent');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['due_date', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadlines');
    }
};
