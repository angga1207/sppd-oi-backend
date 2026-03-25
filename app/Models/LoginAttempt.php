<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $fillable = [
        'username',
        'ip_address',
        'attempts',
        'is_blocked',
        'blocked_until',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'blocked_until' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public const CAPTCHA_THRESHOLD = 5;
    public const BLOCK_THRESHOLD = 10;
    public const BLOCK_DURATION_MINUTES = 1440; // 24 hours

    /**
     * Get or create the attempt record for a username+IP combination
     */
    public static function getAttempt(string $username, string $ip): self
    {
        return self::firstOrCreate(
            ['username' => $username, 'ip_address' => $ip],
            ['attempts' => 0]
        );
    }

    /**
     * Check if this attempt record is currently blocked
     */
    public function isCurrentlyBlocked(): bool
    {
        if (!$this->is_blocked) {
            return false;
        }

        // Check if block has expired
        if ($this->blocked_until && now()->gte($this->blocked_until)) {
            $this->update([
                'is_blocked' => false,
                'blocked_until' => null,
                'attempts' => 0,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Whether captcha is required for this attempt record
     */
    public function requiresCaptcha(): bool
    {
        return $this->attempts >= self::CAPTCHA_THRESHOLD;
    }

    /**
     * Record a failed login attempt. Returns the updated attempt.
     */
    public function recordFailedAttempt(): self
    {
        $this->increment('attempts');
        $this->update(['last_attempt_at' => now()]);

        if ($this->attempts >= self::BLOCK_THRESHOLD) {
            $this->update([
                'is_blocked' => true,
                'blocked_until' => now()->addMinutes(self::BLOCK_DURATION_MINUTES),
            ]);
        }

        return $this->fresh();
    }

    /**
     * Reset attempts on successful login
     */
    public function resetAttempts(): void
    {
        $this->update([
            'attempts' => 0,
            'is_blocked' => false,
            'blocked_until' => null,
        ]);
    }

    /**
     * Unblock this record (admin action)
     */
    public function unblock(): void
    {
        $this->update([
            'attempts' => 0,
            'is_blocked' => false,
            'blocked_until' => null,
        ]);
    }

    /**
     * Generate a simple math captcha challenge
     */
    public static function generateCaptcha(): array
    {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $operators = ['+', '-'];
        $op = $operators[array_rand($operators)];

        $answer = $op === '+' ? $a + $b : $a - $b;

        // Encode the answer with a simple hash to verify later
        $token = hash_hmac('sha256', (string) $answer, config('app.key'));

        return [
            'question' => "{$a} {$op} {$b} = ?",
            'token' => $token,
        ];
    }

    /**
     * Verify a captcha answer against the token
     */
    public static function verifyCaptcha(string $answer, string $token): bool
    {
        $expected = hash_hmac('sha256', $answer, config('app.key'));
        return hash_equals($expected, $token);
    }
}
