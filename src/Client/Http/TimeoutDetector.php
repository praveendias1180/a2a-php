<?php

declare(strict_types=1);

namespace A2A\Client\Http;

/**
 * Tells a timeout apart from other network failures.
 *
 * httpx raises a dedicated TimeoutException; PHP clients mostly don't, so
 * this looks at the exception message (cURL error 28, "timed out", ...).
 *
 * @internal
 */
final class TimeoutDetector
{
    private function __construct() {}

    public static function isTimeout(\Throwable $e): bool
    {
        if ($e instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }
}
