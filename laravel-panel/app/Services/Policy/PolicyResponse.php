<?php

namespace App\Services\Policy;

class PolicyResponse
{
    public const ACTION_DUNNO = 'DUNNO';
    public const REJECT_QUOTA = 'REJECT 554 5.7.1 Daily outbound recipient quota exceeded';
    public const REJECT_AUTH = 'REJECT 554 5.7.1 Mailbox authorization failed';
    public const REJECT_SUSPENDED = 'REJECT 554 5.7.1 Account is suspended or inactive';
    public const REJECT_INVALID = 'REJECT 554 5.7.1 Invalid policy request';

    public function __construct(
        public readonly string $action,
        public readonly string $status,
        public readonly string $reason,
        public readonly bool $isFailOpen = false
    ) {}

    public static function dunno(string $reason = 'Allowed by policy'): self
    {
        return new self(self::ACTION_DUNNO, 'ALLOWED', $reason);
    }

    public static function failOpen(string $reason = 'Fail-open on backend error'): self
    {
        return new self(self::ACTION_DUNNO, 'FAIL_OPEN', $reason, true);
    }

    public static function quotaExceeded(string $reason = 'Daily outbound recipient quota exceeded'): self
    {
        return new self(self::REJECT_QUOTA, 'REJECTED_QUOTA', $reason);
    }

    public static function authFailed(string $reason = 'Mailbox authorization failed'): self
    {
        return new self(self::REJECT_AUTH, 'REJECTED_AUTH', $reason);
    }

    public static function suspended(string $reason = 'Account or domain is suspended or inactive'): self
    {
        return new self(self::REJECT_SUSPENDED, 'REJECTED_SUSPENDED', $reason);
    }

    public static function invalid(string $reason = 'Invalid request'): self
    {
        return new self(self::REJECT_INVALID, 'REJECTED_INVALID', $reason);
    }

    public function toPostfixResponse(): string
    {
        return "action={$this->action}\n\n";
    }
}
