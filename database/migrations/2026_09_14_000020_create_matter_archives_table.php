<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('archived_at');
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('handover_package_path')->nullable();
            $table->timestamp('handover_generated_at')->nullable();
            $table->date('client_access_until')->nullable();
            $table->date('retention_until');
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_archives');
    }
};
