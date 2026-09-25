<?php

declare(strict_types=1);

namespace A2A\Client\Transports;

use A2A\Client\ClientCallContext;
use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
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
use A2A\Utils\Errors\MethodNotFoundError;
use Google\Protobuf\Internal\Message as ProtobufMessage;

/**
 * The HTTP+JSON (REST) transport.
 *
 * Mirrors a2a-python: RestTransport in src/a2a/client/transports/rest.py
 */
final class RestTransport implements ClientTransport
{
    public readonly string $url;

    public function __construct(
        public readonly HttpSender $httpSender,
        public AgentCard $agentCard,
        string $url,
    ) {
        $this->url = str_ends_with($url, '/') ? substr($url, 0, -1) : $url;
    }

    public function sendMessage(SendMessageRequest $request, ?ClientCallContext $context = null): SendMessageResponse
    {
        $data = $this->executeRequest('POST', '/message:send', $request->getTenant(), $context, $request->serializeToJsonString());

        return HttpHelpers::parseInto($data, new SendMessageResponse());
    }

    public function sendMessageStreaming(SendMessageRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('POST', '/message:stream', $request->getTenant(), $context, $request->serializeToJsonString());
    }

    public function getTask(GetTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $params = self::paramsWithout($request, ['id', 'tenant']);
        $data = $this->executeRequest('GET', '/tasks/' . rawurlencode($request->getId()), $request->getTenant(), $context, null, $params);

        return HttpHelpers::parseInto($data, new Task());
    }

    public function listTasks(ListTasksRequest $request, ?ClientCallContext $context = null): ListTasksResponse
    {
        $params = self::paramsWithout($request, ['tenant']);
        $data = $this->executeRequest('GET', '/tasks', $request->getTenant(), $context, null, $params);

        return HttpHelpers::parseInto($data, new ListTasksResponse());
    }

    public function cancelTask(CancelTaskRequest $request, ?ClientCallContext $context = null): Task
    {
        $data = $this->executeRequest('POST', '/tasks/' . rawurlencode($request->getId()) . ':cancel', $request->getTenant(), $context, $request->serializeToJsonString());

        return HttpHelpers::parseInto($data, new Task());
    }

    public function createTaskPushNotificationConfig(TaskPushNotificationConfig $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $target = '/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs';
        $data = $this->executeRequest('POST', $target, $request->getTenant(), $context, $request->serializeToJsonString());

        return HttpHelpers::parseInto($data, new TaskPushNotificationConfig());
    }

    public function getTaskPushNotificationConfig(GetTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): TaskPushNotificationConfig
    {
        $params = self::paramsWithout($request, ['id', 'taskId', 'tenant']);
        $target = '/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs/' . rawurlencode($request->getId());
        $data = $this->executeRequest('GET', $target, $request->getTenant(), $context, null, $params);

        return HttpHelpers::parseInto($data, new TaskPushNotificationConfig());
    }

    public function listTaskPushNotificationConfigs(ListTaskPushNotificationConfigsRequest $request, ?ClientCallContext $context = null): ListTaskPushNotificationConfigsResponse
    {
        $params = self::paramsWithout($request, ['taskId', 'tenant']);
        $target = '/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs';
        $data = $this->executeRequest('GET', $target, $request->getTenant(), $context, null, $params);

        return HttpHelpers::parseInto($data, new ListTaskPushNotificationConfigsResponse());
    }

    public function deleteTaskPushNotificationConfig(DeleteTaskPushNotificationConfigRequest $request, ?ClientCallContext $context = null): void
    {
        $params = self::paramsWithout($request, ['id', 'taskId', 'tenant']);
        $target = '/tasks/' . rawurlencode($request->getTaskId()) . '/pushNotificationConfigs/' . rawurlencode($request->getId());
        $this->executeRequest('DELETE', $target, $request->getTenant(), $context, null, $params);
    }

    public function subscribe(SubscribeToTaskRequest $request, ?ClientCallContext $context = null): \Generator
    {
        yield from $this->sendStreamRequest('POST', '/tasks/' . rawurlencode($request->getId()) . ':subscribe', $request->getTenant(), $context);
    }

    public function getExtendedAgentCard(GetExtendedAgentCardRequest $request, ?ClientCallContext $context = null): AgentCard
    {
        $card = $this->agentCard;
        if (!($card->getCapabilities()?->getExtendedAgentCard() ?? false)) {
            return $card;
        }
        $data = $this->executeRequest('GET', '/extendedAgentCard', $request->getTenant(), $context);

        return HttpHelpers::parseInto($data, new AgentCard());
    }

    public function close(): void {}

    /**
     * The A2AError for a REST error payload, found through the reason of its
     * first google.rpc.ErrorInfo detail, or null when there is no known
     * reason. Python: rest._parse_rest_error.
     */
    public static function parseRestError(mixed $errorPayload, string $fallbackMessage): ?A2AError
    {
        $error = $errorPayload instanceof \stdClass ? ($errorPayload->error ?? null) : null;
        if (!$error instanceof \stdClass) {
            return null;
        }
        $message = isset($error->message) && is_string($error->message) ? $error->message : $fallbackMessage;
        $details = $error->details ?? [];
        if (!is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if ($detail instanceof \stdClass && ($detail->{'@type'} ?? null) === ErrorHandlers::ERROR_INFO_TYPE) {
                $reason = $detail->reason ?? null;
                if (is_string($reason)) {
                    $class = ErrorMapping::errorClassForReason($reason);
                    if ($class !== null) {
                        $metadata = isset($detail->metadata) ? json_decode(HttpHelpers::encodeJson($detail->metadata), true) : null;

                        return new $class($message, is_array($metadata) && $metadata !== [] ? $metadata : null);
                    }
                }

                break;
            }
        }

        return null;
    }

    private function handleHttpError(HttpResponse $response, HttpRequest $request): never
    {
        try {
            $payload = HttpHelpers::decodeJson($response->body);
            $mapped = self::parseRestError($payload, HttpHelpers::describe($response, $request));
            if ($mapped !== null) {
                throw $mapped;
            }
        } catch (A2AClientError) {
            // Not JSON: fall through to the status-code handling below.
        }

        if ($response->statusCode === 404) {
            throw new MethodNotFoundError('Resource not found: ' . $request->url);
        }

        throw new A2AClientError(sprintf('HTTP Error %d: %s', $response->statusCode, HttpHelpers::describe($response, $request)));
    }

    private function handleSseError(string $data): never
    {
        try {
            $payload = HttpHelpers::decodeJson($data);
        } catch (A2AClientError) {
            $payload = null;
        }
        $mapped = self::parseRestError($payload, $data);
        if ($mapped !== null) {
            throw $mapped;
        }

        throw new A2AClientError($data);
    }

    /**
     * @return \Generator<int, StreamResponse>
     */
    private function sendStreamRequest(string $method, string $target, string $tenant, ?ClientCallContext $context, ?string $json = null): \Generator
    {
        $events = HttpHelpers::sendHttpStreamRequest(
            $this->httpSender,
            $this->buildHttpRequest($method, $target, $tenant, $context, $json),
            $this->handleHttpError(...),
            $this->handleSseError(...),
        );
        foreach ($events as $data) {
            yield HttpHelpers::parseJsonInto($data, new StreamResponse());
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeRequest(string $method, string $target, string $tenant, ?ClientCallContext $context, ?string $json = null, array $params = []): mixed
    {
        $request = $this->buildHttpRequest($method, $target, $tenant, $context, $json, $params);
        $body = HttpHelpers::sendHttpRequest($this->httpSender, $request, $this->handleHttpError(...));

        return $body === '' ? new \stdClass() : HttpHelpers::decodeJson($body);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildHttpRequest(string $method, string $target, string $tenant, ?ClientCallContext $context, ?string $json = null, array $params = []): HttpRequest
    {
        $url = $this->url . ($tenant !== '' ? '/' . $tenant . $target : $target);
        $query = self::buildQuery($params);
        if ($query !== '') {
            $url .= '?' . $query;
        }
        $headers = HttpHelpers::getHttpHeaders($context);
        if ($json !== null) {
            $headers += ['Content-Type' => 'application/json'];
        }

        return new HttpRequest($method, $url, $headers, $json, $context?->timeout);
    }

    /**
     * The request's ProtoJSON fields minus the ones carried in the path,
     * like Python's MessageToDict(request) + del params[...].
     *
     * @param list<string> $pathFields
     *
     * @return array<string, mixed>
     */
    private static function paramsWithout(ProtobufMessage $request, array $pathFields): array
    {
        $params = json_decode($request->serializeToJsonString(), true);
        if (!is_array($params)) {
            return [];
        }
        foreach ($pathFields as $field) {
            unset($params[$field]);
        }

        /** @var array<string, mixed> $params */
        return $params;
    }

    /**
     * Encodes query parameters the way httpx does: booleans as true/false and
     * lists as repeated keys.
     *
     * @param array<string, mixed> $params
     */
    private static function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::queryValue($item));
            }
        }

        return implode('&', $pairs);
    }

    private static function queryValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value === null => '',
            default => HttpHelpers::encodeJson($value),
        };
    }
}
