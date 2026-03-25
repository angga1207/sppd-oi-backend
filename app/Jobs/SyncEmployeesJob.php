<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\EmployeeSyncLog;
use App\Models\Instance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncEmployeesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public ?int $instanceId = null
    ) {}

    public function handle(): void
    {
        $semestaUrl = config('services.semesta.url') . '/daftar-pegawai';
        $apiKey = config('services.semesta.api_key');

        $instances = Instance::whereNotNull('id_eoffice')
            ->where('status', 'active')
            ->when($this->instanceId, fn ($q) => $q->where('id', $this->instanceId))
            ->get();

        if ($instances->isEmpty()) {
            Log::info('SyncEmployees: Tidak ada instance aktif dengan id_eoffice.');
            return;
        }

        // Phase 1: Upsert all employees across all instances (no deletions yet)
        $syncResults = [];
        foreach ($instances as $instance) {
            $syncResults[] = $this->syncInstance($instance, $semestaUrl, $apiKey);
        }

        // Phase 2: Cleanup — soft-delete employees no longer in their instance's API response.
        // Done AFTER all upserts so transferred employees already have their new instance_id.
        foreach ($syncResults as $result) {
            if ($result === null) {
                continue;
            }

            $deleted = 0;
            if (!empty($result['fetchedSemestaIds'])) {
                $deleted = Employee::where('instance_id', $result['instanceId'])
                    ->whereNotIn('semesta_id', $result['fetchedSemestaIds'])
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => Carbon::now()]);
            }

            // Update the sync log with deleted count
            if ($result['syncLog'] && $deleted > 0) {
                $result['syncLog']->increment('total_deleted', $deleted);
            }

            if ($deleted > 0) {
                Log::info("SyncEmployees cleanup: {$result['instanceName']} — deleted: {$deleted}");
            }
        }
    }

    private function syncInstance(Instance $instance, string $semestaUrl, string $apiKey): ?array
    {
        $syncLog = EmployeeSyncLog::create([
            'instance_id' => $instance->id,
            'instance_name' => $instance->name,
            'id_skpd' => $instance->id_eoffice,
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        $startTime = microtime(true);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PostmanRuntime/7.44.1',
                    'x-api-key' => $apiKey,
                ])
                ->post($semestaUrl, [
                    'id_skpd' => (int) $instance->id_eoffice,
                ]);

            if (!$response->successful()) {
                $this->finishLog($syncLog, 'failed', $startTime, errorMessage: "API returned HTTP {$response->status()}");
                return null;
            }

            $data = $response->json();
            $pegawaiList = $data['data'] ?? $data ?? [];

            if (!is_array($pegawaiList)) {
                $this->finishLog($syncLog, 'failed', $startTime, errorMessage: 'Response data bukan array.');
                return null;
            }

            $totalFetched = count($pegawaiList);
            $created = 0;
            $updated = 0;

            $fetchedSemestaIds = [];

            foreach ($pegawaiList as $p) {
                $semestaId = $p['id'] ?? null;
                if (!$semestaId) {
                    continue;
                }

                $fetchedSemestaIds[] = $semestaId;

                $employeeData = [
                    'nama_lengkap' => $p['nama_lengkap'] ?? '-',
                    'nip' => $p['nip'] ?? '-',
                    'jenis_pegawai' => $p['jenis_pegawai'] ?? null,
                    'instance_id' => $instance->id,
                    'id_skpd' => $p['id_skpd'] ?? $instance->id_eoffice,
                    'id_jabatan' => $p['id_jabatan'] ?? null,
                    'jabatan' => $p['jabatan'] ?? null,
                    'kepala_skpd' => $p['kepala_skpd'] ?? null,
                    'foto_pegawai' => $p['foto_pegawai'] ?? null,
                    'email' => $p['email'] ?? null,
                    'no_hp' => $p['no_hp'] ?? null,
                    'eselon' => $p['eselon'] ?? null,
                    'golongan' => $p['golongan'] ?? null,
                    'pangkat' => $p['pangkat'] ?? null,
                    'ref_jabatan_baru' => $p['ref_jabatan_baru'] ?? null,
                ];

                $existing = Employee::withTrashed()
                    ->where('semesta_id', $semestaId)
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($employeeData);
                    $updated++;
                } else {
                    Employee::create(array_merge($employeeData, [
                        'semesta_id' => $semestaId,
                    ]));
                    $created++;
                }
            }

            $this->finishLog($syncLog, 'success', $startTime,
                totalFetched: $totalFetched,
                created: $created,
                updated: $updated,
            );

            Log::info("SyncEmployees: {$instance->name} — fetched: {$totalFetched}, created: {$created}, updated: {$updated}");

            return [
                'instanceId' => $instance->id,
                'instanceName' => $instance->name,
                'fetchedSemestaIds' => $fetchedSemestaIds,
                'syncLog' => $syncLog,
            ];
        } catch (\Exception $e) {
            Log::error("SyncEmployees error [{$instance->name}]: {$e->getMessage()}");
            $this->finishLog($syncLog, 'failed', $startTime, errorMessage: $e->getMessage());
            return null;
        }
    }

    private function finishLog(
        EmployeeSyncLog $log,
        string $status,
        float $startTime,
        int $totalFetched = 0,
        int $created = 0,
        int $updated = 0,
        int $deleted = 0,
        ?string $errorMessage = null,
    ): void {
        $log->update([
            'status' => $status,
            'total_fetched' => $totalFetched,
            'total_created' => $created,
            'total_updated' => $updated,
            'total_deleted' => $deleted,
            'error_message' => $errorMessage,
            'duration_seconds' => round(microtime(true) - $startTime, 2),
            'finished_at' => Carbon::now(),
        ]);
    }
}
