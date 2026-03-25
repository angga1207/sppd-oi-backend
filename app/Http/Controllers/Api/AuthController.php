<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Models\Instance;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Login via Semesta API
     * Frontend sends username & password -> Backend hits Semesta API
     * If success -> check user in DB -> generate token
     * If user not found -> create new user -> generate token
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'captcha_answer' => 'nullable|string',
            'captcha_token' => 'nullable|string',
        ]);

        $ip = $request->ip();
        $attempt = LoginAttempt::getAttempt($request->username, $ip);

        // Check if blocked
        if ($attempt->isCurrentlyBlocked()) {
            $remaining = now()->diffInSeconds($attempt->blocked_until, false);
            $minutes = (int) ceil($remaining / 60);

            return response()->json([
                'success' => false,
                'message' => "Akun diblokir karena terlalu banyak percobaan gagal. Coba lagi dalam {$minutes} menit.",
                'blocked' => true,
                'blocked_until' => $attempt->blocked_until->toISOString(),
                'retry_after' => $remaining,
            ], 429);
        }

        // Verify captcha if required
        if ($attempt->requiresCaptcha()) {
            if (!$request->captcha_answer || !$request->captcha_token) {
                $captcha = LoginAttempt::generateCaptcha();
                return response()->json([
                    'success' => false,
                    'message' => 'Captcha diperlukan. Silakan jawab pertanyaan berikut.',
                    'requires_captcha' => true,
                    'captcha' => $captcha,
                    'attempts' => $attempt->attempts,
                ], 422);
            }

            if (!LoginAttempt::verifyCaptcha($request->captcha_answer, $request->captcha_token)) {
                $captcha = LoginAttempt::generateCaptcha();
                return response()->json([
                    'success' => false,
                    'message' => 'Jawaban captcha salah. Silakan coba lagi.',
                    'requires_captcha' => true,
                    'captcha' => $captcha,
                    'attempts' => $attempt->attempts,
                ], 422);
            }
        }

        try {
            // Hit Semesta API for authentication
            $semestaUrl = config('services.semesta.url') . '/auth-user-evalakip';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PostmanRuntime/7.44.1',
                ])
                ->post($semestaUrl, [
                    'username' => $request->username,
                    'password' => $request->password,
                ]);

            if (!$response->successful()) {
                $attempt = $attempt->recordFailedAttempt();
                $responseData = [
                    'success' => false,
                    'message' => 'Autentikasi gagal. Username atau password salah.',
                    'attempts' => $attempt->attempts,
                ];

                if ($attempt->isCurrentlyBlocked()) {
                    $responseData['blocked'] = true;
                    $responseData['blocked_until'] = $attempt->blocked_until->toISOString();
                    $responseData['message'] = 'Akun diblokir karena terlalu banyak percobaan gagal. Coba lagi dalam ' . LoginAttempt::BLOCK_DURATION_MINUTES . ' menit.';
                    return response()->json($responseData, 429);
                }

                if ($attempt->requiresCaptcha()) {
                    $responseData['requires_captcha'] = true;
                    $responseData['captcha'] = LoginAttempt::generateCaptcha();
                }

                return response()->json($responseData, 401);
            }

            $semestaData = $response->json();

            // Semesta API returns: {error_code, status: "success", message, atribut_user: {...}}
            if (!isset($semestaData['status']) || $semestaData['status'] !== 'success') {
                $attempt = $attempt->recordFailedAttempt();
                $responseData = [
                    'success' => false,
                    'message' => $semestaData['message'] ?? 'Autentikasi gagal.',
                    'attempts' => $attempt->attempts,
                ];

                if ($attempt->isCurrentlyBlocked()) {
                    $responseData['blocked'] = true;
                    $responseData['blocked_until'] = $attempt->blocked_until->toISOString();
                    $responseData['message'] = 'Akun diblokir karena terlalu banyak percobaan gagal. Coba lagi dalam ' . LoginAttempt::BLOCK_DURATION_MINUTES . ' menit.';
                    return response()->json($responseData, 429);
                }

                if ($attempt->requiresCaptcha()) {
                    $responseData['requires_captcha'] = true;
                    $responseData['captcha'] = LoginAttempt::generateCaptcha();
                }

                return response()->json($responseData, 401);
            }

            $userData = $semestaData['atribut_user'] ?? null;

            if (!$userData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data pengguna tidak ditemukan dari Semesta.',
                ], 404);
            }

            // Find or create employee
            // Semesta fields: id, nik, id_pegawai, username, fullname, email, no_hp,
            //   foto_pegawai, id_skpd, nama_skpd, id_unit_kerja, nama_unit_kerja,
            //   id_jabatan, jabatan
            $isBupati = ($userData['username'] ?? $request->username) === '1000'
                || ($userData['jenis_pegawai'] ?? '') === 'bupati';

            // Resolve instance_id from id_skpd
            $instanceId = null;
            if (!$isBupati && isset($userData['id_skpd'])) {
                $instance = Instance::where('id_eoffice', $userData['id_skpd'])->first();
                $instanceId = $instance?->id;
            }

            $employee = Employee::updateOrCreate(
                ['semesta_id' => $userData['id_pegawai'] ?? $userData['id']],
                [
                    'nama_lengkap' => $userData['fullname'] ?? 'Unknown',
                    'nip' => $userData['username'] ?? $request->username,
                    'jenis_pegawai' => $employee['jenis_pegawai'] ?? $userData['jenis_pegawai'] ?? 'staff',
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

            // Check if user exists in our database
            $user = User::where('username', $request->username)->first();

            if (!$user) {
                // Determine role
                $defaultRole = \App\Models\Role::where('slug', 'staff')->first();
                $roleId = $defaultRole->id;

                if ($isBupati) {
                    $bupatiRole = \App\Models\Role::where('slug', 'bupati')->first();
                    $roleId = $bupatiRole->id;
                }

                // Create new user
                $user = User::create([
                    'name' => $userData['fullname'] ?? 'Unknown',
                    'username' => $request->username,
                    'nik' => $userData['nik'] ?? null,
                    'email' => $userData['email'] ?? null,
                    'image' => $userData['foto_pegawai'] ?? '/storage/images/users/default.png',
                    'role_id' => $roleId,
                    'instance_id' => $isBupati ? null : $employee->instance_id,
                    'employee_id' => $employee->id,
                    'jabatan' => $userData['jabatan'] ?? null,
                    'no_hp' => $userData['no_hp'] ?? null,
                    'password' => bcrypt($request->password),
                ]);
            } else {
                // Update existing user data
                $user->update([
                    'name' => $userData['fullname'] ?? $user->name,
                    'nik' => $userData['nik'] ?? $user->nik,
                    'email' => $userData['email'] ?? $user->email,
                    'image' => $userData['foto_pegawai'] ?? $user->image,
                    'instance_id' => $isBupati ? null : ($employee->instance_id ?? $user->instance_id),
                    'employee_id' => $employee->id,
                    'jabatan' => $userData['jabatan'] ?? $user->jabatan,
                    'no_hp' => $userData['no_hp'] ?? $user->no_hp,
                    'password' => bcrypt($request->password),
                ]);
            }

            // Revoke previous tokens
            $user->tokens()->delete();

            // Reset login attempts on successful login
            $attempt->resetAttempts();

            // Generate new token
            $token = $user->createToken('auth-token')->plainTextToken;

            // Activity Log - Login
            ActivityLog::log(
                $user->id,
                ActivityLog::ACTION_LOGIN,
                'Login ke aplikasi',
                null,
                $request->ip(),
                $request->userAgent()
            );

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil.',
                'data' => [
                    'user' => $user->load(['role', 'instance', 'employee']),
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat proses login. Silakan coba lagi.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Logout - Revoke current token
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Activity Log - Logout
        ActivityLog::log(
            $user->id,
            ActivityLog::ACTION_LOGOUT,
            'Logout dari aplikasi',
            null,
            $request->ip(),
            $request->userAgent()
        );

        $user->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Get authenticated user profile
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'instance', 'employee']);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * Check login attempt status for a username (public)
     * Returns whether captcha is required and/or user is blocked
     */
    public function loginStatus(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
        ]);

        $ip = $request->ip();
        $attempt = LoginAttempt::getAttempt($request->username, $ip);

        $data = [
            'attempts' => $attempt->attempts,
            'requires_captcha' => $attempt->requiresCaptcha(),
            'blocked' => $attempt->isCurrentlyBlocked(),
        ];

        if ($data['blocked']) {
            $data['blocked_until'] = $attempt->blocked_until->toISOString();
            $remaining = now()->diffInSeconds($attempt->blocked_until, false);
            $data['retry_after'] = max(0, $remaining);
        }

        if ($data['requires_captcha']) {
            $data['captcha'] = LoginAttempt::generateCaptcha();
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * List all blocked login attempts (Super Admin only)
     */
    public function blockedUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if (($user->role->slug ?? '') !== 'super-admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = LoginAttempt::query()
            ->orderByDesc('last_attempt_at');

        if ($request->filled('status')) {
            if ($request->status === 'blocked') {
                $query->where('is_blocked', true)->where('blocked_until', '>', now());
            } elseif ($request->status === 'warning') {
                $query->where('attempts', '>=', LoginAttempt::CAPTCHA_THRESHOLD)
                      ->where(function ($q) {
                          $q->where('is_blocked', false)
                            ->orWhere('blocked_until', '<=', now());
                      });
            }
        } else {
            // Default: show records with at least 1 attempt
            $query->where('attempts', '>', 0);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'ilike', "%{$search}%")
                  ->orWhere('ip_address', 'ilike', "%{$search}%");
            });
        }

        $attempts = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $attempts,
        ]);
    }

    /**
     * Unblock a specific login attempt record (Super Admin only)
     */
    public function unblockUser(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if (($user->role->slug ?? '') !== 'super-admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $attempt = LoginAttempt::findOrFail($id);
        $attempt->unblock();

        return response()->json([
            'success' => true,
            'message' => "User {$attempt->username} (IP: {$attempt->ip_address}) berhasil di-unblock.",
        ]);
    }

    /**
     * Unblock all blocked records (Super Admin only)
     */
    public function unblockAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        if (($user->role->slug ?? '') !== 'super-admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $count = LoginAttempt::where('is_blocked', true)->count();
        LoginAttempt::where('is_blocked', true)->update([
            'attempts' => 0,
            'is_blocked' => false,
            'blocked_until' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$count} record berhasil di-unblock.",
        ]);
    }
}
