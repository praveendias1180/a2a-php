<?php

declare(strict_types=1);

namespace A2A\Client\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Wraps whatever HTTP client you have in the right HttpSender.
 *
 * Accepts, in order of preference:
 *  - an HttpSender (used as is)
 *  - a Guzzle client (streams live)
 *  - a native Symfony HttpClient (streams live)
 *  - any other PSR-18 client (SSE arrives buffered, see Psr18HttpSender)
 *  - nothing: Guzzle if installed, else Symfony HttpClient if installed,
 *    else whatever PSR-18 client php-http/discovery finds.
 */
final class HttpSenderFactory
{
    private function __construct() {}

    public static function create(
        ?object $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): HttpSender {
        if ($client instanceof HttpSender) {
            return $client;
        }
        if ($client instanceof \GuzzleHttp\ClientInterface) {
            return new GuzzleHttpSender($client);
        }
        if ($client instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            return new SymfonyHttpSender($client);
        }
        if ($client instanceof ClientInterface) {
            return new Psr18HttpSender(
                $client,
                $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
                $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            );
        }
        if ($client !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported HTTP client %s: pass a PSR-18 client, a Guzzle client, a Symfony HttpClient or an %s.',
                $client::class,
                HttpSender::class,
            ));
        }

        if (class_exists(\GuzzleHttp\Client::class)) {
            return new GuzzleHttpSender(new \GuzzleHttp\Client());
        }
        if (class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            return new SymfonyHttpSender(\Symfony\Component\HttpClient\HttpClient::create());
        }

        return new Psr18HttpSender(
            Psr18ClientDiscovery::find(),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
        );
    }
}
