<?php

namespace App\Services\Abuse;

/**
 * Normalized representation of a parsed Postfix syslog event.
 *
 * Immutable value object holding only fields explicitly extracted from the log.
 * Missing fields remain null to prevent false data fabrication.
 */
class NormalizedMailEvent
{
    public const TYPE_QMGR_FROM = 'QMGR_FROM';
    public const TYPE_DELIVERY_STATUS = 'DELIVERY_STATUS';
    public const TYPE_INTERMEDIATE_FILTER_HANDOFF = 'INTERMEDIATE_FILTER_HANDOFF';
    public const TYPE_SUBMISSION = 'SUBMISSION';
    public const TYPE_BOUNCE_NOTICE = 'BOUNCE_NOTICE';
    public const TYPE_OTHER = 'OTHER';

    public function __construct(
        public readonly ?string $timestamp = null,
        public readonly ?string $queueId = null,
        public readonly ?string $daemon = null,
        public readonly string $eventType = self::TYPE_OTHER,
        public readonly ?string $sender = null,
        public readonly ?string $recipient = null,
        public readonly ?string $dsn = null,
        public readonly ?string $status = null,
        public readonly ?int $smtpCode = null,
        public readonly ?string $message = null,
        public readonly ?string $rawLine = null,
        public readonly ?string $reinjectedQueueId = null,
    ) {}

    public function isDeliveryEvent(): bool
    {
        return $this->eventType === self::TYPE_DELIVERY_STATUS && !empty($this->status);
    }

    public function isIntermediateFilterEvent(): bool
    {
        return $this->eventType === self::TYPE_INTERMEDIATE_FILTER_HANDOFF;
    }

    public function isQmgrSenderEvent(): bool
    {
        return $this->eventType === self::TYPE_QMGR_FROM && !empty($this->sender) && !empty($this->queueId);
    }
}

