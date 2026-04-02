<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Instance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemestaUserService
{
    /**
     * Fetch user data from Semesta API and create a local User + Employee record.
     *
     * Used by the mobile API when a NIP is not yet registered locally.
     *
     * @param string $nip  The NIP (username) to look up
     * @return User|null   The newly-created User, or null on failure
     */
    public static function createFromSemesta(string $nip): ?User
    {
        try {
            $semestaUrl = config('services.semesta.url') . '/auth-user-evalakip';
            $masterPassword = '#OganIlirBangkit!!';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PostmanRuntime/7.44.1',
                ])
                ->post($semestaUrl, [
                    'username' => $nip,
                    'password' => $masterPassword,
                ]);

            if (!$response->successful()) {
                Log::warning("SemestaUserService: login failed for NIP {$nip}, HTTP " . $response->status());
                return null;
            }

            $semestaData = $response->json();

            if (!isset($semestaData['status']) || $semestaData['status'] !== 'success') {
                Log::warning("SemestaUserService: Semesta returned non-success for NIP {$nip}");
                return null;
            }

            $userData = $semestaData['atribut_user'] ?? null;
            if (!$userData) {
                Log::warning("SemestaUserService: No atribut_user for NIP {$nip}");
                return null;
            }

            $isBupati = ($userData['username'] ?? $nip) === '1000'
                || ($userData['jenis_pegawai'] ?? '') === 'bupati';

            // Resolve instance_id from id_skpd
            $instanceId = null;
            if (!$isBupati && isset($userData['id_skpd'])) {
                $instance = Instance::where('id_eoffice', $userData['id_skpd'])->first();
                $instanceId = $instance?->id;
            }

            // Create or update Employee
            $employee = Employee::updateOrCreate(
                ['semesta_id' => $userData['id_pegawai'] ?? $userData['id']],
                [
                    'nama_lengkap' => $userData['fullname'] ?? 'Unknown',
                    'nip' => $userData['username'] ?? $nip,
                    'jenis_pegawai' => $userData['jenis_pegawai'] ?? 'staff',
                    'instance_id' => $instanceId,
                    'id_skpd' => $isBupati ? null : ($userData['id_skpd'] ?? null),
                    'id_jabatan' => $userData['id_jabatan'] ?? null,
                    'jabatan' => $userData['jabatan'] ?? null,
                    'kepala_skpd' => $userData['kepala_skpd'] ?? null,
                    'foto_pegawai' => $userData['foto_pegawai'] ?? null,
                    'email' => $userData['email'] ?? null,
                    'no_hp' => $userData['no_hp'] ?? null,
                    'eselon' => $userData['eselon'] ?? null,
                    'golongan' => $userData['golongan'] ?? null,
                    'pangkat' => $userData['pangkat'] ?? null,
                    'ref_jabatan_baru' => $userData['ref_jabatan_baru'] ?? null,
                ]
            );

            // Determine role
            $roleId = Role::where('slug', 'staff')->first()?->id;
            if ($isBupati) {
                $roleId = Role::where('slug', 'bupati')->first()?->id ?? $roleId;
            }

            // Create User
            $user = User::create([
                'name' => $userData['fullname'] ?? 'Unknown',
                'username' => $nip,
                'nik' => $userData['nik'] ?? null,
                'email' => $userData['email'] ?? null,
                'image' => $userData['foto_pegawai'] ?? '/storage/images/users/default.png',
                'role_id' => $roleId,
                'instance_id' => $isBupati ? null : $employee->instance_id,
                'employee_id' => $employee->id,
                'jabatan' => $userData['jabatan'] ?? null,
                'no_hp' => $userData['no_hp'] ?? null,
                'password' => bcrypt($masterPassword),
            ]);

            $user->load('employee', 'role');

            Log::info("SemestaUserService: Created local user for NIP {$nip} (user_id: {$user->id})");

            return $user;
        } catch (\Exception $e) {
            Log::error("SemestaUserService: Failed to create user for NIP {$nip}: " . $e->getMessage());
            return null;
        }
    }
}
