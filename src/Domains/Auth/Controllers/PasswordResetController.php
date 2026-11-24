<?php

declare(strict_types=1);

namespace EcApi\Domains\Auth\Controllers;

use EcApi\Mail\OtpMail;
use EcApi\Models\AdminPasswordReset;
use EcApi\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class PasswordResetController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const MAX_ATTEMPTS    = 5;

    /**
     * Step 1 — send a 6-digit OTP to the email address.
     *
     * POST /api/admin/forgot-password
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim((string) $request->input('email')));

        $user = User::where('email', $email)->first();

        // Always respond with success to prevent email enumeration.
        if (! $user) {
            return response()->json([
                'message' => 'If that email exists, an OTP has been sent.',
            ]);
        }

        // Delete any prior pending resets for this email.
        AdminPasswordReset::where('email', $email)->delete();

        $plainOtp = (string) random_int(100_000, 999_999);

        AdminPasswordReset::create([
            'email'      => $email,
            'otp'        => hash('sha256', $plainOtp),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);

        Mail::to($user->email)->send(new OtpMail($plainOtp, $user->name ?? $user->email));

        return response()->json([
            'message' => 'If that email exists, an OTP has been sent.',
        ]);
    }

    /**
     * Step 2 — verify the OTP; on success issue a short-lived reset_token.
     *
     * POST /api/admin/verify-otp
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp'   => ['required', 'digits:6'],
        ]);

        $email = strtolower(trim((string) $request->input('email')));
        $otp   = (string) $request->input('otp');

        $record = AdminPasswordReset::where('email', $email)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (! $record) {
            return $this->otpError('No active OTP request found for this email.');
        }

        if ($record->isExpired()) {
            $record->delete();

            return $this->otpError('OTP has expired. Please request a new one.');
        }

        if ($record->hasExceededAttempts()) {
            $record->delete();

            return $this->otpError('Too many incorrect attempts. Please request a new OTP.');
        }

        if (! hash_equals($record->otp, hash('sha256', $otp))) {
            $record->increment('attempts');

            $remaining = self::MAX_ATTEMPTS - ($record->attempts + 1);

            return $this->otpError(
                $remaining > 0
                    ? "Incorrect OTP. {$remaining} attempt(s) remaining."
                    : 'Incorrect OTP. No attempts remaining — please request a new OTP.',
                422,
            );
        }

        // OTP correct — generate reset token.
        $resetToken = Str::uuid()->toString();

        $record->update([
            'reset_token' => hash('sha256', $resetToken),
            'verified_at' => now(),
        ]);

        return response()->json([
            'message'     => 'OTP verified successfully.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * Step 3 — set a new password using the reset_token from step 2.
     *
     * POST /api/admin/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => ['required', 'email'],
            'reset_token'           => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $email      = strtolower(trim((string) $request->input('email')));
        $resetToken = (string) $request->input('reset_token');

        $record = AdminPasswordReset::where('email', $email)
            ->whereNotNull('verified_at')
            ->latest()
            ->first();

        if (! $record || ! $record->reset_token) {
            return $this->error('Invalid or expired reset session. Please start over.');
        }

        if (! hash_equals($record->reset_token, hash('sha256', $resetToken))) {
            return $this->error('Invalid reset token.');
        }

        // Tokens are single-use and expire after 15 minutes of verification.
        if ($record->verified_at->addMinutes(15)->isPast()) {
            $record->delete();

            return $this->error('Reset session has expired. Please start over.');
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return $this->error('User not found.');
        }

        $user->update(['password' => Hash::make($request->input('password'))]);
        $record->delete();

        return response()->json(['message' => 'Password reset successfully. You can now sign in.']);
    }

    private function otpError(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    private function error(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
