<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Server\ServerCallContext;
use A2A\Types\Task;
use A2A\Utils\ProtoUtils;

/**
 * Serves v0.3 requests with a v1.0 RequestHandler: converts the v0.3
 * `params` to the v1.0 request, calls the handler, and converts the result
 * back to its v0.3 shape.
 *
 * Mirrors a2a-python: RequestHandler03 in src/a2a/compat/v0_3/request_handler.py.
 * Arguments are the JSON-RPC `params` object instead of the whole pydantic
 * request, and streams pass keep-alive ticks (null) through unchanged.
 */
final class RequestHandler03
{
    public function __construct(public readonly RequestHandler $requestHandler) {}

    /**
     * @return \stdClass a v0.3 Task or Message
     */
    public function onMessageSend(\stdClass $params, ServerCallContext $context): \stdClass
    {
        $result = $this->requestHandler->onMessageSend(Conversions::toCoreSendMessageRequest($params), $context);

        return $result instanceof Task ? Conversions::toCompatTask($result) : Conversions::toCompatMessage($result);
    }

    /**
     * @return \Generator<int, \stdClass|null> v0.3 streaming results (null = keep-alive)
     */
    public function onMessageSendStream(\stdClass $params, ServerCallContext $context): \Generator
    {
        return self::compatStream($this->requestHandler->onMessageSendStream(Conversions::toCoreSendMessageRequest($params), $context));
    }

    public function onCancelTask(\stdClass $params, ServerCallContext $context): \stdClass
    {
        return Conversions::toCompatTask($this->requestHandler->onCancelTask(Conversions::toCoreCancelTaskRequest($params), $context)
            ?? throw new \A2A\Utils\Errors\TaskNotFoundError());
    }

    /**
     * @return \Generator<int, \stdClass|null>
     */
    public function onSubscribeToTask(\stdClass $params, ServerCallContext $context): \Generator
    {
        return self::compatStream($this->requestHandler->onSubscribeToTask(Conversions::toCoreSubscribeToTaskRequest($params), $context));
    }

    public function onGetTaskPushNotificationConfig(\stdClass $params, ServerCallContext $context): \stdClass
    {
        return Conversions::toCompatTaskPushNotificationConfig(
            $this->requestHandler->onGetTaskPushNotificationConfig(Conversions::toCoreGetTaskPushNotificationConfigRequest($params), $context),
        );
    }

    public function onCreateTaskPushNotificationConfig(\stdClass $params, ServerCallContext $context): \stdClass
    {
        return Conversions::toCompatTaskPushNotificationConfig(
            $this->requestHandler->onCreateTaskPushNotificationConfig(Conversions::toCoreCreateTaskPushNotificationConfigRequest($params), $context),
        );
    }

    public function onGetTask(\stdClass $params, ServerCallContext $context): \stdClass
    {
        return Conversions::toCompatTask($this->requestHandler->onGetTask(Conversions::toCoreGetTaskRequest($params), $context)
            ?? throw new \A2A\Utils\Errors\TaskNotFoundError());
    }

    /**
     * @return list<\stdClass>
     */
    public function onListTaskPushNotificationConfigs(\stdClass $params, ServerCallContext $context): array
    {
        return Conversions::toCompatListTaskPushNotificationConfigResponse(
            $this->requestHandler->onListTaskPushNotificationConfigs(Conversions::toCoreListTaskPushNotificationConfigRequest($params), $context),
        );
    }

    public function onDeleteTaskPushNotificationConfig(\stdClass $params, ServerCallContext $context): void
    {
        $this->requestHandler->onDeleteTaskPushNotificationConfig(Conversions::toCoreDeleteTaskPushNotificationConfigRequest($params), $context);
    }

    public function onGetExtendedAgentCard(\stdClass $params, ServerCallContext $context): \stdClass
    {
        return Conversions::toCompatAgentCard(
            $this->requestHandler->onGetExtendedAgentCard(Conversions::toCoreGetExtendedAgentCardRequest($params), $context),
        );
    }

    /**
     * @param \Generator<int, \A2A\Types\Message|Task|\A2A\Types\TaskStatusUpdateEvent|\A2A\Types\TaskArtifactUpdateEvent|null, mixed, void> $events
     *
     * @return \Generator<int, \stdClass|null>
     */
    private static function compatStream(\Generator $events): \Generator
    {
        foreach ($events as $event) {
            yield $event === null ? null : Conversions::toCompatStreamResponse(ProtoUtils::toStreamResponse($event));
        }
    }
}
