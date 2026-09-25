<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A tiny PSR-15 router for plain-PHP servers: exact paths for the Agent
 * Card and JSON-RPC, a prefix for REST. Frameworks should use their own
 * router and mount the handlers from Routes instead.
 *
 * PHP-specific; Python mounts Starlette routes.
 */
final class Router implements RequestHandlerInterface
{
    /** @var array<string, RequestHandlerInterface> */
    private array $exact = [];

    /** @var list<RestDispatcher> */
    private array $rest = [];

    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct(?ResponseFactoryInterface $responseFactory = null)
    {
        $this->responseFactory = $responseFactory ?? new Psr17Factory();
    }

    public function add(string $path, RequestHandlerInterface $handler): self
    {
        $this->exact[$path] = $handler;

        return $this;
    }

    public function addRest(RestDispatcher $dispatcher): self
    {
        $this->rest[] = $dispatcher;

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '') {
            $path = '/';
        }
        if (isset($this->exact[$path])) {
            return $this->exact[$path]->handle($request);
        }
        foreach ($this->rest as $dispatcher) {
            if ($dispatcher->matches($request)) {
                return $dispatcher->handle($request);
            }
        }

        $response = $this->responseFactory->createResponse(404)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(Common::encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Not Found']]));

        return $response;
    }
}
