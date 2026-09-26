<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
use A2A\Client\Http\HttpSender;
use A2A\Client\Transports\ClientTransport;
use A2A\Client\Transports\HttpHelpers;
use A2A\Client\Transports\RestTransport;
use A2A\Compat\V0_3\Types as P;
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
use A2A\Utils\Constants;
use A2A\Utils\Errors\ContentTypeNotSupportedError;
use A2A\Utils\Errors\InvalidAgentResponseError;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\Errors\InvalidRequestError;
use A2A\Utils\Errors\JSONParseError;
use A2A\Utils\Errors\MethodNotFoundError;
use A2A\Utils\Errors\PushNotificationNotSupportedError;
use A2A\Utils\Errors\TaskNotCancelableError;
use A2A\Utils\Errors\TaskNotFoundError;
use A2A\Utils\Errors\UnsupportedOperationError;
use Google\Protobuf\Internal\Message as ProtobufMessage;

/**
 * Talks HTTP+JSON to an A2A v0.3 agent with v1.0 types: the v0.3 routes
 * (`/v1/message:send`, `/v1/tasks/{id}`, ...) with the v0.3 ProtoJSON
 * bodies, converted to and from v1.0. ClientFactory picks it when the
 * agent's HTTP+JSON interface is v0.3.
 *
 * Mirrors a2a-python: CompatRestTransport in
 * src/a2a/compat/v0_3/rest_transport.py, including subscribe trying POST
 * first and switching to GET after a 405 (v0.3 servers only served GET).
 * ListTasks is not part of v0.3; listing and deleting push configs use the
 * v0.3 proto's routes (Python raises NotImplementedError for those).
 * Errors: a `type` name (Python's format), a v1.0 ErrorInfo detail, or the
 * HTTP status (404 → MethodNotFoundError, as in Python).
 */
final class CompatRestTransport implements ClientTransport
{
    /** Python: _A2A_ERROR_NAME_TO_CLS */
    private const ERROR_TYPES = [
        'TaskNotFoundError' => TaskNotFoundError::class,
        'TaskNotCancelableError' => TaskNotCancelableError::class,
        'PushNotificationNotSupportedError' => PushNotificationNotSupportedError::class,
        'UnsupportedOperationError' => UnsupportedOperationError::class,
        'ContentTypeNotSupportedError' => ContentTypeNotSupportedError::class,
        'InvalidAgentResponseError' => InvalidAgentResponseError::class,
        'MethodNotFoundError' => MethodNotFoundError::class,
        'InvalidParamsError' => InvalidParamsError::class,
        'InvalidRequestError' => InvalidRequestError::class,
        'JSONParseError' => JSONParseError::class,
    ];

    private ?string $subscribeMethod;

    private bool $subscribeAutoMethod;

    public function __construct(
        public readonly HttpSender $httpSender,
        public AgentCard $agentCard,
        public readonly string $url,
        ?string $subscribeMethodOverride = null,
    ) {
        $this->subscribeMethod = $subscribeMethodOverride;
        $this->subscribeAutoMethod = $subscribeMethodOverride === null;
    }

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        $body = ToProto::sendMessageRequest(Conversions::toCompatSendMessageRequest(CompatJsonRpcTransport::withBlocking($request)));
        $response = $this->parseInto($this->execute('POST', '/v1/message:send', $context, $body), new P\SendMessageResponse());

        return $response->getPayload() === ''
            ? new SendMessageResponse()
            : Conversions::toCoreSendMessageResponse(FromProto::taskOrMessage($response));
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $body = ToProto::sendMessageRequest(Conversions::toCompatSendMessageRequest(CompatJsonRpcTransport::withBlocking($request)));
        yield from $this->stream('POST', '/v1/message:stream', $context, $body);
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $query = $request->hasHistoryLength() ? '?historyLength=' . $request->getHistoryLength() : '';
        $task = $this->parseInto($this->execute('GET', '/v1/tasks/' . rawurlencode($request->getId()) . $query, $context), new P\Task());

        return Conversions::toCoreTask(FromProto::task($task));
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        throw new \BadMethodCallException('ListTasks is not supported in A2A v0.3 REST.');
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $task = $this->parseInto($this->execute('POST', '/v1/tasks/' . rawurlencode($request->getId()) . ':cancel', $context, null, '{}'), new P\Task());

        return Conversions::toCoreTask(FromProto::task($task));
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $compat = Conversions::toCompatCreateTaskPushNotificationConfigRequest($request);
        $body = new P\CreateTaskPushNotificationConfigRequest([
            'parent' => 'tasks/' . $request->getTaskId(),
            'config_id' => $request->getId(),
            'config' => ToProto::taskPushNotificationConfig($compat),
        ]);
        $target = '/v1/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs';
        $config = $this->parseInto($this->execute('POST', $target, $context, $body), new P\TaskPushNotificationConfig());

        return Conversions::toCoreTaskPushNotificationConfig(FromProto::taskPushNotificationConfig($config));
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $target = '/v1/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs/' . rawurlencode($request->getId());
        $config = $this->parseInto($this->execute('GET', $target, $context), new P\TaskPushNotificationConfig());

        return Conversions::toCoreTaskPushNotificationConfig(FromProto::taskPushNotificationConfig($config));
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        $target = '/v1/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs';
        $response = $this->parseInto($this->execute('GET', $target, $context), new P\ListTaskPushNotificationConfigResponse());
        $configs = [];
        foreach ($response->getConfigs() as $config) {
            $configs[] = FromProto::taskPushNotificationConfig($config);
        }

        return Conversions::toCoreListTaskPushNotificationConfigResponse($configs);
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $target = '/v1/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs/' . rawurlencode($request->getId());
        $this->execute('DELETE', $target, $context);
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        $target = '/v1/tasks/' . rawurlencode($request->getId()) . ':subscribe';
        $method = $this->subscribeMethod ?? 'POST';
        try {
            yield from $this->stream($method, $target, $context);
        } catch (MethodNotAllowed) {
            if ($this->subscribeMethod !== null) {
                if ($this->subscribeAutoMethod) {
                    $this->subscribeAutoMethod = false;
                    $this->subscribeMethod = 'POST';
                }

                throw new A2AClientError('HTTP Error 405: subscribe is not allowed with POST or GET');
            }
            $this->subscribeMethod = 'GET';
            yield from $this->subscribe($request, $context);
        }
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        if (!($this->agentCard->getCapabilities()?->getExtendedAgentCard() ?? false)) {
            return $this->agentCard;
        }
        $data = HttpHelpers::decodeJson($this->execute('GET', '/v1/card', $context));
        if (!$data instanceof \stdClass) {
            throw new A2AClientError('Invalid agent card response: ' . get_debug_type($data));
        }
        $card = Conversions::toCoreAgentCard($data);
        $this->agentCard = $card;

        return $card;
    }

    public function close(): void {}

    /**
     * @template T of ProtobufMessage
     *
     * @param T $message
     *
     * @return T
     */
    private function parseInto(string $body, ProtobufMessage $message): ProtobufMessage
    {
        return HttpHelpers::parseJsonInto($body === '' ? '{}' : $body, $message, true);
    }

    /**
     * @return \Generator<int, StreamResponse>
     */
    private function stream(string $method, string $target, ?ClientCallContext $context, ?ProtobufMessage $body = null): \Generator
    {
        $events = HttpHelpers::sendHttpStreamRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $target, $context, $body),
            $this->handleHttpError(...),
            function (string $data): never {
                $this->throwMappedError(HttpHelpers::decodeJson($data), 500, $data);

                throw new A2AClientError($data);
            },
        );
        foreach ($events as $data) {
            $event = HttpHelpers::parseJsonInto($data, new P\StreamResponse(), true);
            yield Conversions::toCoreStreamResponse(FromProto::streamResponse($event));
        }
    }

    private function execute(string $method, string $target, ?ClientCallContext $context, ?ProtobufMessage $body = null, ?string $rawBody = null): string
    {
        return HttpHelpers::sendHttpRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $target, $context, $body, $rawBody),
            $this->handleHttpError(...),
        );
    }

    private function buildHttpRequest(string $method, string $target, ?ClientCallContext $context, ?ProtobufMessage $body = null, ?string $rawBody = null): HttpRequest
    {
        $headers = HttpHelpers::getHttpHeaders($context);
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, Constants::VERSION_HEADER) === 0) {
                unset($headers[$name]);
            }
        }
        $headers[Constants::VERSION_HEADER] = Constants::PROTOCOL_VERSION_0_3;
        $headers = ExtensionHeaders::addLegacyExtensionHeader($headers);
        $json = $body?->serializeToJsonString() ?? $rawBody;
        if ($json !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }

        return new HttpRequest($method, $this->url . $target, $headers, $json, $context?->timeout);
    }

    private function handleHttpError(HttpResponse $response, HttpRequest $request): never
    {
        if ($response->statusCode === 405) {
            throw new MethodNotAllowed(HttpHelpers::describe($response, $request));
        }
        try {
            $payload = HttpHelpers::decodeJson($response->body);
        } catch (A2AClientError) {
            $payload = null;
        }
        $this->throwMappedError($payload, $response->statusCode, HttpHelpers::describe($response, $request));

        if ($response->statusCode === 404) {
            throw new MethodNotFoundError('Resource not found: ' . $request->url);
        }

        throw new A2AClientError(sprintf('HTTP Error %d: %s', $response->statusCode, HttpHelpers::describe($response, $request)));
    }

    /**
     * Throws the A2AError for a known error body: Python's `{type, message}`
     * or a v1.0 `{error: {..., details: [ErrorInfo]}}`.
     */
    private function throwMappedError(mixed $payload, int $status, string $fallback): void
    {
        if (!$payload instanceof \stdClass) {
            return;
        }
        $type = $payload->type ?? null;
        if (is_string($type) && isset(self::ERROR_TYPES[$type])) {
            $class = self::ERROR_TYPES[$type];

            throw new $class(is_string($payload->message ?? null) ? $payload->message : $fallback);
        }
        $mapped = RestTransport::parseRestError($payload, $fallback);
        if ($mapped !== null) {
            throw $mapped;
        }
    }
}
