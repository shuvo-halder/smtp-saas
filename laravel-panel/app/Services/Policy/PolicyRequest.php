<?php

namespace App\Services\Policy;

class PolicyRequest
{
    public function __construct(
        public readonly array $rawAttributes = [],
        public readonly ?string $request = null,
        public readonly ?string $protocolState = null,
        public readonly ?string $protocolName = null,
        public readonly ?string $clientAddress = null,
        public readonly ?string $clientName = null,
        public readonly ?string $heloName = null,
        public readonly ?string $sender = null,
        public readonly ?string $recipient = null,
        public readonly int $recipientCount = 1,
        public readonly ?string $queueId = null,
        public readonly ?string $instance = null,
        public readonly ?string $saslMethod = null,
        public readonly ?string $saslUsername = null,
        public readonly ?string $saslSender = null,
    ) {}

    public static function fromAttributes(array $attributes): self
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $normalized[strtolower(trim($key))] = trim((string)$value);
        }

        $recipientCount = 1;
        if (isset($normalized['recipient_count'])) {
            $recipientCount = is_numeric($normalized['recipient_count']) ? (int)$normalized['recipient_count'] : 0;
        }

        return new self(
            rawAttributes: $attributes,
            request: $normalized['request'] ?? null,
            protocolState: $normalized['protocol_state'] ?? null,
            protocolName: $normalized['protocol_name'] ?? null,
            clientAddress: $normalized['client_address'] ?? null,
            clientName: $normalized['client_name'] ?? null,
            heloName: $normalized['helo_name'] ?? null,
            sender: $normalized['sender'] ?? null,
            recipient: $normalized['recipient'] ?? null,
            recipientCount: $recipientCount,
            queueId: $normalized['queue_id'] ?? null,
            instance: $normalized['instance'] ?? null,
            saslMethod: $normalized['sasl_method'] ?? null,
            saslUsername: !empty($normalized['sasl_username']) ? strtolower($normalized['sasl_username']) : null,
            saslSender: $normalized['sasl_sender'] ?? null,
        );
    }

    public function isAuthenticated(): bool
    {
        return !empty($this->saslUsername);
    }
}
