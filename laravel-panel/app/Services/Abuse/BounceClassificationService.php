<?php

namespace App\Services\Abuse;

class BounceClassificationService
{
    public const CLASSIFICATION_SUCCESS = 'SUCCESS';
    public const CLASSIFICATION_HARD_BOUNCE = 'HARD_BOUNCE';
    public const CLASSIFICATION_SOFT_BOUNCE = 'SOFT_BOUNCE';
    public const CLASSIFICATION_UNKNOWN = 'UNKNOWN';

    /**
     * Classify a normalized delivery event deterministically.
     */
    public function classify(NormalizedMailEvent $event): string
    {
        return $this->classifyFields(
            status: $event->status,
            dsn: $event->dsn,
            smtpCode: $event->smtpCode
        );
    }

    /**
     * Classify raw delivery status fields.
     */
    public function classifyFields(?string $status, ?string $dsn, ?int $smtpCode): string
    {
        $status = $status !== null ? strtolower(trim($status)) : null;
        $dsn = $dsn !== null ? trim($dsn) : null;

        if (empty($status)) {
            return self::CLASSIFICATION_UNKNOWN;
        }

        // 1. Success Classification
        if ($status === 'sent') {
            if ($dsn === null || str_starts_with($dsn, '2.')) {
                return self::CLASSIFICATION_SUCCESS;
            }
            // Unexpected DSN with status=sent
            return self::CLASSIFICATION_UNKNOWN;
        }

        // 2. Soft Bounce (Deferred)
        if ($status === 'deferred') {
            return self::CLASSIFICATION_SOFT_BOUNCE;
        }

        // 3. Status is 'bounced' or 'expired'
        if ($status === 'bounced' || $status === 'expired') {
            // Check DSN 5.x.x -> Hard Bounce
            if ($dsn !== null && str_starts_with($dsn, '5.')) {
                return self::CLASSIFICATION_HARD_BOUNCE;
            }

            // Check DSN 4.x.x -> Soft Bounce (e.g. queue time limit exceeded on deferred mail)
            if ($dsn !== null && str_starts_with($dsn, '4.')) {
                return self::CLASSIFICATION_SOFT_BOUNCE;
            }

            // If DSN is missing, fallback to SMTP status code
            if ($smtpCode !== null) {
                if ($smtpCode >= 500 && $smtpCode <= 599) {
                    return self::CLASSIFICATION_HARD_BOUNCE;
                }
                if ($smtpCode >= 400 && $smtpCode <= 499) {
                    return self::CLASSIFICATION_SOFT_BOUNCE;
                }
            }

            // Default 'bounced' without DSN or SMTP code is treated as Hard Bounce
            // per Postfix convention where status=bounced indicates permanent non-delivery
            if ($dsn === null && $smtpCode === null) {
                return self::CLASSIFICATION_HARD_BOUNCE;
            }

            return self::CLASSIFICATION_UNKNOWN;
        }

        return self::CLASSIFICATION_UNKNOWN;
    }

    public function isHardBounce(string $classification): bool
    {
        return $classification === self::CLASSIFICATION_HARD_BOUNCE;
    }

    public function isSoftBounce(string $classification): bool
    {
        return $classification === self::CLASSIFICATION_SOFT_BOUNCE;
    }

    public function isSuccess(string $classification): bool
    {
        return $classification === self::CLASSIFICATION_SUCCESS;
    }
}
