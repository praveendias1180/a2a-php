<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\Routes\Common;
use A2A\Server\Routes\DefaultServerCallContextBuilder;
use A2A\Server\Routes\ServerCallContextBuilder;
use A2A\Server\Routes\Sse\SseStream;
use A2A\Server\ServerCallContext;
use A2A\Utils\Constants;
use A2A\Utils\ErrorHandlers;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\VersionValidator;
use Google\Protobuf\Internal\GPBDecodeException;
use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves the v0.3 HTTP+JSON binding (`/v1/message:send`, `/v1/tasks/{id}`,
 * `/v1/card`, ...) with a v1.0 RequestHandler. The RestDispatcher hands it
 * the requests whose path (after its prefix) starts with `/v1/`; the v1.0
 * routes never use that segment, so there is no clash.
 *
 * Errors use the v1.0 REST error body (google.rpc.Status + ErrorInfo), as
 * Python's compat adapter does.
 *
 * Mirrors a2a-python: REST03Adapter in src/a2a/compat/v0_3/rest_adapter.py.
 * ListTasks is not served: it is not in the v0.3 spec (Python leaves it out
 * too).
 */
final class Rest03Adapter
{
    public readonly Rest03Handler $handler;

    private readonly ServerCallContextBuilder $contextBuilder;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly VersionValidator $versionValidator;

    public function __construct(
        RequestHandler $httpHandler,
        ?ServerCallContextBuilder $contextBuilder = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->handler = new Rest03Handler($httpHandler);
        $this->contextBuilder = new V03ServerCallContextBuilder($contextBuilder ?? new DefaultServerCallContextBuilder());
        $factory = new Psr17Factory();
        $this->responseFactory = $responseFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->versionValidator = new VersionValidator(Constants::PROTOCOL_VERSION_0_3, $this->logger);
    }

    /**
     * The route for $method + $path (the path under the REST prefix), or
     * null when it is not a v0.3 route.
     *
     * @return array{string, array<string, string>}|null
     */
    public static function route(string $method, string $path): ?array
    {
        $routes = [
            ['POST', '#^/v1/message:send$#', 'messageSend'],
            ['POST', '#^/v1/message:stream$#', 'messageStream'],
            ['POST', '#^/v1/tasks/(?<id>[^/]+):cancel$#', 'cancel'],
            ['GET', '#^/v1/tasks/(?<id>[^/]+):subscribe$#', 'subscribe'],
            ['POST', '#^/v1/tasks/(?<id>[^/]+):subscribe$#', 'subscribe'],
            ['GET', '#^/v1/tasks/(?<id>[^/]+)/pushNotificationConfigs/(?<pushId>[^/]+)$#', 'getPushConfig'],
            ['DELETE', '#^/v1/tasks/(?<id>[^/]+)/pushNotificationConfigs/(?<pushId>[^/]+)$#', 'deletePushConfig'],
            ['POST', '#^/v1/tasks/(?<id>[^/]+)/pushNotificationConfigs$#', 'setPushConfig'],
            ['GET', '#^/v1/tasks/(?<id>[^/]+)/pushNotificationConfigs$#', 'listPushConfigs'],
            ['GET', '#^/v1/tasks/(?<id>[^/:]+)$#', 'getTask'],
            ['GET', '#^/v1/card$#', 'card'],
        ];
        foreach ($routes as [$routeMethod, $pattern, $name]) {
            if ($routeMethod === $method && preg_match($pattern, $path, $m) === 1) {
                return [$name, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        return null;
    }

    /**
     * True when $path (under the REST prefix) is a v0.3 path, even if the
     * method is wrong (the dispatcher answers 405 then, not the v1.0 404).
     */
    public static function isV03Path(string $path): bool
    {
        return str_starts_with($path, '/v1/');
    }

    /**
     * @param array{string, array<string, string>} $route from route()
     */
    public function handle(ServerRequestInterface $request, array $route): ResponseInterface
    {
        [$name, $args] = $route;
        $taskId = $args['id'] ?? '';

        try {
            $context = $this->contextBuilder->build($request);
            $this->versionValidator->validate($context->headers());

            if ($name === 'messageStream' || $name === 'subscribe') {
                $stream = $name === 'messageStream'
                    ? $this->handler->onMessageSendStream($request, $context)
                    : $this->handler->onSubscribeToTask($taskId, $context);

                return $this->stream($stream, $context, drainOnDisconnect: $name === 'messageStream');
            }

            $result = match ($name) {
                'messageSend' => $this->handler->onMessageSend($request, $context),
                'cancel' => $this->handler->onCancelTask($taskId, $context),
                'getPushConfig' => $this->handler->getPushNotification($taskId, $args['pushId'] ?? '', $context),
                'deletePushConfig' => $this->handler->deletePushNotification($taskId, $args['pushId'] ?? '', $context),
                'setPushConfig' => $this->handler->setPushNotification($request, $taskId, $context),
                'listPushConfigs' => $this->handler->listPushNotifications($taskId, $context),
                'getTask' => $this->handler->onGetTask($request, $taskId, $context),
                'card' => $this->handler->onGetExtendedAgentCard($context),
                default => throw new \LogicException("Unknown route {$name}"),
            };

            return Common::withActivatedExtensions($this->json($result), $context);
        } catch (\Throwable $e) {
            $this->logError($e);

            return $this->json(ErrorHandlers::buildRestErrorPayload($e), ErrorHandlers::restStatusCode($e));
        }
    }

    /**
     * @param \Generator<int, mixed> $stream
     */
    private function stream(\Generator $stream, ServerCallContext $context, bool $drainOnDisconnect): ResponseInterface
    {
        // Setup errors (unknown task, streaming unsupported) happen on the
        // first event and come back as a normal JSON error response.
        $stream->current();

        $chunks = function () use ($stream): \Generator {
            try {
                while ($stream->valid()) {
                    $event = $stream->current();
                    yield $event === null ? SseStream::keepAlive() : SseStream::event(Common::encode($event));
                    $stream->next();
                }
            } catch (\Throwable $e) {
                $this->logError($e);
                yield SseStream::event(Common::encode(ErrorHandlers::buildRestErrorPayload($e)), 'error');
            }
        };

        return Common::withActivatedExtensions($this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new SseStream($chunks(), drainOnDisconnect: $drainOnDisconnect)), $context);
    }

    private function logError(\Throwable $error): void
    {
        if (($error instanceof A2AError && ErrorHandlers::restStatusCode($error) < 500) || $error instanceof GPBDecodeException) {
            $this->logger->warning('v0.3 REST request failed: {message}', ['message' => $error->getMessage()]);

            return;
        }
        $this->logger->error('v0.3 REST request failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
    }

    private function json(mixed $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(Common::encode($payload)));
    }
}
