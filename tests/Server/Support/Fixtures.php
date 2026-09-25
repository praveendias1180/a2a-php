<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Support;

use A2A\Helpers\ProtoHelpers;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentSkill;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\SendMessageRequest;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use Google\Protobuf\Timestamp;

final class Fixtures
{
    public const BASE_URL = 'http://agent.test';

    public static function agentCard(bool $streaming = true, bool $push = false, bool $extended = false): AgentCard
    {
        return new AgentCard([
            'name' => 'Test Agent',
            'description' => 'An agent for tests',
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(['streaming' => $streaming, 'push_notifications' => $push, 'extended_agent_card' => $extended]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'skills' => [new AgentSkill(['id' => 'echo', 'name' => 'Echo', 'description' => 'Echoes', 'tags' => ['test']])],
            'supported_interfaces' => [
                new AgentInterface(['url' => self::BASE_URL . '/a2a/jsonrpc', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']),
                new AgentInterface(['url' => self::BASE_URL . '/a2a/rest', 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0']),
            ],
        ]);
    }

    public static function userMessage(string $text = 'hello', ?string $messageId = null, ?string $taskId = null, ?string $contextId = null): Message
    {
        $message = new Message([
            'message_id' => $messageId ?? 'msg-' . bin2hex(random_bytes(4)),
            'role' => Role::ROLE_USER,
            'parts' => [new Part(['text' => $text])],
        ]);
        if ($taskId !== null) {
            $message->setTaskId($taskId);
        }
        if ($contextId !== null) {
            $message->setContextId($contextId);
        }

        return $message;
    }

    public static function sendRequest(Message $message, ?SendMessageConfiguration $configuration = null): SendMessageRequest
    {
        $request = new SendMessageRequest(['message' => $message]);
        if ($configuration !== null) {
            $request->setConfiguration($configuration);
        }

        return $request;
    }

    public static function callContext(string $user = '', string $version = '1.0'): ServerCallContext
    {
        return new ServerCallContext(
            state: ['headers' => ['a2a-version' => $version]],
            user: $user === '' ? new \A2A\Auth\UnauthenticatedUser() : new NamedUser($user),
        );
    }

    public static function task(string $id, int $state, string $contextId = 'ctx-1', ?int $timestampSeconds = null): Task
    {
        $status = new TaskStatus(['state' => $state]);
        if ($timestampSeconds !== null) {
            $status->setTimestamp(new Timestamp(['seconds' => $timestampSeconds]));
        }

        return new Task(['id' => $id, 'context_id' => $contextId, 'status' => $status]);
    }

    /**
     * An execute() closure that creates the task (if new) and then runs $steps.
     *
     * @param \Closure(TaskUpdater, RequestContext, EventQueue): void $steps
     *
     * @return \Closure(RequestContext, EventQueue): void
     */
    public static function taskScript(\Closure $steps): \Closure
    {
        return static function (RequestContext $context, EventQueue $queue) use ($steps): void {
            $message = $context->message();
            if ($context->currentTask() === null && $message !== null) {
                $queue->enqueueEvent(ProtoHelpers::newTaskFromUserMessage($message));
            }
            $steps(new TaskUpdater($queue, (string) $context->taskId(), (string) $context->contextId()), $context, $queue);
        };
    }

    public static function stateName(?Task $task): string
    {
        $state = $task?->getStatus()?->getState() ?? TaskState::TASK_STATE_UNSPECIFIED;
        $name = TaskState::name($state);

        return is_string($name) ? $name : (string) $state;
    }
}
