<?php

namespace App\Exceptions\MailDelivery;

/**
 * Thrown when an invalid delivery state transition or illegal concurrent mutation is attempted.
 */
class InvalidDeliveryStateException extends PermanentDeliveryException
{
}
