<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('matter_type_id')->constrained()->restrictOnDelete();
            $table->string('title', 250);
            $table->text('description_internal')->nullable();
            $table->text('summary_for_client')->nullable();
            $table->string('stage', 40);
            $table->timestamp('stage_entered_at');
            $table->foreignId('lead_lawyer_id')->constrained('users')->restrictOnDelete();
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->boolean('is_published_to_portal')->default(false);
            $table->string('court_name', 200)->nullable();
            $table->string('case_number', 80)->nullable();
            $table->timestamp('last_client_update_at')->nullable();
            $table->string('confidentiality', 20)->default('normal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lead_lawyer_id');
            $table->index('stage');
            $table->index('last_client_update_at');
            $table->index(['is_published_to_portal', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
