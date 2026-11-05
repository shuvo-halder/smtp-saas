<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MailDeliveryService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    public function __construct(
        protected MailDeliveryService $mailDeliveryService,
        protected AuditService $auditService
    ) {}

    /**
     * Handle incoming password reset link request.
     * Always returns generic response to prevent account enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->status !== 'suspended') {
            try {
                $token = Password::broker()->createToken($user);
                $resetUrl = config('app.frontend_url', config('app.url')) . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

                $this->mailDeliveryService->enqueue(
                    tenantId: $user->id,
                    recipientEmail: $user->email,
                    messageType: 'password_reset',
                    subject: 'Reset your EmailSaaS password',
                    payload: [
                        'reset_url' => $resetUrl,
                    ],
                    idempotencyKey: "password_reset:user_{$user->id}:" . substr(sha1($token), 0, 16),
                );

                $this->auditService->record(
                    action: 'auth.password_reset_request',
                    entityType: 'User',
                    entityId: $user->id,
                    before: null,
                    after: ['email' => $user->email],
                    actor: $user,
                    reason: 'Password reset link requested',
                    request: $request
                );
            } catch (Throwable $e) {
                Log::error('Password reset dispatch failed: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                ]);
            }
        }

        return response()->json([
            'message' => 'If an account exists for this email address, a password reset link has been sent.',
        ], 200);
    }

    /**
     * Handle incoming password reset execution with token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                $this->auditService->record(
                    action: 'auth.password_reset_success',
                    entityType: 'User',
                    entityId: $user->id,
                    before: null,
                    after: ['email' => $user->email],
                    actor: $user,
                    reason: 'Password reset successfully completed',
                    request: $request
                );
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 200);
        }

        return response()->json([
            'message' => __($status),
        ], 400);
    }
}
