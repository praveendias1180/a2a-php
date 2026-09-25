<?php

declare(strict_types=1);

namespace A2A\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpSender;
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
use A2A\Types\SendMessageRequest;
use A2A\Types\SendMessageResponse;
use A2A\Types\StreamResponse;
use A2A\Types\SubscribeToTaskRequest;
use A2A\Types\Task;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Utils\ErrorHandlers;
use A2A\Utils\Errors\A2AError;
use A2A\Utils\Errors\ErrorMapping;
use A2A\Utils\Uuid;
use Google\Protobuf\Internal\Message as ProtobufMessage;

/**
 * The JSON-RPC 2.0 transport.
 *
 * Mirrors a2a-python: JsonRpcTransport in src/a2a/client/transports/jsonrpc.py.
 * Python takes an httpx.AsyncClient; this takes an HttpSender (see
 * HttpSenderFactory to wrap Guzzle, Symfony or any PSR-18 client).
 */
final class JsonRpcTransport implements ClientTransport
{
    public function __construct(
        public readonly HttpSender $httpSender,
        public AgentCard $agentCard,
        public readonly string $url,
    ) {}

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        return $this->call('SendMessage', $request, new SendMessageResponse(), $context);
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('SendStreamingMessage', $request, $context);
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return $this->call('GetTask', $request, new Task(), $context);
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        return $this->call('ListTasks', $request, new ListTasksResponse(), $context);
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        return $this->call('CancelTask', $request, new Task(), $context);
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        return $this->call('CreateTaskPushNotificationConfig', $request, new TaskPushNotificationConfig(), $context);
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        return $this->call('GetTaskPushNotificationConfig', $request, new TaskPushNotificationConfig(), $context);
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        return $this->call('ListTaskPushNotificationConfigs', $request, new ListTaskPushNotificationConfigsResponse(), $context);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $this->sendRequest('DeleteTaskPushNotificationConfig', $request, $context);
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('SubscribeToTask', $request, $context);
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        $card = $this->agentCard;
        if (!($card->getCapabilities()?->getExtendedAgentCard() ?? false)) {
            return $card;
        }

        $result = $this->sendRequest('GetExtendedAgentCard', $request, $context);
        if (!$result instanceof \stdClass) {
            throw new A2AClientError('Invalid response type: ' . get_debug_type($result));
        }

        return HttpHelpers::parseInto($result, new AgentCard());
    }

    public function close(): void {}

    /**
     * The A2AError for a JSON-RPC error object: the class registered for its
     * code, with the ErrorInfo metadata as data. Unknown codes become an
     * A2AClientError. Python: JsonRpcTransport._create_jsonrpc_error.
     */
    public static function createJsonRpcError(mixed $error): A2AError
    {
        $error = is_object($error) ? get_object_vars($error) : (is_array($error) ? $error : []);
        $code = $error['code'] ?? null;
        $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : HttpHelpers::encodeJson($error);

        $data = null;
        $rawData = $error['data'] ?? null;
        if (is_array($rawData)) {
            foreach ($rawData as $detail) {
                if ($detail instanceof \stdClass && ($detail->{'@type'} ?? null) === ErrorHandlers::ERROR_INFO_TYPE) {
                    $metadata = $detail->metadata ?? null;
                    $metadata = $metadata === null ? [] : json_decode(HttpHelpers::encodeJson($metadata), true);
                    $data = is_array($metadata) && $metadata !== [] ? $metadata : null;

                    break;
                }
            }
        }

        if (is_int($code)) {
            $class = ErrorMapping::errorClassForJsonRpcCode($code);
            if ($class !== null) {
                return new $class($message, $data);
            }
        }

        return new A2AClientError(sprintf('JSON-RPC Error %s: %s', is_scalar($code) ? (string) $code : 'null', $message));
    }

    /**
     * @template T of ProtobufMessage
     *
     * @param T $into
     *
     * @return T
     */
    private function call(string $method, ProtobufMessage $request, ProtobufMessage $into, ?ClientCallContext $context): ProtobufMessage
    {
        return HttpHelpers::parseInto($this->sendRequest($method, $request, $context), $into);
    }

    /**
     * Sends one JSON-RPC request and returns the decoded `result`.
     */
    private function sendRequest(string $method, ProtobufMessage $request, ?ClientCallContext $context): mixed
    {
        $body = HttpHelpers::sendHttpRequest($this->httpSender, $this->buildHttpRequest($method, $request, $context));

        return $this->unwrap(HttpHelpers::decodeJson($body));
    }

    /**
     * @return \Generator<int, StreamResponse>
     */
    private function sendStreamRequest(string $method, ProtobufMessage $request, ?ClientCallContext $context): \Generator
    {
        $events = HttpHelpers::sendHttpStreamRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $request, $context),
            null,
            $this->handleSseError(...),
        );
        foreach ($events as $data) {
            $result = $this->unwrap(HttpHelpers::decodeJson($data));
            yield HttpHelpers::parseInto($result, new StreamResponse());
        }
    }

    private function buildHttpRequest(string $method, ProtobufMessage $request, ?ClientCallContext $context): HttpRequest
    {
        // Built as a string so the ProtoJSON params keep their exact shape.
        $body = sprintf(
            '{"jsonrpc":"2.0","method":%s,"params":%s,"id":%s}',
            HttpHelpers::encodeJson($method),
            $request->serializeToJsonString(),
            HttpHelpers::encodeJson(Uuid::v4()),
        );
        $headers = HttpHelpers::getHttpHeaders($context) + ['Content-Type' => 'application/json'];

        return new HttpRequest('POST', $this->url, $headers, $body, $context?->timeout);
    }

    /**
     * The `result` of a JSON-RPC response object, or the mapped error.
     */
    private function unwrap(mixed $response): mixed
    {
        if (!$response instanceof \stdClass) {
            throw new A2AClientError('Invalid JSON-RPC response: ' . get_debug_type($response));
        }
        if (isset($response->error)) {
            throw self::createJsonRpcError($response->error);
        }
        if (!property_exists($response, 'result')) {
            throw new A2AClientError('Invalid JSON-RPC response: neither result nor error');
        }

        return $response->result;
    }

    private function handleSseError(string $data): never
    {
        try {
            $response = HttpHelpers::decodeJson($data);
        } catch (A2AClientError) {
            $response = null;
        }
        if ($response instanceof \stdClass && isset($response->error)) {
            throw self::createJsonRpcError($response->error);
        }

        throw new A2AClientError('SSE stream error: ' . $data);
    }
}
