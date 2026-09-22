<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuditService
{
    /**
     * Exact and partial key names that must be recursively redacted in state payloads.
     */
    public const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'new_password',
        'temporary_password',
        'plain_password',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'plaintexttoken',
        'sanctum_token',
        'secret',
        'client_secret',
        'api_key',
        'apikey',
        'otp',
        'private_key',
        'privatekey',
        'signature',
        'verify_sign',
        'verify_key',
        'remember_token',
        'auth',
        'credentials',
    ];

    /**
     * Record an administrative audit event in the append-only application ledger.
     *
     * @param string $action Canonical action identifier, e.g. 'mailbox_toggle', 'mailbox_password_reset'
     * @param string $entityType Entity class name or descriptor, e.g. 'Mailbox', 'Domain', 'User'
     * @param int|string|null $entityId Entity primary identifier
     * @param mixed $before State snapshot prior to mutation
     * @param mixed $after State snapshot post mutation
     * @param User|null $actor The administrator user performing the action
     * @param string|null $reason Optional operational rationale
     * @param Request|null $request Current HTTP request for context extraction
     * @return AuditLog|null Returns the persisted AuditLog model, or null on failure (fail-safe)
     */
    public function record(
        string $action,
        string $entityType,
        int|string|null $entityId,
        mixed $before = null,
        mixed $after = null,
        ?User $actor = null,
        ?string $reason = null,
        ?Request $request = null
    ): ?AuditLog {
        try {
            $req = $request ?? (function_exists('request') ? request() : null);

            $ipAddress = $req?->ip();
            $userAgent = $req?->userAgent();
            $requestId = $req?->header('X-Request-ID') ?: (string) Str::uuid();

            $sanitizedBefore = $this->sanitizeState($before);
            $sanitizedAfter  = $this->sanitizeState($after);

            return AuditLog::create([
                'actor_user_id' => $actor?->id,
                'actor_email'   => $actor?->email,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => is_numeric($entityId) ? (int) $entityId : null,
                'before_state'  => $sanitizedBefore,
                'after_state'   => $sanitizedAfter,
                'reason'        => $reason ? Str::limit($reason, 255, '') : null,
                'ip_address'    => $ipAddress,
                'user_agent'    => $userAgent ? Str::limit($userAgent, 1000, '') : null,
                'request_id'    => $requestId,
                'created_at'    => Carbon::now('UTC'),
            ]);
        } catch (Throwable $e) {
            // Fail-safe: Record failure to operational log without halting primary administrative operations.
            // Invariant: Never include raw or sensitive state, query text, or bindings in the error log.
            Log::channel('admin_smtp')->error('Failed to persist audit log entry', [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'actor_id'    => $actor?->id,
                'error_class' => get_class($e),
                'error_code'  => $e->getCode(),
            ]);

            return null;
        }
    }

    /**
     * Authoritative single-point sanitization: recursively sanitizes state snapshots,
     * replacing sensitive keys and cryptographic hash patterns with [REDACTED].
     *
     * @param mixed $state Raw state payload (array, object, or null)
     * @return mixed Sanitized state safe for persistent ledger and operational logs
     */
    public function sanitizeState(mixed $state): mixed
    {
        if ($state === null) {
            return null;
        }

        if (is_object($state)) {
            if (method_exists($state, 'toArray')) {
                $state = $state->toArray();
            } elseif ($state instanceof \JsonSerializable) {
                $state = $state->jsonSerialize();
            } else {
                $state = (array) $state;
            }
        }

        if (!is_array($state)) {
            if (is_string($state) && $this->looksLikePasswordHash($state)) {
                return '[REDACTED]';
            }
            return $state;
        }

        $sanitized = [];

        foreach ($state as $key => $value) {
            $normalizedKey = (string) $key;

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = $this->sanitizeState($value);
            } elseif (is_string($value) && $this->looksLikePasswordHash($value)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Determine whether a key matches any known sensitive credential pattern (case-insensitive).
     */
    public function isSensitiveKey(string $normalizedKey): bool
    {
        $cleaned = strtolower(trim($normalizedKey));
        $normalized = str_replace(['-', ' '], '_', $cleaned);

        // Operational audit flags that are not credentials themselves
        if ($normalized === 'password_reset' || $normalized === 'credential_changed') {
            return false;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect cryptographic password hashes ($6$, $2y$, $2a$, $argon2).
     */
    public function looksLikePasswordHash(string $value): bool
    {
        return str_starts_with($value, '$6$')
            || str_starts_with($value, '$2y$')
            || str_starts_with($value, '$2a$')
            || str_starts_with($value, '$argon2');
    }
}
