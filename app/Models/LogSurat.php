<?php

namespace App\Models;

use App\Traits\HasNanoId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogSurat extends Model
{
    use HasNanoId;
    protected $table = 'log_surat';

    protected $fillable = [
        'surat_tugas_id',
        'user_id',
        'aksi',
        'keterangan',
        'ip_address',
        'user_agent',
    ];

    /**
     * Action labels for display
     */
    public const ACTION_LABELS = [
        'dibuat' => 'Surat Tugas Dibuat',
        'diperbarui' => 'Surat Tugas Diperbarui',
        'dikirim' => 'Dikirim untuk Ditandatangani',
        'ditandatangani' => 'Ditandatangani',
        'ditolak' => 'Ditolak',
        'direvisi' => 'Dikembalikan ke Draft (Revisi)',
        'diselesaikan' => 'Diselesaikan',
        'diunduh' => 'Dokumen Diunduh',
        'digenerate_ulang' => 'Dokumen Digenerate Ulang',
    ];

    /**
     * Action icons/colors for frontend
     */
    public const ACTION_COLORS = [
        'dibuat' => 'blue',
        'diperbarui' => 'amber',
        'dikirim' => 'candy',
        'ditandatangani' => 'mint',
        'ditolak' => 'red',
        'direvisi' => 'orange',
        'diselesaikan' => 'grape',
        'diunduh' => 'sky',
        'digenerate_ulang' => 'amber',
    ];

    // Relationships
    public function suratTugas(): BelongsTo
    {
        return $this->belongsTo(SuratTugas::class, 'surat_tugas_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Accessor for label
    public function getLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->aksi] ?? $this->aksi;
    }

    public function getColorAttribute(): string
    {
        return self::ACTION_COLORS[$this->aksi] ?? 'gray';
    }

    /**
     * Helper: create a log entry
     */
    public static function catat(
        string $suratTugasId,
        string $aksi,
        ?int $userId = null,
        ?string $keterangan = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'surat_tugas_id' => $suratTugasId,
            'user_id' => $userId,
            'aksi' => $aksi,
            'keterangan' => $keterangan,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
