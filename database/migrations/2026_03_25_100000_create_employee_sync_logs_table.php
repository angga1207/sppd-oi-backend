<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('instances')->nullOnDelete();
            $table->string('instance_name')->nullable();
            $table->integer('id_skpd')->nullable();
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->integer('total_fetched')->default(0);
            $table->integer('total_created')->default(0);
            $table->integer('total_updated')->default(0);
            $table->integer('total_deleted')->default(0);
            $table->text('error_message')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('instance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sync_logs');
    }
};
