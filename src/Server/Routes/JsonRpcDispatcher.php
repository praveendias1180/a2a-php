<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

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
use A2A\Types\Message;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Constants;
use A2A\Utils\ErrorHandlers;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\InvalidRequestError;
use A2A\Utils\Errors\JSONParseError;
use A2A\Utils\Errors\MethodNotFoundError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\Errors\UnsupportedOperationError;
use A2A\Utils\ProtoUtils;
use A2A\Utils\VersionValidator;
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
 * Serves the JSON-RPC 2.0 binding: POST a request object, get a JSON
 * response, or an SSE stream for SendStreamingMessage and SubscribeToTask.
 *
 * A PSR-15 handler, so any framework or router can mount it at the path the
 * Agent Card declares.
 *
 * Mirrors a2a-python: JsonRpcDispatcher in
 * src/a2a/server/routes/jsonrpc_dispatcher.py. Differences: exceptions that
 * are not A2A errors return a generic "Internal error" (the original goes
 * to the logger), and the v0.3 compat adapter is not here yet (phase 6).
 */
final class JsonRpcDispatcher implements RequestHandlerInterface
{
    /** @var array<string, class-string<ProtobufMessage>> */
    public const METHOD_TO_MODEL = [
        'SendMessage' => SendMessageRequest::class,
        'SendStreamingMessage' => SendMessageRequest::class,
        'GetTask' => GetTaskRequest::class,
        'ListTasks' => ListTasksRequest::class,
        'CancelTask' => CancelTaskRequest::class,
        'CreateTaskPushNotificationConfig' => TaskPushNotificationConfig::class,
        'GetTaskPushNotificationConfig' => GetTaskPushNotificationConfigRequest::class,
        'ListTaskPushNotificationConfigs' => ListTaskPushNotificationConfigsRequest::class,
        'DeleteTaskPushNotificationConfig' => DeleteTaskPushNotificationConfigRequest::class,
        'SubscribeToTask' => SubscribeToTaskRequest::class,
        'GetExtendedAgentCard' => GetExtendedAgentCardRequest::class,
    ];

    private readonly ServerCallContextBuilder $contextBuilder;

    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly VersionValidator $versionValidator;

    public function __construct(
        private readonly RequestHandler $requestHandler,
        ?ServerCallContextBuilder $contextBuilder = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->contextBuilder = $contextBuilder ?? new DefaultServerCallContextBuilder();
        $factory = ($responseFactory === null || $streamFactory === null) ? new Psr17Factory() : null;
        $this->responseFactory = $responseFactory ?? $factory ?? new Psr17Factory();
        $this->streamFactory = $streamFactory ?? $factory ?? new Psr17Factory();
        $this->versionValidator = new VersionValidator(Constants::PROTOCOL_VERSION_1_0, $this->logger);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'JSON-RPC requests must use POST']], 405)
                ->withHeader('Allow', 'POST');
        }

        $requestId = null;
        try {
            $raw = (string) $request->getBody();
            try {
                $body = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $this->errorResponse(null, new JSONParseError($e->getMessage()));
            }

            if (is_array($body)) {
                return $this->errorResponse(null, new InvalidRequestError('Batch requests are not supported'));
            }
            if (!$body instanceof \stdClass) {
                return $this->errorResponse(null, new InvalidRequestError('Invalid request: the body must be a JSON object'));
            }

            $id = $body->id ?? null;
            if ($id !== null && !is_string($id) && !is_int($id)) {
                return $this->errorResponse(null, new InvalidRequestError('Invalid request: id must be a string, a number or null'));
            }
            $requestId = $id;

            if (($body->jsonrpc ?? null) !== '2.0') {
                return $this->errorResponse($requestId, new InvalidRequestError("Invalid request: 'jsonrpc' must be exactly '2.0'"));
            }
            $method = $body->method ?? null;
            if (!is_string($method) || $method === '') {
                return $this->errorResponse($requestId, new InvalidRequestError('Method is required'));
            }
            $params = $body->params ?? new \stdClass();
            if (!$params instanceof \stdClass && !is_array($params)) {
                return $this->errorResponse($requestId, new InvalidRequestError('Invalid request: params must be an object'));
            }

            $modelClass = self::METHOD_TO_MODEL[$method] ?? null;
            if ($modelClass === null) {
                return $this->errorResponse($requestId, new MethodNotFoundError());
            }

            try {
                $specific = new $modelClass();
                // Unknown fields are ignored so newer clients keep working
                // (the spec's forward-compatibility SHOULD, DM-SERIAL-005).
                // Python rejects them.
                $specific->mergeFromJsonString(Common::encode($params), true);
            } catch (\Throwable $e) {
                return $this->errorResponse($requestId, new InvalidParamsError('Invalid params: ' . $e->getMessage()));
            }

            $context = $this->contextBuilder->build($request);
            $context->tenant = $specific->getTenant();
            $context->state['method'] = $method;
            $context->state['request_id'] = $requestId;

            if ($method === 'SendStreamingMessage' || $method === 'SubscribeToTask') {
                return $this->processStreamingRequest($requestId, $specific, $context);
            }

            try {
                $result = $this->processNonStreamingRequest($specific, $context);
            } catch (A2AError $e) {
                return $this->errorResponse($requestId, $e);
            }

            return Common::withActivatedExtensions($this->json(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => $result]), $context);
        } catch (A2AError $e) {
            return $this->errorResponse($requestId, $e);
        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, $e);
        }
    }

    private function processStreamingRequest(string|int|null $requestId, ProtobufMessage $params, ServerCallContext $context): ResponseInterface
    {
        $this->versionValidator->validate($context->headers());
        $method = $context->state['method'] ?? null;

        $stream = match (true) {
            $method === 'SendStreamingMessage' && $params instanceof SendMessageRequest => $this->requestHandler->onMessageSendStream($params, $context),
            $method === 'SubscribeToTask' && $params instanceof SubscribeToTaskRequest => $this->requestHandler->onSubscribeToTask($params, $context),
            default => throw new UnsupportedOperationError('Stream not supported'),
        };

        // Pull the first event before answering, so setup errors (unknown
        // task, unsupported operation) come back as a plain JSON error.
        $stream->current();

        $chunks = function () use ($stream, $requestId): \Generator {
            try {
                while ($stream->valid()) {
                    $event = $stream->current();
                    yield $event === null ? SseStream::keepAlive() : SseStream::event(Common::encode($this->streamEnvelope($requestId, $event)));
                    $stream->next();
                }
            } catch (\Throwable $e) {
                $this->logError($e, $requestId);
                yield SseStream::event(Common::encode(self::errorEnvelope($requestId, $e)), 'error');
            }
        };

        return Common::withActivatedExtensions($this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new SseStream($chunks(), drainOnDisconnect: $method === 'SendStreamingMessage')), $context);
    }

    private function processNonStreamingRequest(ProtobufMessage $params, ServerCallContext $context): mixed
    {
        $this->versionValidator->validate($context->headers());

        return match (true) {
            $params instanceof SendMessageRequest => $this->sendMessage($params, $context),
            $params instanceof CancelTaskRequest => Common::toJsonValue($this->requestHandler->onCancelTask($params, $context) ?? throw new TaskNotFoundError()),
            $params instanceof GetTaskRequest => Common::toJsonValue($this->requestHandler->onGetTask($params, $context) ?? throw new TaskNotFoundError()),
            $params instanceof ListTasksRequest => Common::serializeListTasksResponse($this->requestHandler->onListTasks($params, $context), $params->getIncludeArtifacts()),
            $params instanceof TaskPushNotificationConfig => Common::toJsonValue($this->requestHandler->onCreateTaskPushNotificationConfig($params, $context)),
            $params instanceof GetTaskPushNotificationConfigRequest => Common::toJsonValue($this->requestHandler->onGetTaskPushNotificationConfig($params, $context)),
            $params instanceof ListTaskPushNotificationConfigsRequest => Common::toJsonValue($this->requestHandler->onListTaskPushNotificationConfigs($params, $context)),
            $params instanceof DeleteTaskPushNotificationConfigRequest => $this->deletePushConfig($params, $context),
            $params instanceof GetExtendedAgentCardRequest => Common::toJsonValue($this->requestHandler->onGetExtendedAgentCard($params, $context)),
            default => throw new UnsupportedOperationError(sprintf('Method %s is not supported.', is_string($context->state['method'] ?? null) ? $context->state['method'] : '?')),
        };
    }

    private function sendMessage(SendMessageRequest $params, ServerCallContext $context): mixed
    {
        $result = $this->requestHandler->onMessageSend($params, $context);
        $response = $result instanceof Task ? new SendMessageResponse(['task' => $result]) : new SendMessageResponse(['message' => $result]);

        return Common::toJsonValue($response);
    }

    private function deletePushConfig(DeleteTaskPushNotificationConfigRequest $params, ServerCallContext $context): mixed
    {
        $this->requestHandler->onDeleteTaskPushNotificationConfig($params, $context);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function streamEnvelope(string|int|null $requestId, Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): array
    {
        return ['jsonrpc' => '2.0', 'id' => $requestId, 'result' => Common::toJsonValue(ProtoUtils::toStreamResponse($event))];
    }

    private function errorResponse(string|int|null $requestId, \Throwable $error): ResponseInterface
    {
        $this->logError($error, $requestId);

        return $this->json(self::errorEnvelope($requestId, $error));
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorEnvelope(string|int|null $requestId, \Throwable $error): array
    {
        return ['jsonrpc' => '2.0', 'id' => $requestId, 'error' => ErrorHandlers::buildJsonRpcError($error)];
    }

    private function logError(\Throwable $error, string|int|null $requestId): void
    {
        if ($error instanceof A2AError && $error->jsonRpcCode() !== null && $error->jsonRpcCode() !== -32603) {
            $this->logger->warning('JSON-RPC request {id} failed: {message}', ['id' => $requestId, 'message' => $error->getMessage()]);

            return;
        }
        $this->logger->error('JSON-RPC request {id} failed: {message}', ['id' => $requestId, 'message' => $error->getMessage(), 'exception' => $error]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(Common::encode($payload)));
    }
}
