<?php

namespace App\Services\Abuse;

use App\Models\Mailbox;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class AbuseAttributionService
{
    public const TYPE_AUTHENTICATED_MAILBOX = 'AUTHENTICATED_MAILBOX';
    public const TYPE_DOMAIN_ONLY = 'DOMAIN_ONLY';
    public const TYPE_UNKNOWN_SYSTEM = 'UNKNOWN_SYSTEM';

    /**
     * Resolves envelope sender email into verified database models.
     *
     * Attribution chain:
     * sender email -> Mailbox -> Domain -> User (Tenant)
     *
     * @param string|null $senderEmail
     * @return array{
     *     type: string,
     *     tenant_id: ?int,
     *     mailbox_id: ?int,
     *     domain_id: ?int,
     *     tenant: ?User,
     *     mailbox: ?Mailbox,
     *     domain: ?Domain,
     *     sender: ?string
     * }
     */
    public function attribute(?string $senderEmail): array
    {
        $default = [
            'type' => self::TYPE_UNKNOWN_SYSTEM,
            'tenant_id' => null,
            'mailbox_id' => null,
            'domain_id' => null,
            'tenant' => null,
            'mailbox' => null,
            'domain' => null,
            'sender' => $senderEmail,
        ];

        if (empty($senderEmail) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            return $default;
        }

        try {
            // 1. Resolve exact Mailbox
            $mailbox = Mailbox::with(['domain.user'])
                ->where('email', $senderEmail)
                ->first();

            if ($mailbox && $mailbox->domain && $mailbox->domain->user) {
                return [
                    'type' => self::TYPE_AUTHENTICATED_MAILBOX,
                    'tenant_id' => (int) $mailbox->domain->user->id,
                    'mailbox_id' => (int) $mailbox->id,
                    'domain_id' => (int) $mailbox->domain->id,
                    'tenant' => $mailbox->domain->user,
                    'mailbox' => $mailbox,
                    'domain' => $mailbox->domain,
                    'sender' => $senderEmail,
                ];
            }

            // 2. Mailbox not found; check if domain belongs to a tenant
            $parts = explode('@', $senderEmail, 2);
            $domainPart = $parts[1] ?? '';

            if (!empty($domainPart)) {
                $domain = Domain::with('user')
                    ->where('domain_name', $domainPart)
                    ->first();

                if ($domain && $domain->user) {
                    return [
                        'type' => self::TYPE_DOMAIN_ONLY,
                        'tenant_id' => (int) $domain->user->id,
                        'mailbox_id' => null,
                        'domain_id' => (int) $domain->id,
                        'tenant' => $domain->user,
                        'mailbox' => null,
                        'domain' => $domain,
                        'sender' => $senderEmail,
                    ];
                }
            }

            return $default;

        } catch (Throwable $e) {
            Log::channel('abuse')->error('AbuseAttribution: Database error during sender attribution', [
                'sender' => $senderEmail,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Determines whether the attribution represents a fully authenticated tenant mailbox.
     */
    public function isValidTenantMailbox(array $attribution): bool
    {
        return $attribution['type'] === self::TYPE_AUTHENTICATED_MAILBOX
            && !empty($attribution['tenant_id'])
            && !empty($attribution['mailbox_id']);
    }
}
