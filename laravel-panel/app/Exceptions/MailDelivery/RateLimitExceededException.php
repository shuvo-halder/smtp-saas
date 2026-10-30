<?php

namespace App\Exceptions\MailDelivery;

/**
 * Thrown when a tenant exceeds the allowed transactional mail enqueue rate limit.
 */
class RateLimitExceededException extends TransientDeliveryException
{
}
