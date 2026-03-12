<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('klasifikasi', function (Blueprint $table) {
            $table->id();
            $table->integer('parent_id')->nullable();
            $table->string('kode', 100);
            $table->text('klasifikasi');
            $table->text('deskripsi')->nullable();
            $table->string('status', 20)->default('AKTIF');
            $table->timestamps();

            $table->index(['kode', 'parent_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('klasifikasi');
    }
};
