<?php

declare(strict_types=1);

namespace A2A\Utils\Errors;

/**
 * Exception raised when push notifications are not supported.
 */
class PushNotificationNotSupportedError extends A2AError
{
    public const DEFAULT_MESSAGE = 'Push Notification is not supported';
}
