<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'data',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Notification types
     */
    public const TYPE_SURAT_DIKIRIM = 'surat_dikirim';
    public const TYPE_SURAT_DITANDATANGANI = 'surat_ditandatangani';
    public const TYPE_SURAT_DITOLAK = 'surat_ditolak';

    /**
     * Type labels for display
     */
    public const TYPE_LABELS = [
        'surat_dikirim' => 'Surat Dikirim',
        'surat_ditandatangani' => 'Surat Ditandatangani',
        'surat_ditolak' => 'Surat Ditolak',
    ];

    /**
     * Type colors for frontend
     */
    public const TYPE_COLORS = [
        'surat_dikirim' => 'candy',
        'surat_ditandatangani' => 'mint',
        'surat_ditolak' => 'red',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Helpers
    public function markAsRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function getLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getColorAttribute(): string
    {
        return self::TYPE_COLORS[$this->type] ?? 'gray';
    }
}
