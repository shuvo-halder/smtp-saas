<?php

namespace App\Exceptions\MailDelivery;

use Exception;

/**
 * Base exception for transient, retryable delivery failures (e.g. timeout, rate limit).
 * Jobs encountering this exception are released back into the queue with exponential backoff.
 */
class TransientDeliveryException extends Exception
{
}
