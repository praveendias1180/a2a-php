<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Compat\V0_3\Types as P;
use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\ServerCallContext;
use Google\Protobuf\Internal\GPBDecodeException;
use Google\Protobuf\Internal\Message as ProtobufMessage;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The v0.3 HTTP+JSON operations: parses the v0.3 ProtoJSON body, calls
 * RequestHandler03, and returns the v0.3 ProtoJSON result (decoded, ready
 * to encode).
 *
 * Mirrors a2a-python: REST03Handler in src/a2a/compat/v0_3/rest_handler.py.
 * Also serves DELETE on a push config (in the v0.3 proto, not in Python's
 * route table), and the card comes back with camelCase keys (Python dumps
 * the pydantic model without aliases).
 */
final class Rest03Handler
{
    public readonly RequestHandler03 $handler03;

    public function __construct(RequestHandler $requestHandler)
    {
        $this->handler03 = new RequestHandler03($requestHandler);
    }

    public function onMessageSend(ServerRequestInterface $request, ServerCallContext $context): mixed
    {
        $params = FromProto::messageSendParams(self::parse($request, new P\SendMessageRequest()));

        return self::toJson(ToProto::taskOrMessage($this->handler03->onMessageSend($params, $context)));
    }

    /**
     * @return \Generator<int, mixed> ProtoJSON StreamResponse values (null = keep-alive)
     */
    public function onMessageSendStream(ServerRequestInterface $request, ServerCallContext $context): \Generator
    {
        $params = FromProto::messageSendParams(self::parse($request, new P\SendMessageRequest()));

        return self::streamJson($this->handler03->onMessageSendStream($params, $context));
    }

    public function onCancelTask(string $taskId, ServerCallContext $context): mixed
    {
        return self::toJson(ToProto::task($this->handler03->onCancelTask((object) ['id' => $taskId], $context)));
    }

    /**
     * @return \Generator<int, mixed>
     */
    public function onSubscribeToTask(string $taskId, ServerCallContext $context): \Generator
    {
        return self::streamJson($this->handler03->onSubscribeToTask((object) ['id' => $taskId], $context));
    }

    public function getPushNotification(string $taskId, string $pushId, ServerCallContext $context): mixed
    {
        $config = $this->handler03->onGetTaskPushNotificationConfig((object) ['id' => $taskId, 'pushNotificationConfigId' => $pushId], $context);

        return self::toJson(ToProto::taskPushNotificationConfig($config));
    }

    public function setPushNotification(ServerRequestInterface $request, string $taskId, ServerCallContext $context): mixed
    {
        $params = FromProto::taskPushNotificationConfigRequest(self::parse($request, new P\CreateTaskPushNotificationConfigRequest(), ['parent' => 'tasks/' . $taskId]));
        $params->taskId = $taskId;

        return self::toJson(ToProto::taskPushNotificationConfig($this->handler03->onCreateTaskPushNotificationConfig($params, $context)));
    }

    public function deletePushNotification(string $taskId, string $pushId, ServerCallContext $context): mixed
    {
        $this->handler03->onDeleteTaskPushNotificationConfig((object) ['id' => $taskId, 'pushNotificationConfigId' => $pushId], $context);

        return new \stdClass();
    }

    public function onGetTask(ServerRequestInterface $request, string $taskId, ServerCallContext $context): mixed
    {
        $params = (object) ['id' => $taskId];
        parse_str($request->getUri()->getQuery(), $query);
        $historyLength = $query['historyLength'] ?? null;
        if (is_string($historyLength) && is_numeric($historyLength)) {
            $params->historyLength = (int) $historyLength;
        }

        return self::toJson(ToProto::task($this->handler03->onGetTask($params, $context)));
    }

    public function listPushNotifications(string $taskId, ServerCallContext $context): mixed
    {
        $configs = [];
        foreach ($this->handler03->onListTaskPushNotificationConfigs((object) ['id' => $taskId], $context) as $config) {
            $configs[] = ToProto::taskPushNotificationConfig($config);
        }

        return self::toJson(new P\ListTaskPushNotificationConfigResponse(['configs' => $configs]));
    }

    public function onGetExtendedAgentCard(ServerCallContext $context): mixed
    {
        return $this->handler03->onGetExtendedAgentCard(new \stdClass(), $context);
    }

    /**
     * @template T of ProtobufMessage
     *
     * @param T                    $message
     * @param array<string, string> $defaults fields to set when the body leaves them out
     *
     * @return T
     */
    private static function parse(ServerRequestInterface $request, ProtobufMessage $message, array $defaults = []): ProtobufMessage
    {
        $body = (string) $request->getBody();
        try {
            // Unknown fields are ignored, as in Python (ignore_unknown_fields=True).
            $message->mergeFromJsonString($body === '' ? '{}' : $body, true);
        } catch (GPBDecodeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GPBDecodeException($e->getMessage(), 0, $e instanceof \Exception ? $e : null);
        }
        if ($message instanceof P\CreateTaskPushNotificationConfigRequest && $message->getParent() === '' && isset($defaults['parent'])) {
            $message->setParent($defaults['parent']);
        }

        return $message;
    }

    private static function toJson(ProtobufMessage $message): mixed
    {
        return json_decode($message->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param \Generator<int, \stdClass|null> $events
     *
     * @return \Generator<int, mixed>
     */
    private static function streamJson(\Generator $events): \Generator
    {
        foreach ($events as $event) {
            yield $event === null ? null : self::toJson(ToProto::streamResponse($event));
        }
    }
}
