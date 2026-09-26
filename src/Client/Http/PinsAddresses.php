<?php

declare(strict_types=1);

namespace A2A\Client\Http;

/**
 * An HttpSender that can connect to a given IP address for a request's host
 * (HttpRequest::$pinnedAddress) instead of resolving the host again.
 *
 * Push notifications use it to close the DNS-rebinding window: the webhook
 * host is resolved and checked once (PushUrlValidator::resolve()), and the
 * connection goes to exactly the address that passed.
 */
interface PinsAddresses
{
    /**
     * Whether pinnedAddress is honoured in this environment.
     */
    public function pinsAddresses(): bool;
}
