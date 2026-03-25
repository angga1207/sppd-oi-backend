<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master table: Pejabat Pembuat Komitmen per OPD
        Schema::create('pejabat_pembuat_komitmen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')
                ->constrained('instances')
                ->cascadeOnDelete();
            $table->string('nama');
            $table->string('nip');
            $table->string('jabatan')->nullable();
            $table->string('pangkat')->nullable();
            $table->string('golongan')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['instance_id', 'is_active']);
        });

        // Add PPK fields to surat_tugas
        Schema::table('surat_tugas', function (Blueprint $table) {
            $table->string('ppk_nama')->nullable()->after('penandatangan_instance_id');
            $table->string('ppk_nip')->nullable()->after('ppk_nama');
            $table->string('ppk_jabatan')->nullable()->after('ppk_nip');
            $table->string('ppk_pangkat')->nullable()->after('ppk_jabatan');
            $table->string('ppk_golongan')->nullable()->after('ppk_pangkat');
            $table->foreignId('ppk_instance_id')
                ->nullable()
                ->after('ppk_golongan')
                ->constrained('instances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('surat_tugas', function (Blueprint $table) {
            $table->dropForeign(['ppk_instance_id']);
            $table->dropColumn([
                'ppk_nama',
                'ppk_nip',
                'ppk_jabatan',
                'ppk_pangkat',
                'ppk_golongan',
                'ppk_instance_id',
            ]);
        });

        Schema::dropIfExists('pejabat_pembuat_komitmen');
    }
};
