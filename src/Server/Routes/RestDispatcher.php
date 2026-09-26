<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Compat\V0_3\Rest03Adapter;
use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\Routes\Sse\SseStream;
use A2A\Server\ServerCallContext;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Utils\Constants;
use A2A\Utils\ErrorHandlers;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\ProtoUtils;
use A2A\Utils\VersionValidator;
use Google\Protobuf\Internal\GPBDecodeException;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves the HTTP+JSON (REST) binding under a path prefix:
 *
 *     POST   /message:send
 *     POST   /message:stream                          (SSE)
 *     GET    /tasks
 *     GET    /tasks/{id}
 *     POST   /tasks/{id}:cancel
 *     GET|POST /tasks/{id}:subscribe                  (SSE)
 *     POST|GET /tasks/{id}/pushNotificationConfigs
 *     GET|DELETE /tasks/{id}/pushNotificationConfigs/{configId}
 *     GET    /extendedAgentCard
 *
 * Each path also works behind a tenant segment (`/{tenant}/tasks/...`).
 *
 * A PSR-15 handler that does its own routing, so mount it for every path
 * under the prefix.
 *
 * With $enableV03Compat it also serves the A2A v0.3 binding under the same
 * prefix (`/v1/message:send`, `/v1/tasks/{id}`, ...) through
 * Compat\V0_3\Rest03Adapter. The v0.3 routes are tried first, as Python
 * mounts them first. Off by default, as in Python.
 *
 * Mirrors a2a-python: RestDispatcher + create_rest_routes() in
 * src/a2a/server/routes/rest_dispatcher.py and rest_routes.py.
 */
final class RestDispatcher implements RequestHandlerInterface
{
    private readonly ServerCallContextBuilder $contextBuilder;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly VersionValidator $versionValidator;

    private readonly string $pathPrefix;

    private readonly ?Rest03Adapter $v03Adapter;

    public function __construct(
        private readonly RequestHandler $requestHandler,
        string $pathPrefix = '',
        ?ServerCallContextBuilder $contextBuilder = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        public readonly bool $enableV03Compat = false,
    ) {
        $this->pathPrefix = rtrim($pathPrefix, '/');
        $this->contextBuilder = $contextBuilder ?? new DefaultServerCallContextBuilder();
        $factory = new Psr17Factory();
        $this->responseFactory = $responseFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->versionValidator = new VersionValidator(Constants::PROTOCOL_VERSION_1_0, $this->logger);
        $this->v03Adapter = $enableV03Compat
            ? new Rest03Adapter($requestHandler, $contextBuilder, $this->responseFactory, $this->streamFactory, $this->logger)
            : null;
    }

    /**
     * True when this dispatcher serves the request's path.
     */
    public function matches(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return $this->pathPrefix === '' || $path === $this->pathPrefix || str_starts_with($path, $this->pathPrefix . '/');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        if ($this->pathPrefix !== '' && str_starts_with($path, $this->pathPrefix)) {
            $path = substr($path, strlen($this->pathPrefix));
        }
        $path = '/' . ltrim($path, '/');
        $method = strtoupper($request->getMethod());

        if ($this->v03Adapter !== null && ($v03Route = Rest03Adapter::route($method, $path)) !== null) {
            return $this->v03Adapter->handle($request, $v03Route);
        }

        $route = self::route($method, $path);
        $tenant = '';
        if ($route === null && preg_match('#^/([^/]+)(/.+)$#', $path, $m) === 1) {
            $route = self::route($method, $m[2]);
            $tenant = $m[1];
        }
        if ($route === null) {
            return $this->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Not Found']], 404);
        }

        [$name, $args] = $route;
        $streaming = $name === 'messageStream' || $name === 'subscribe';

        try {
            $context = $this->contextBuilder->build($request);
            if ($tenant !== '') {
                $context->tenant = $tenant;
            }
            $this->versionValidator->validate($context->headers());

            if ($streaming) {
                return $this->stream($name, $request, $args, $context);
            }

            $result = $this->dispatch($name, $request, $args, $context);

            return Common::withActivatedExtensions($this->json($result), $context);
        } catch (\Throwable $e) {
            $this->logError($e);

            return $this->json(ErrorHandlers::buildRestErrorPayload($e), ErrorHandlers::restStatusCode($e));
        }
    }

    /**
     * @param array<string, string> $args
     */
    private function dispatch(string $name, ServerRequestInterface $request, array $args, ServerCallContext $context): mixed
    {
        switch ($name) {
            case 'messageSend':
                $params = $this->parseBody($request, new SendMessageRequest());
                $result = $this->requestHandler->onMessageSend($params, $context);
                $response = $result instanceof Task ? new SendMessageResponse(['task' => $result]) : new SendMessageResponse(['message' => $result]);

                return Common::toJsonValue($response);

            case 'cancel':
                $task = $this->requestHandler->onCancelTask(new CancelTaskRequest(['id' => $args['id']]), $context);

                return Common::toJsonValue($task ?? throw new TaskNotFoundError());

            case 'getTask':
                $params = new GetTaskRequest();
                $this->parseQuery($request, $params);
                $params->setId($args['id']);
                $task = $this->requestHandler->onGetTask($params, $context);

                return Common::toJsonValue($task ?? throw new TaskNotFoundError());

            case 'listTasks':
                $params = new ListTasksRequest();
                $this->parseQuery($request, $params);

                return Common::serializeListTasksResponse($this->requestHandler->onListTasks($params, $context), $params->getIncludeArtifacts());

            case 'createPushConfig':
                $params = $this->parseBody($request, new TaskPushNotificationConfig());
                $params->setTaskId($args['id']);

                return Common::toJsonValue($this->requestHandler->onCreateTaskPushNotificationConfig($params, $context));

            case 'listPushConfigs':
                $params = new ListTaskPushNotificationConfigsRequest();
                $this->parseQuery($request, $params);
                $params->setTaskId($args['id']);

                return Common::toJsonValue($this->requestHandler->onListTaskPushNotificationConfigs($params, $context));

            case 'getPushConfig':
                return Common::toJsonValue($this->requestHandler->onGetTaskPushNotificationConfig(
                    new GetTaskPushNotificationConfigRequest(['task_id' => $args['id'], 'id' => $args['configId']]),
                    $context,
                ));

            case 'deletePushConfig':
                $this->requestHandler->onDeleteTaskPushNotificationConfig(
                    new DeleteTaskPushNotificationConfigRequest(['task_id' => $args['id'], 'id' => $args['configId']]),
                    $context,
                );

                return new \stdClass();

            case 'extendedCard':
                return Common::toJsonValue($this->requestHandler->onGetExtendedAgentCard(new GetExtendedAgentCardRequest(), $context));
        }

        throw new \LogicException("Unknown route {$name}");
    }

    /**
     * @param array<string, string> $args
     */
    private function stream(string $name, ServerRequestInterface $request, array $args, ServerCallContext $context): ResponseInterface
    {
        $stream = $name === 'messageStream'
            ? $this->requestHandler->onMessageSendStream($this->parseBody($request, new SendMessageRequest()), $context)
            : $this->requestHandler->onSubscribeToTask(new SubscribeToTaskRequest(['id' => $args['id']]), $context);

        // Setup errors (unknown task, streaming not supported) happen here
        // and come back as a normal JSON error response.
        $stream->current();

        $chunks = function () use ($stream): \Generator {
            try {
                while ($stream->valid()) {
                    $event = $stream->current();
                    yield $event === null
                        ? SseStream::keepAlive()
                        : SseStream::event(Common::encode(Common::toJsonValue(ProtoUtils::toStreamResponse($event))));
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
            ->withBody(new SseStream($chunks(), drainOnDisconnect: $name === 'messageStream')), $context);
    }

    /**
     * @return array{string, array<string, string>}|null
     */
    private static function route(string $method, string $path): ?array
    {
        $routes = [
            ['POST', '#^/message:send$#', 'messageSend'],
            ['POST', '#^/message:stream$#', 'messageStream'],
            ['POST', '#^/tasks/(?<id>[^/]+):cancel$#', 'cancel'],
            ['GET', '#^/tasks/(?<id>[^/]+):subscribe$#', 'subscribe'],
            ['POST', '#^/tasks/(?<id>[^/]+):subscribe$#', 'subscribe'],
            ['GET', '#^/tasks/(?<id>[^/]+)/pushNotificationConfigs/(?<configId>[^/]+)$#', 'getPushConfig'],
            ['DELETE', '#^/tasks/(?<id>[^/]+)/pushNotificationConfigs/(?<configId>[^/]+)$#', 'deletePushConfig'],
            ['POST', '#^/tasks/(?<id>[^/]+)/pushNotificationConfigs$#', 'createPushConfig'],
            ['GET', '#^/tasks/(?<id>[^/]+)/pushNotificationConfigs$#', 'listPushConfigs'],
            ['GET', '#^/tasks/(?<id>[^/:]+)$#', 'getTask'],
            ['GET', '#^/tasks$#', 'listTasks'],
            ['GET', '#^/extendedAgentCard$#', 'extendedCard'],
        ];

        foreach ($routes as [$routeMethod, $pattern, $name]) {
            if ($routeMethod === $method && preg_match($pattern, $path, $m) === 1) {
                return [$name, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }

        return null;
    }

    /**
     * @template T of ProtobufMessage
     *
     * @param T $message
     *
     * @return T
     */
    private function parseBody(ServerRequestInterface $request, ProtobufMessage $message): ProtobufMessage
    {
        $body = (string) $request->getBody();
        try {
            // Unknown fields are ignored for forward compatibility
            // (DM-SERIAL-005); Python rejects them.
            $message->mergeFromJsonString($body === '' ? '{}' : $body, true);
        } catch (GPBDecodeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GPBDecodeException($e->getMessage(), 0, $e instanceof \Exception ? $e : null);
        }

        return $message;
    }

    private function parseQuery(ServerRequestInterface $request, ProtobufMessage $message): void
    {
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            ProtoUtils::parseParams($query, $message);
        }
    }

    private function logError(\Throwable $error): void
    {
        if ($error instanceof A2AError && ErrorHandlers::restStatusCode($error) < 500) {
            $this->logger->warning('REST request failed: {message}', ['message' => $error->getMessage()]);

            return;
        }
        if ($error instanceof GPBDecodeException) {
            $this->logger->warning('REST request body could not be parsed: {message}', ['message' => $error->getMessage()]);

            return;
        }
        $this->logger->error('REST request failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
    }

    private function json(mixed $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(Common::encode($payload)));
    }
}
