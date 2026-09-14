<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matter_checklist_item_id')->nullable()->constrained('matter_checklist_items')->nullOnDelete();
            $table->string('group', 1);
            $table->string('title', 250);
            $table->string('status', 20)->default('internal_draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('uploader_type');
            $table->unsignedBigInteger('uploader_id');
            $table->boolean('client_can_view')->default(false);
            $table->boolean('client_can_download')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['uploader_type', 'uploader_id']);
            $table->index(['matter_id', 'group', 'client_can_view']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
