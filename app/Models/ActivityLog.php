<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * Action constants
     */
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_CREATE_SURAT = 'create_surat';
    public const ACTION_UPDATE_SURAT = 'update_surat';
    public const ACTION_DELETE_SURAT = 'delete_surat';
    public const ACTION_KIRIM_SURAT = 'kirim_surat';
    public const ACTION_TANDATANGANI_SURAT = 'tandatangani_surat';
    public const ACTION_TOLAK_SURAT = 'tolak_surat';
    public const ACTION_REVISI_SURAT = 'revisi_surat';
    public const ACTION_SELESAI_SURAT = 'selesai_surat';
    public const ACTION_DOWNLOAD_SURAT = 'download_surat';
    public const ACTION_SUBMIT_LAPORAN = 'submit_laporan';
    public const ACTION_UPDATE_SPD = 'update_spd';

    /**
     * Action labels for display
     */
    public const ACTION_LABELS = [
        'login' => 'Login',
        'logout' => 'Logout',
        'create_surat' => 'Membuat Surat Tugas',
        'update_surat' => 'Mengubah Surat Tugas',
        'delete_surat' => 'Menghapus Surat Tugas',
        'kirim_surat' => 'Mengirim Surat Tugas',
        'tandatangani_surat' => 'Menandatangani Surat Tugas',
        'tolak_surat' => 'Menolak Surat Tugas',
        'revisi_surat' => 'Merevisi Surat Tugas',
        'selesai_surat' => 'Menyelesaikan Surat Tugas',
        'download_surat' => 'Mengunduh Dokumen',
        'submit_laporan' => 'Submit Laporan SPD',
        'update_spd' => 'Mengubah SPD',
    ];

    /**
     * Action icons/colors for frontend
     */
    public const ACTION_COLORS = [
        'login' => 'mint',
        'logout' => 'gray',
        'create_surat' => 'blue',
        'update_surat' => 'amber',
        'delete_surat' => 'red',
        'kirim_surat' => 'candy',
        'tandatangani_surat' => 'mint',
        'tolak_surat' => 'red',
        'revisi_surat' => 'orange',
        'selesai_surat' => 'grape',
        'download_surat' => 'sky',
        'submit_laporan' => 'blue',
        'update_spd' => 'amber',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Accessors
    public function getLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function getColorAttribute(): string
    {
        return self::ACTION_COLORS[$this->action] ?? 'gray';
    }

    /**
     * Helper: create an activity log entry
     */
    public static function log(
        int $userId,
        string $action,
        string $description,
        ?array $properties = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
