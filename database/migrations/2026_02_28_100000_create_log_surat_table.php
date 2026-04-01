<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_surat', function (Blueprint $table) {
            $table->string('id', 11)->primary();
            $table->string('surat_tugas_id', 11);
            $table->foreign('surat_tugas_id')->references('id')->on('surat_tugas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aksi', 50); // dibuat, diperbarui, dikirim, ditandatangani, ditolak, direvisi, diselesaikan, diunduh, digenerate_ulang
            $table->text('keterangan')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['surat_tugas_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_surat');
    }
};
