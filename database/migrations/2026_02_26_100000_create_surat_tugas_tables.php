<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Surat Tugas (induk)
        Schema::create('surat_tugas', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_surat')->nullable();
            $table->foreignId('klasifikasi_id')
                ->nullable()
                ->constrained('klasifikasi')
                ->nullOnDelete();
            $table->foreignId('kategori_id')
                ->nullable()
                ->constrained('kategori_surat')
                ->nullOnDelete();

            // Pemberi Perintah
            $table->string('pemberi_perintah_nama')->nullable();
            $table->string('pemberi_perintah_nip')->nullable();
            $table->string('pemberi_perintah_jabatan')->nullable();
            $table->foreignId('pemberi_perintah_instance_id')
                ->nullable()
                ->constrained('instances')
                ->nullOnDelete();
            $table->string('pemberi_perintah_pangkat')->nullable();
            $table->string('pemberi_perintah_golongan')->nullable();

            $table->text('dasar')->nullable(); // HTML from quill editor
            $table->text('untuk')->nullable(); // HTML from quill editor
            $table->boolean('has_spd')->default(false); // true = has SPD children

            // Penandatangan
            $table->string('penandatangan_nama')->nullable();
            $table->string('penandatangan_nip')->nullable();
            $table->string('penandatangan_jabatan')->nullable();
            $table->foreignId('penandatangan_instance_id')
                ->nullable()
                ->constrained('instances')
                ->nullOnDelete();

            // Instansi yang mengeluarkan
            $table->foreignId('instance_id')
                ->nullable()
                ->constrained('instances')
                ->nullOnDelete();

            // SPD shared fields (only when has_spd = true)
            $table->enum('jenis_perjalanan', ['luar_kabupaten', 'dalam_kabupaten'])->nullable();
            $table->string('tujuan_provinsi_id')->nullable();
            $table->string('tujuan_provinsi_nama')->nullable();
            $table->string('tujuan_kabupaten_id')->nullable();
            $table->string('tujuan_kabupaten_nama')->nullable();
            $table->string('tujuan_kecamatan_id')->nullable();
            $table->string('tujuan_kecamatan_nama')->nullable();
            $table->string('lokasi_tujuan')->nullable();
            $table->date('tanggal_berangkat')->nullable();
            $table->integer('lama_perjalanan')->nullable(); // hari
            $table->date('tanggal_kembali')->nullable();
            $table->string('tempat_dikeluarkan')->nullable();
            $table->date('tanggal_dikeluarkan')->nullable();
            $table->string('alat_angkut')->nullable();
            $table->decimal('biaya', 15, 2)->nullable();
            $table->string('sub_kegiatan_kode')->nullable();
            $table->string('sub_kegiatan_nama')->nullable();
            $table->string('kode_rekening')->nullable();
            $table->string('uraian_rekening')->nullable();
            $table->text('keterangan')->nullable();

            // Status flow
            $table->enum('status', [
                'draft',
                'dikirim',
                'ditandatangani',
                'ditolak',
                'selesai',
            ])->default('draft');

            // Files
            $table->string('file_surat_tugas')->nullable(); // PDF path
            $table->string('file_surat_tugas_signed')->nullable(); // Signed PDF path

            // Tanggal ditandatangani
            $table->timestamp('signed_at')->nullable();

            // Created by user
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'instance_id', 'created_by']);
            $table->index(['nomor_surat']);
        });

        // Pegawai yang ditugaskan (many-to-many pivot)
        Schema::create('surat_tugas_pegawai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surat_tugas_id')
                ->constrained('surat_tugas')
                ->cascadeOnDelete();

            // Data pegawai snapshot (dari Semesta API)
            $table->bigInteger('semesta_pegawai_id')->nullable();
            $table->string('nip');
            $table->string('nama_lengkap');
            $table->string('jabatan')->nullable();
            $table->string('pangkat')->nullable();
            $table->string('golongan')->nullable();
            $table->string('eselon')->nullable();
            $table->integer('id_skpd')->nullable();
            $table->string('nama_skpd')->nullable();
            $table->integer('id_jabatan')->nullable();

            $table->timestamps();

            $table->index(['surat_tugas_id', 'nip']);
        });

        // Surat Perjalanan Dinas (child of Surat Tugas, 1 per pegawai)
        Schema::create('surat_perjalanan_dinas', function (Blueprint $table) {
            $table->id();
            $table->string('nomor_spd')->nullable();
            $table->string('tingkat_biaya', 1)->nullable()
                ->comment('Tingkat biaya perjalanan dinas: A-G berdasarkan eselon/golongan');
            $table->foreignId('surat_tugas_id')
                ->constrained('surat_tugas')
                ->cascadeOnDelete();
            $table->foreignId('surat_tugas_pegawai_id')
                ->constrained('surat_tugas_pegawai')
                ->cascadeOnDelete();

            // Status follows Surat Tugas but can track individually
            $table->enum('status', [
                'draft',
                'dikirim',
                'ditandatangani',
                'ditolak',
                'selesai',
            ])->default('draft');

            // Files
            $table->string('file_spd')->nullable();
            $table->string('file_spd_signed')->nullable();

            // Tanggal ditandatangani
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['surat_tugas_id', 'status']);
            $table->index(['nomor_spd']);
        });

        // Laporan pasca perjalanan dinas
        Schema::create('laporan_perjalanan_dinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spd_id')
                ->constrained('surat_perjalanan_dinas')
                ->cascadeOnDelete();
            $table->text('laporan')->nullable(); // isi laporan
            $table->json('lampiran')->nullable(); // array of file paths
            $table->timestamps();
        });

        // Pengikut SPD
        Schema::create('spd_pengikut', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spd_id')
                ->constrained('surat_perjalanan_dinas')
                ->cascadeOnDelete();
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('keterangan')->nullable();
            $table->timestamps();

            $table->index('spd_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spd_pengikut');
        Schema::dropIfExists('laporan_perjalanan_dinas');
        Schema::dropIfExists('surat_perjalanan_dinas');
        Schema::dropIfExists('surat_tugas_pegawai');
        Schema::dropIfExists('surat_tugas');
    }
};
