<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send notification to a specific user
     * Stores in DB + sends FCM push notification
     */
    public static function send(
        int $userId,
        string $title,
        string $body,
        string $type,
        ?array $data = null
    ): ?Notification {
        try {
            // Store in database
            $notification = Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'data' => $data,
            ]);

            // Send FCM push notification
            $user = User::find($userId);
            if ($user && $user->fcm_token) {
                self::sendFcmNotification($user->fcm_token, $title, $body, $data);
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('NotificationService send error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send notification to multiple users
     */
    public static function sendToMany(
        array $userIds,
        string $title,
        string $body,
        string $type,
        ?array $data = null
    ): void {
        foreach ($userIds as $userId) {
            self::send($userId, $title, $body, $type, $data);
        }
    }

    /**
     * Send FCM push notification using Firebase Cloud Messaging HTTP v1 API
     */
    private static function sendFcmNotification(
        string $fcmToken,
        string $title,
        string $body,
        ?array $data = null
    ): void {
        try {
            $serverKey = config('services.firebase.server_key');
            if (!$serverKey) {
                Log::warning('Firebase server key not configured.');
                return;
            }

            $payload = [
                'to' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                    'icon' => '/logo-oi.png',
                    'click_action' => config('app.frontend_url', 'http://localhost:3000') . '/dashboard',
                ],
                'data' => $data ?? [],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', $payload);

            if (!$response->successful()) {
                Log::warning('FCM send failed: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('FCM notification error: ' . $e->getMessage());
        }
    }

    /**
     * Notify penandatangan that a surat tugas has been sent for signing
     */
    public static function notifySuratDikirim(
        \App\Models\SuratTugas $suratTugas,
        \App\Models\User $sender
    ): void {
        // Find the signer user by NIP (username)
        $signerUser = User::where('username', $suratTugas->penandatangan_nip)->first();

        if ($signerUser) {
            self::send(
                $signerUser->id,
                'Surat Tugas Menunggu Tanda Tangan',
                "{$sender->name} mengirimkan Surat Tugas" .
                    ($suratTugas->nomor_surat ? " ({$suratTugas->nomor_surat})" : '') .
                    " untuk ditandatangani.",
                Notification::TYPE_SURAT_DIKIRIM,
                [
                    'surat_tugas_id' => $suratTugas->id,
                    'nomor_surat' => $suratTugas->nomor_surat,
                    'sender_name' => $sender->name,
                    'url' => "/dashboard/surat-tugas/{$suratTugas->id}",
                ]
            );
        }
    }

    /**
     * Notify creator and pegawai that surat tugas has been signed
     */
    public static function notifySuratDitandatangani(
        \App\Models\SuratTugas $suratTugas,
        \App\Models\User $signer
    ): void {
        $notifiedUserIds = [];

        // Notify the creator
        if ($suratTugas->created_by && $suratTugas->created_by !== $signer->id) {
            self::send(
                $suratTugas->created_by,
                'Surat Tugas Ditandatangani',
                "Surat Tugas {$suratTugas->nomor_surat} telah ditandatangani oleh {$signer->name}.",
                Notification::TYPE_SURAT_DITANDATANGANI,
                [
                    'surat_tugas_id' => $suratTugas->id,
                    'nomor_surat' => $suratTugas->nomor_surat,
                    'signer_name' => $signer->name,
                    'url' => "/dashboard/surat-tugas/{$suratTugas->id}",
                ]
            );
            $notifiedUserIds[] = $suratTugas->created_by;
        }

        // Notify all pegawai in ST/SPD
        $suratTugas->load('pegawai');
        foreach ($suratTugas->pegawai as $pegawai) {
            // Find user by NIP (username)
            $pegawaiUser = User::where('username', $pegawai->nip)->first();
            if ($pegawaiUser && !in_array($pegawaiUser->id, $notifiedUserIds) && $pegawaiUser->id !== $signer->id) {
                self::send(
                    $pegawaiUser->id,
                    'Surat Tugas Ditandatangani',
                    "Surat Tugas {$suratTugas->nomor_surat} yang melibatkan Anda telah ditandatangani oleh {$signer->name}.",
                    Notification::TYPE_SURAT_DITANDATANGANI,
                    [
                        'surat_tugas_id' => $suratTugas->id,
                        'nomor_surat' => $suratTugas->nomor_surat,
                        'signer_name' => $signer->name,
                        'url' => "/dashboard/surat-tugas/{$suratTugas->id}",
                    ]
                );
                $notifiedUserIds[] = $pegawaiUser->id;
            }
        }
    }

    /**
     * Notify creator that surat tugas has been rejected
     */
    public static function notifySuratDitolak(
        \App\Models\SuratTugas $suratTugas,
        \App\Models\User $rejector,
        ?string $alasan = null
    ): void {
        $notifiedUserIds = [];

        // Notify the creator
        if ($suratTugas->created_by && $suratTugas->created_by !== $rejector->id) {
            $bodyText = "Surat Tugas {$suratTugas->nomor_surat} ditolak oleh {$rejector->name}.";
            if ($alasan) {
                $bodyText .= " Alasan: {$alasan}";
            }

            self::send(
                $suratTugas->created_by,
                'Surat Tugas Ditolak',
                $bodyText,
                Notification::TYPE_SURAT_DITOLAK,
                [
                    'surat_tugas_id' => $suratTugas->id,
                    'nomor_surat' => $suratTugas->nomor_surat,
                    'rejector_name' => $rejector->name,
                    'alasan' => $alasan,
                    'url' => "/dashboard/surat-tugas/{$suratTugas->id}",
                ]
            );
            $notifiedUserIds[] = $suratTugas->created_by;
        }

        // Also notify pegawai in ST/SPD
        $suratTugas->load('pegawai');
        foreach ($suratTugas->pegawai as $pegawai) {
            $pegawaiUser = User::where('username', $pegawai->nip)->first();
            if ($pegawaiUser && !in_array($pegawaiUser->id, $notifiedUserIds) && $pegawaiUser->id !== $rejector->id) {
                $bodyText = "Surat Tugas {$suratTugas->nomor_surat} yang melibatkan Anda ditolak oleh {$rejector->name}.";
                if ($alasan) {
                    $bodyText .= " Alasan: {$alasan}";
                }

                self::send(
                    $pegawaiUser->id,
                    'Surat Tugas Ditolak',
                    $bodyText,
                    Notification::TYPE_SURAT_DITOLAK,
                    [
                        'surat_tugas_id' => $suratTugas->id,
                        'nomor_surat' => $suratTugas->nomor_surat,
                        'rejector_name' => $rejector->name,
                        'alasan' => $alasan,
                        'url' => "/dashboard/surat-tugas/{$suratTugas->id}",
                    ]
                );
                $notifiedUserIds[] = $pegawaiUser->id;
            }
        }
    }
}
