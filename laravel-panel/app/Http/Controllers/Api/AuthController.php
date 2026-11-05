<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'company_name' => 'nullable|string|max:255',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'status' => 'pending',
        ]);

        app(\App\Services\AuditService::class)->record(
            action: 'tenant.register',
            entityType: 'User',
            entityId: $user->id,
            before: null,
            after: [
                'name'    => $user->name,
                'email'   => $user->email,
                'status'  => $user->status,
                'outcome' => 'success',
            ],
            actor: $user,
            reason: 'Tenant account self-registration',
            request: $request
        );

        event(new \Illuminate\Auth\Events\Registered($user));

        // Enqueue account verification email fail-safely
        try {
            $verificationUrl = config('app.frontend_url', config('app.url')) . '/verify-email?id=' . $user->id . '&hash=' . sha1($user->email);
            app(\App\Services\MailDeliveryService::class)->enqueue(
                tenantId: $user->id,
                recipientEmail: $user->email,
                messageType: 'account_verification',
                subject: 'Verify your EmailSaaS account',
                payload: [
                    'name'             => $user->name,
                    'verification_url' => $verificationUrl,
                ],
                idempotencyKey: "registration_verification:user_{$user->id}",
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to enqueue registration verification email: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);
        }

        return response()->json(new UserResource($user), 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            return response()->json(null, 204);
        }

        throw ValidationException::withMessages([
            'email' => [__('auth.failed')],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function user(Request $request)
    {
        return new UserResource($request->user()->load('plan'));
    }

    public function verifyEmail(Request $request)
    {
        $validated = $request->validate([
            'id'   => 'required|integer',
            'hash' => 'required|string',
        ]);

        $user = User::find($validated['id']);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        if (!hash_equals(sha1($user->email), $validated['hash'])) {
            return response()->json(['message' => 'Invalid or expired verification hash.'], 400);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        app(\App\Services\AuditService::class)->record(
            action: 'auth.email_verified',
            entityType: 'User',
            entityId: $user->id,
            before: ['email_verified_at' => null],
            after: ['email_verified_at' => $user->email_verified_at->toIso8601String()],
            actor: $user,
            reason: 'User successfully completed email verification',
            request: $request
        );

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }
}
