<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityAuditLogger
{
    /**
     * Keys to strictly scrub if present in logged context.
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'new_password',
        'temporary_password',
        'token',
        'secret',
        'key',
        'access_token',
        'api_key',
        'cookie',
        'authorization',
    ];

    /**
     * Log an authorization denial event to the operational security log channel per RBAC-DEC-05.
     *
     * @param Request|null $request
     * @param string $reason
     * @param string|null $requiredPermission
     * @param string|null $targetType
     * @param string|null $targetId
     * @param array<string, mixed> $extraContext
     * @return void
     */
    public static function logDenial(
        ?Request $request,
        string $reason,
        ?string $requiredPermission = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $extraContext = []
    ): void {
        try {
            $user = $request?->user();

            $sanitizedContext = self::sanitizeContext($extraContext);

            $payload = [
                'event'               => 'authorization_denied',
                'timestamp'           => now()->toISOString(),
                'actor_id'            => $user?->id,
                'actor_email'         => $user?->email,
                'ip_address'          => $request?->ip(),
                'route'               => $request?->path(),
                'method'              => $request?->method(),
                'required_permission' => $requiredPermission,
                'target_type'         => $targetType,
                'target_id'           => $targetId,
                'reason'              => $reason,
                'user_agent'          => $request?->userAgent(),
                'context'             => $sanitizedContext,
            ];

            Log::channel('security')->warning('SECURITY_DENIAL: ' . $reason, $payload);
        } catch (Throwable $e) {
            // Operational log failure must never break execution or compromise fail-closed security
        }
    }

    /**
     * Recursively scrub sensitive keys and credential patterns from context.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeContext($value);
            } elseif (is_string($value) && (
                str_starts_with($value, '$6$') || // SHA512-CRYPT
                str_starts_with($value, '$2y$') || // Bcrypt
                str_starts_with($value, '$2a$') ||
                str_starts_with($value, 'eyJ')     // JWT / Bearer
            )) {
                $sanitized[$key] = '[REDACTED_HASH]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
