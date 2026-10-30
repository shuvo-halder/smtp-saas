<?php

namespace App\Exceptions\MailDelivery;

use Exception;

/**
 * Base exception for permanent unrecoverable delivery failures.
 * Jobs encountering this exception are marked as failed immediately and never retried.
 */
class PermanentDeliveryException extends Exception
{
}
