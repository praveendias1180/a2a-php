<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpSender;
use A2A\Client\Transports\ClientTransport;
use A2A\Client\Transports\HttpHelpers;
use A2A\Types\AgentCard;
use A2A\Types\CancelTaskRequest;
use A2A\Types\DeleteTaskPushNotificationConfigRequest;
use A2A\Types\GetExtendedAgentCardRequest;
use A2A\Types\GetTaskPushNotificationConfigRequest;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTaskPushNotificationConfigsRequest;
use A2A\Types\ListTaskPushNotificationConfigsResponse;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Utils\Constants;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\ErrorMapping;
use A2A\Utils\Uuid;

/**
 * Talks JSON-RPC to an A2A v0.3 agent with v1.0 types: requests go out as
 * v0.3 methods (`message/send`, `tasks/get`, ...) and v0.3 results come back
 * as v1.0 objects, so the calling code does not change. ClientFactory picks
 * it when the agent's JSON-RPC interface is v0.3.
 *
 * Mirrors a2a-python: CompatJsonRpcTransport in
 * src/a2a/compat/v0_3/jsonrpc_transport.py. Requests carry
 * `A2A-Version: 0.3` and both extension headers. Differences: the request
 * always states `blocking` (the opposite of returnImmediately), so a v0.3
 * server never falls back to its own default.
 */
final class CompatJsonRpcTransport implements ClientTransport
{
    public function __construct(
        public readonly HttpSender $httpSender,
        public AgentCard $agentCard,
        public readonly string $url,
    ) {}

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        $result = $this->sendRequest('message/send', Conversions::toCompatSendMessageRequest(self::withBlocking($request)), $context);
        if (!$result instanceof \stdClass) {
            return new SendMessageResponse();
        }

        return Conversions::toCoreSendMessageResponse($result);
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('message/stream', Conversions::toCompatSendMessageRequest(self::withBlocking($request)), $context);
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return Conversions::toCoreTask(self::expectObject($this->sendRequest('tasks/get', Conversions::toCompatGetTaskRequest($request), $context)));
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        throw new \BadMethodCallException('ListTasks is not part of A2A v0.3.');
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return Conversions::toCoreTask(self::expectObject($this->sendRequest('tasks/cancel', Conversions::toCompatCancelTaskRequest($request), $context)));
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $result = $this->sendRequest('tasks/pushNotificationConfig/set', Conversions::toCompatCreateTaskPushNotificationConfigRequest($request), $context);

        return Conversions::toCoreTaskPushNotificationConfig(self::expectObject($result));
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $result = $this->sendRequest('tasks/pushNotificationConfig/get', Conversions::toCompatGetTaskPushNotificationConfigRequest($request), $context);

        return Conversions::toCoreTaskPushNotificationConfig(self::expectObject($result));
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        $result = $this->sendRequest('tasks/pushNotificationConfig/list', Conversions::toCompatListTaskPushNotificationConfigRequest($request), $context);

        return Conversions::toCoreListTaskPushNotificationConfigResponse($result);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $this->sendRequest('tasks/pushNotificationConfig/delete', Conversions::toCompatDeleteTaskPushNotificationConfigRequest($request), $context);
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('tasks/resubscribe', Conversions::toCompatSubscribeToTaskRequest($request), $context);
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        if (!($this->agentCard->getCapabilities()?->getExtendedAgentCard() ?? false)) {
            return $this->agentCard;
        }
        $card = Conversions::toCoreAgentCard(self::expectObject($this->sendRequest('agent/getAuthenticatedExtendedCard', new \stdClass(), $context)));
        $this->agentCard = $card;

        return $card;
    }

    public function close(): void {}

    /**
     * The A2AError for a v0.3 JSON-RPC error object: v0.3 uses the same codes
     * as v1.0 (-32001 task not found, ...). Unknown codes become an
     * A2AClientError. Python: CompatJsonRpcTransport._create_jsonrpc_error.
     */
    public static function createJsonRpcError(mixed $error): A2AError
    {
        $error = $error instanceof \stdClass ? $error : new \stdClass();
        $message = is_string($error->message ?? null) ? $error->message : 'Unknown Error';
        $code = $error->code ?? null;
        if (is_int($code)) {
            $class = ErrorMapping::errorClassForJsonRpcCode($code);
            if ($class !== null) {
                return new $class($message);
            }
        }

        return new A2AClientError($message);
    }

    /**
     * Blocking unless the caller asked for returnImmediately, stated
     * explicitly so v0.3 servers don't apply their own default.
     */
    public static function withBlocking(SendMessageRequest $request): SendMessageRequest
    {
        if ($request->hasConfiguration()) {
            return $request;
        }
        $copy = new SendMessageRequest();
        $copy->mergeFrom($request);
        $copy->setConfiguration(new SendMessageConfiguration());

        return $copy;
    }

    private function sendRequest(string $method, \stdClass $params, ?ClientCallContext $context): mixed
    {
        $body = HttpHelpers::sendHttpRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $params, $context),
            static fn($response): never => throw new A2AClientError('HTTP Error: ' . $response->statusCode),
        );

        return $this->unwrap(HttpHelpers::decodeJson($body));
    }

    /**
     * @return \Generator<int, \A2A\Types\StreamResponse>
     */
    private function sendStreamRequest(string $method, \stdClass $params, ?ClientCallContext $context): \Generator
    {
        $events = HttpHelpers::sendHttpStreamRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $params, $context),
            static fn($response): never => throw new A2AClientError('HTTP Error: ' . $response->statusCode),
            static function (string $data): never {
                $payload = json_decode($data, false);
                if ($payload instanceof \stdClass && isset($payload->error)) {
                    throw self::createJsonRpcError($payload->error);
                }

                throw new A2AClientError('SSE stream error: ' . $data);
            },
        );
        foreach ($events as $data) {
            $result = $this->unwrap(HttpHelpers::decodeJson($data));
            if ($result instanceof \stdClass && Conversions::resultKind($result) !== null) {
                yield Conversions::toCoreStreamResponse($result);
            }
        }
    }

    private function buildHttpRequest(string $method, \stdClass $params, ?ClientCallContext $context): HttpRequest
    {
        $body = HttpHelpers::encodeJson(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => Uuid::v4()]);
        $headers = HttpHelpers::getHttpHeaders($context);
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, Constants::VERSION_HEADER) === 0) {
                unset($headers[$name]);
            }
        }
        $headers[Constants::VERSION_HEADER] = Constants::PROTOCOL_VERSION_0_3;
        $headers = ExtensionHeaders::addLegacyExtensionHeader($headers) + ['Content-Type' => 'application/json'];

        return new HttpRequest('POST', $this->url, $headers, $body, $context?->timeout);
    }

    private function unwrap(mixed $response): mixed
    {
        if (!$response instanceof \stdClass) {
            throw new A2AClientError('Invalid JSON-RPC response: ' . get_debug_type($response));
        }
        if (isset($response->error)) {
            throw self::createJsonRpcError($response->error);
        }

        return $response->result ?? null;
    }

    private static function expectObject(mixed $result): \stdClass
    {
        if (!$result instanceof \stdClass) {
            throw new A2AClientError('Invalid JSON-RPC result: ' . get_debug_type($result));
        }

        return $result;
    }
}
