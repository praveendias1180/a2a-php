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
use A2A\Utils\Errors\InvalidRequestError;
use A2A\Utils\VersionValidator;
use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves the v0.3 JSON-RPC methods (`message/send`, `tasks/get`, ...) with a
 * v1.0 RequestHandler, on the same endpoint as the v1.0 methods. The v0.3
 * method names never clash with the v1.0 ones, so the JsonRpcDispatcher
 * routes by method name.
 *
 * Mirrors a2a-python: JSONRPC03Adapter in src/a2a/compat/v0_3/jsonrpc_adapter.py.
 * Differences: A2A errors keep their own JSON-RPC code (-32001 task not
 * found, ...) where Python answers every A2AError with -32603 and its
 * message, and internal errors return a generic message (logged here).
 */
final class JsonRpc03Adapter
{
    /** The v0.3 methods; the value says whether the method streams. */
    public const METHODS = [
        'message/send' => false,
        'message/stream' => true,
        'tasks/get' => false,
        'tasks/cancel' => false,
        'tasks/pushNotificationConfig/set' => false,
        'tasks/pushNotificationConfig/get' => false,
        'tasks/pushNotificationConfig/list' => false,
        'tasks/pushNotificationConfig/delete' => false,
        'tasks/resubscribe' => true,
        'agent/getAuthenticatedExtendedCard' => false,
    ];

    public readonly RequestHandler03 $handler;

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
        $this->handler = new RequestHandler03($httpHandler);
        $this->contextBuilder = new V03ServerCallContextBuilder($contextBuilder ?? new DefaultServerCallContextBuilder());
        $factory = new Psr17Factory();
        $this->responseFactory = $responseFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->versionValidator = new VersionValidator(Constants::PROTOCOL_VERSION_0_3, $this->logger);
    }

    public function supportsMethod(string $method): bool
    {
        return array_key_exists($method, self::METHODS);
    }

    /**
     * Handles one v0.3 request whose envelope (jsonrpc, id, method) the
     * dispatcher already checked.
     */
    public function handleRequest(string|int|null $requestId, string $method, \stdClass $body, ServerRequestInterface $request): ResponseInterface
    {
        try {
            $params = self::validate($method, $body);
            $context = $this->contextBuilder->build($request);
            $context->tenant = is_string($params->tenant ?? null) ? $params->tenant : '';
            $context->state['method'] = $method;
            $context->state['request_id'] = $requestId;
            $this->versionValidator->validate($context->headers());

            if (self::METHODS[$method]) {
                return $this->processStreamingRequest($requestId, $method, $params, $context);
            }

            $result = $this->processNonStreamingRequest($method, $params, $context);

            return Common::withActivatedExtensions($this->json(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => $result]), $context);
        } catch (\Throwable $e) {
            $this->logError($e, $requestId);

            return $this->json(self::errorEnvelope($requestId, $e));
        }
    }

    private function processNonStreamingRequest(string $method, \stdClass $params, ServerCallContext $context): mixed
    {
        return match ($method) {
            'message/send' => $this->handler->onMessageSend($params, $context),
            'tasks/get' => $this->handler->onGetTask($params, $context),
            'tasks/cancel' => $this->handler->onCancelTask($params, $context),
            'tasks/pushNotificationConfig/get' => $this->handler->onGetTaskPushNotificationConfig($params, $context),
            'tasks/pushNotificationConfig/set' => $this->handler->onCreateTaskPushNotificationConfig($params, $context),
            'tasks/pushNotificationConfig/list' => $this->handler->onListTaskPushNotificationConfigs($params, $context),
            'tasks/pushNotificationConfig/delete' => $this->deletePushConfig($params, $context),
            'agent/getAuthenticatedExtendedCard' => $this->handler->onGetExtendedAgentCard($params, $context),
            default => throw new \LogicException("Unsupported method {$method}"),
        };
    }

    private function deletePushConfig(\stdClass $params, ServerCallContext $context): mixed
    {
        $this->handler->onDeleteTaskPushNotificationConfig($params, $context);

        return null;
    }

    private function processStreamingRequest(string|int|null $requestId, string $method, \stdClass $params, ServerCallContext $context): ResponseInterface
    {
        $stream = $method === 'message/stream'
            ? $this->handler->onMessageSendStream($params, $context)
            : $this->handler->onSubscribeToTask($params, $context);

        // Setup errors (unknown task, streaming unsupported) happen on the
        // first event and come back as a plain JSON error.
        $stream->current();

        $chunks = function () use ($stream, $requestId): \Generator {
            try {
                while ($stream->valid()) {
                    $event = $stream->current();
                    yield $event === null
                        ? SseStream::keepAlive()
                        : SseStream::event(Common::encode(['jsonrpc' => '2.0', 'id' => $requestId, 'result' => $event]));
                    $stream->next();
                }
            } catch (\Throwable $e) {
                $this->logError($e, $requestId);
                // v0.3 clients look for `error` in the event data; there is
                // no `event: error` line (Python sends a plain data event).
                yield SseStream::event(Common::encode(self::errorEnvelope($requestId, $e)));
            }
        };

        return Common::withActivatedExtensions($this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withBody(new SseStream($chunks(), drainOnDisconnect: $method === 'message/stream')), $context);
    }

    /**
     * Checks the parts of the v0.3 request the handler relies on (Python
     * validates the whole pydantic model) and returns its params.
     *
     * @throws InvalidRequestError
     */
    private static function validate(string $method, \stdClass $body): \stdClass
    {
        $params = $body->params ?? null;
        if ($method === 'agent/getAuthenticatedExtendedCard') {
            return $params instanceof \stdClass ? $params : new \stdClass();
        }
        if (!$params instanceof \stdClass) {
            throw new InvalidRequestError('Invalid request: params must be an object');
        }

        $missing = static fn(string $field): InvalidRequestError => new InvalidRequestError("Invalid request: params.{$field} is required");
        switch ($method) {
            case 'message/send':
            case 'message/stream':
                $message = $params->message ?? null;
                if (!$message instanceof \stdClass) {
                    throw $missing('message');
                }
                if (!is_string($message->messageId ?? null)) {
                    throw $missing('message.messageId');
                }
                if (!in_array($message->role ?? null, ['user', 'agent'], true)) {
                    throw new InvalidRequestError("Invalid request: params.message.role must be 'user' or 'agent'");
                }
                if (!is_array($message->parts ?? null)) {
                    throw $missing('message.parts');
                }

                break;

            case 'tasks/pushNotificationConfig/set':
                if (!is_string($params->taskId ?? null)) {
                    throw $missing('taskId');
                }
                if (!($params->pushNotificationConfig ?? null) instanceof \stdClass || !is_string($params->pushNotificationConfig->url ?? null)) {
                    throw $missing('pushNotificationConfig.url');
                }

                break;

            case 'tasks/pushNotificationConfig/delete':
                if (!is_string($params->id ?? null)) {
                    throw $missing('id');
                }
                if (!is_string($params->pushNotificationConfigId ?? null)) {
                    throw $missing('pushNotificationConfigId');
                }

                break;

            default:
                if (!is_string($params->id ?? null)) {
                    throw $missing('id');
                }
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorEnvelope(string|int|null $requestId, \Throwable $error): array
    {
        // v0.3 errors are {code, message}: the v1.0 ErrorInfo `data` is left out.
        $jsonRpcError = ErrorHandlers::buildJsonRpcError($error);

        return ['jsonrpc' => '2.0', 'id' => $requestId, 'error' => ['code' => $jsonRpcError['code'], 'message' => $jsonRpcError['message']]];
    }

    private function logError(\Throwable $error, string|int|null $requestId): void
    {
        if ($error instanceof A2AError && $error->jsonRpcCode() !== null && $error->jsonRpcCode() !== -32603) {
            $this->logger->warning('v0.3 JSON-RPC request {id} failed: {message}', ['id' => $requestId, 'message' => $error->getMessage()]);

            return;
        }
        $this->logger->error('v0.3 JSON-RPC request {id} failed: {message}', ['id' => $requestId, 'message' => $error->getMessage(), 'exception' => $error]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(Common::encode($payload)));
    }
}
