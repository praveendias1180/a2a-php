<?php

declare(strict_types=1);

namespace A2A\Client\Http;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Errors\A2AClientTimeoutError;

/**
 * Sends HTTP requests for the client transports.
 *
 * Python's transports take an httpx.AsyncClient, which can both buffer and
 * stream. PSR-18 can only buffer (sendRequest() returns once the whole body
 * is there), which would make SSE arrive all at once at the end. So the SDK
 * sends through this small interface, with one implementation per kind of
 * client: Guzzle and Symfony HttpClient stream for real; any other PSR-18
 * client falls back to reading the buffered body. HttpSenderFactory picks
 * the right one.
 *
 * Implementations never throw for HTTP error statuses (4xx/5xx come back as
 * responses); they throw only for transport failures.
 */
interface HttpSender
{
    /**
     * @throws A2AClientTimeoutError when the request times out
     * @throws A2AClientError        on any other network failure
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * @throws A2AClientTimeoutError when the request times out
     * @throws A2AClientError        on any other network failure
     */
    public function stream(HttpRequest $request): StreamedResponse;

    /**
     * True when stream() delivers chunks as they arrive rather than after the
     * whole body has been received.
     */
    public function supportsIncrementalStreaming(): bool;
}
