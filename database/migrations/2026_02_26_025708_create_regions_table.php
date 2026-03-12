<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->string('id')->primary(); // ID dari API ibnux
            $table->string('nama');
            $table->timestamps();
        });

        Schema::create('kabupatens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('id_provinsi');
            $table->string('nama');
            $table->timestamps();

            $table->foreign('id_provinsi')->references('id')->on('provinces')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->index('id_provinsi');
        });

        Schema::create('kecamatans', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('id_kabupaten');
            $table->string('nama');
            $table->timestamps();

            $table->foreign('id_kabupaten')->references('id')->on('kabupatens')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->index('id_kabupaten');
        });

        Schema::create('kelurahans', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('id_kecamatan');
            $table->string('nama');
            $table->timestamps();

            $table->foreign('id_kecamatan')->references('id')->on('kecamatans')
                ->cascadeOnDelete()->cascadeOnUpdate();
            $table->index('id_kecamatan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelurahans');
        Schema::dropIfExists('kecamatans');
        Schema::dropIfExists('kabupatens');
        Schema::dropIfExists('provinces');
    }
};
