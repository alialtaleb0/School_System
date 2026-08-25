<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class VerificationService
{
    private const MAX_ATTEMPTS = 3;
    private const ATTEMPTS_PER_HOUR = 3;
    private const COOLDOWN_MINUTES = 3;
    private const CODE_EXPIRY_MINUTES = 15;

    public function generateCode(User $user, string $type = 'email_verify'): VerificationCode
    {
        $this->clearUnusedCodes($user, $type);

        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        return VerificationCode::create([
            'user_id' => $user->id,
            'type' => $type,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::CODE_EXPIRY_MINUTES),
        ]);
    }

    public function sendCode(User $user, VerificationCode $verificationCode, ?string $subject = null): string
    {
        $subject = $subject ?? 'Email Verification Code';

        $codeText = "Your verification code is: {$verificationCode->code}\nThis code expires in " . self::CODE_EXPIRY_MINUTES . " minutes.";

        try {
            Mail::raw($codeText, function ($message) use ($user, $subject) {
                $message->to($user->email)
                    ->subject($subject);
            });
        } catch (\Exception $e) {
            \Log::info("Email sending failed, code: {$verificationCode->code}", ['error' => $e->getMessage()]);
        }

        return $verificationCode->code;
    }

    public function verify(User $user, string $code, string $type = 'email_verify'): array
    {
        $verificationCode = VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->latest()
            ->first();

        if (!$verificationCode) {
            return ['success' => false, 'message' => 'No verification code found. Please request a new one.'];
        }

        if ($verificationCode->isExpired()) {
            return ['success' => false, 'message' => 'Verification code has expired. Please request a new one.'];
        }

        if ($verificationCode->attempts >= self::ATTEMPTS_PER_HOUR) {
            $minutesUntilReset = $verificationCode->last_attempt_at
                ? self::COOLDOWN_MINUTES - now()->diffInMinutes($verificationCode->last_attempt_at)
                : self::COOLDOWN_MINUTES;

            if ($minutesUntilReset > 0) {
                return [
                    'success' => false,
                    'message' => "Too many attempts. Please wait {$minutesUntilReset} minutes before trying again.",
                ];
            }
        }

        $verificationCode->update([
            'attempts' => $verificationCode->attempts + 1,
            'last_attempt_at' => now(),
        ]);

        if ($verificationCode->code !== $code) {
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        $verificationCode->update(['is_used' => true]);

        if ($type === 'email_verify') {
            $user->update(['email_verified_at' => now()]);
        }

        return ['success' => true, 'message' => 'Code verified successfully.'];
    }

    public function canRequestCode(User $user, string $type = 'email_verify'): array
    {
        $lastCode = VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->latest()
            ->first();

        if ($lastCode && $lastCode->last_attempt_at) {
            $minutesSinceLastAttempt = now()->diffInMinutes($lastCode->last_attempt_at);

            if ($minutesSinceLastAttempt < self::COOLDOWN_MINUTES) {
                $waitMinutes = self::COOLDOWN_MINUTES - $minutesSinceLastAttempt;
                return [
                    'allowed' => false,
                    'message' => "Please wait {$waitMinutes} minutes before requesting a new code.",
                ];
            }
        }

        $recentCodesCount = VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCodesCount >= self::ATTEMPTS_PER_HOUR) {
            return [
                'allowed' => false,
                'message' => 'Maximum verification codes reached for this hour. Please try again later.',
            ];
        }

        return ['allowed' => true];
    }

    private function clearUnusedCodes(User $user, string $type = 'email_verify'): void
    {
        VerificationCode::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_used', false)
            ->delete();
    }
}
