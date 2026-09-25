<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\Routes\Sse\SseStream;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response from plain PHP (php -S, PHP-FPM), then runs any
 * work that continues after the response.
 *
 * - SSE bodies are flushed event by event, output buffering is turned off
 *   and a client disconnect is noticed at the next write.
 * - Other bodies get a Content-Length and are flushed in one go, so the
 *   client has its answer before background work starts
 *   (fastcgi_finish_request() closes the connection under PHP-FPM).
 *
 * Frameworks with their own emitter can use it too, or call
 * RequestHandler::runBackgroundWork() after sending the response.
 *
 * PHP-specific; Python's ASGI server does this job.
 */
final class ResponseEmitter
{
    public function __construct(private readonly ?RequestHandler $requestHandler = null) {}

    public function emit(ResponseInterface $response): void
    {
        $body = $response->getBody();
        $streaming = $body instanceof SseStream;

        if (!headers_sent()) {
            header(sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase()), true, $response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $index => $value) {
                    header(sprintf('%s: %s', $name, $value), $index === 0);
                }
            }
        }

        if ($streaming) {
            ignore_user_abort(true);
            set_time_limit(0);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            foreach ($body->chunks() as $chunk) {
                echo $chunk;
                flush();
                if (connection_aborted() !== 0) {
                    $body->clientDisconnected();
                    break;
                }
            }
        } else {
            $content = (string) $body;
            if (!headers_sent()) {
                header('Content-Length: ' . strlen($content));
            }
            echo $content;
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        }

        $this->requestHandler?->runBackgroundWork();
    }
}
