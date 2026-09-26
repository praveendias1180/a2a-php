<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Compat\V0_3\Types as P;
use A2A\Utils\Errors\InvalidParamsError;
use Google\Protobuf\Struct;

/**
 * v0.3 protobuf messages (the HTTP+JSON binding) → v0.3 objects in the
 * JSON-RPC shape (\stdClass), which Conversions then maps to v1.0.
 *
 * Mirrors a2a-python: FromProto in src/a2a/compat/v0_3/proto_utils.py, for
 * the types the REST binding carries. Unlike Python, an unset status
 * message stays unset (Python builds an empty Message) and the status
 * timestamp is kept.
 */
final class FromProto
{
    private const TASK_NAME = '#^tasks/([^/]+)$#';

    private const TASK_PUSH_CONFIG_NAME = '#^tasks/([^/]+)/pushNotificationConfigs/([^/]*)$#';

    private const TASK_STATE = [
        P\TaskState::TASK_STATE_SUBMITTED => 'submitted',
        P\TaskState::TASK_STATE_WORKING => 'working',
        P\TaskState::TASK_STATE_COMPLETED => 'completed',
        P\TaskState::TASK_STATE_CANCELLED => 'canceled',
        P\TaskState::TASK_STATE_FAILED => 'failed',
        P\TaskState::TASK_STATE_INPUT_REQUIRED => 'input-required',
        P\TaskState::TASK_STATE_AUTH_REQUIRED => 'auth-required',
        P\TaskState::TASK_STATE_REJECTED => 'rejected',
    ];

    private function __construct() {}

    public static function message(P\Message $message): \stdClass
    {
        $parts = [];
        foreach ($message->getContent() as $part) {
            $parts[] = self::part($part);
        }

        return self::compact([
            'kind' => 'message',
            'messageId' => $message->getMessageId(),
            'parts' => $parts,
            'contextId' => self::nonEmpty($message->getContextId()),
            'taskId' => self::nonEmpty($message->getTaskId()),
            'role' => $message->getRole() === P\Role::ROLE_AGENT ? 'agent' : 'user',
            'metadata' => self::metadata($message->getMetadata()),
            'extensions' => self::listOrNull($message->getExtensions()),
        ]);
    }

    public static function metadata(?Struct $metadata): ?\stdClass
    {
        if ($metadata === null || count($metadata->getFields()) === 0) {
            return null;
        }
        $value = json_decode($metadata->serializeToJsonString(), false, 512, JSON_THROW_ON_ERROR);

        return $value instanceof \stdClass ? $value : new \stdClass();
    }

    public static function part(P\Part $part): \stdClass
    {
        $metadata = $part->hasMetadata() ? self::metadata($part->getMetadata()) : null;

        return match ($part->getPart()) {
            'text' => self::compact(['kind' => 'text', 'text' => $part->getText(), 'metadata' => $metadata]),
            'file' => self::compact(['kind' => 'file', 'file' => self::file($part->getFile() ?? new P\FilePart()), 'metadata' => $metadata]),
            'data' => self::compact(['kind' => 'data', 'data' => self::metadata($part->getData()?->getData()) ?? new \stdClass(), 'metadata' => $metadata]),
            default => throw new \ValueError('Unsupported part type'),
        };
    }

    public static function file(P\FilePart $file): \stdClass
    {
        $common = ['mimeType' => self::nonEmpty($file->getMimeType()), 'name' => self::nonEmpty($file->getName())];

        return $file->getFile() === 'file_with_uri'
            ? self::compact(['uri' => $file->getFileWithUri()] + $common)
            // The bytes field holds the base64 text (see ToProto::file()).
            : self::compact(['bytes' => $file->getFileWithBytes()] + $common);
    }

    public static function taskOrMessage(P\SendMessageResponse $response): \stdClass
    {
        return $response->hasMsg() && $response->getMsg() !== null
            ? self::message($response->getMsg())
            : self::task($response->getTask() ?? new P\Task());
    }

    public static function task(P\Task $task): \stdClass
    {
        $artifacts = [];
        foreach ($task->getArtifacts() as $artifact) {
            $artifacts[] = self::artifact($artifact);
        }
        $history = [];
        foreach ($task->getHistory() as $message) {
            $history[] = self::message($message);
        }

        return self::compact([
            'kind' => 'task',
            'id' => $task->getId(),
            'contextId' => $task->getContextId(),
            'status' => self::taskStatus($task->getStatus() ?? new P\TaskStatus()),
            'artifacts' => $artifacts,
            'history' => $history,
            'metadata' => self::metadata($task->getMetadata()),
        ]);
    }

    public static function taskStatus(P\TaskStatus $status): \stdClass
    {
        $timestamp = null;
        if ($status->hasTimestamp() && $status->getTimestamp() !== null) {
            $json = json_decode($status->getTimestamp()->serializeToJsonString(), false);
            $timestamp = is_string($json) ? $json : null;
        }

        return self::compact([
            'state' => self::taskState($status->getState()),
            'message' => $status->hasUpdate() && $status->getUpdate() !== null ? self::message($status->getUpdate()) : null,
            'timestamp' => $timestamp,
        ]);
    }

    public static function taskState(int $state): string
    {
        return self::TASK_STATE[$state] ?? 'unknown';
    }

    public static function artifact(P\Artifact $artifact): \stdClass
    {
        $parts = [];
        foreach ($artifact->getParts() as $part) {
            $parts[] = self::part($part);
        }

        return self::compact([
            'artifactId' => $artifact->getArtifactId(),
            'description' => self::nonEmpty($artifact->getDescription()),
            'metadata' => self::metadata($artifact->getMetadata()),
            'name' => self::nonEmpty($artifact->getName()),
            'parts' => $parts,
            'extensions' => self::listOrNull($artifact->getExtensions()),
        ]);
    }

    public static function taskArtifactUpdateEvent(P\TaskArtifactUpdateEvent $event): \stdClass
    {
        return self::compact([
            'kind' => 'artifact-update',
            'taskId' => $event->getTaskId(),
            'contextId' => $event->getContextId(),
            'artifact' => self::artifact($event->getArtifact() ?? new P\Artifact()),
            'metadata' => self::metadata($event->getMetadata()),
            'append' => $event->getAppend(),
            'lastChunk' => $event->getLastChunk(),
        ]);
    }

    public static function taskStatusUpdateEvent(P\TaskStatusUpdateEvent $event): \stdClass
    {
        return self::compact([
            'kind' => 'status-update',
            'taskId' => $event->getTaskId(),
            'contextId' => $event->getContextId(),
            'status' => self::taskStatus($event->getStatus() ?? new P\TaskStatus()),
            'metadata' => self::metadata($event->getMetadata()),
            'final' => $event->getFinal(),
        ]);
    }

    public static function pushNotificationConfig(P\PushNotificationConfig $config): \stdClass
    {
        return self::compact([
            'id' => self::nonEmpty($config->getId()),
            'url' => $config->getUrl(),
            'token' => self::nonEmpty($config->getToken()),
            'authentication' => $config->hasAuthentication() && $config->getAuthentication() !== null
                ? self::authenticationInfo($config->getAuthentication())
                : null,
        ]);
    }

    public static function authenticationInfo(P\AuthenticationInfo $info): \stdClass
    {
        return self::compact([
            'schemes' => iterator_to_array($info->getSchemes(), false),
            'credentials' => self::nonEmpty($info->getCredentials()),
        ]);
    }

    public static function messageSendConfiguration(P\SendMessageConfiguration $config): \stdClass
    {
        return self::compact([
            'acceptedOutputModes' => iterator_to_array($config->getAcceptedOutputModes(), false),
            'pushNotificationConfig' => $config->hasPushNotification() && $config->getPushNotification() !== null
                ? self::pushNotificationConfig($config->getPushNotification())
                : null,
            // ProtoJSON can't tell 0 from unset here; 0 means "no limit" in
            // v0.3 REST, as it does after Python's conversion.
            'historyLength' => $config->getHistoryLength() !== 0 ? $config->getHistoryLength() : null,
            'blocking' => $config->getBlocking(),
        ]);
    }

    /**
     * @return \stdClass v0.3 MessageSendParams
     */
    public static function messageSendParams(P\SendMessageRequest $request): \stdClass
    {
        return self::compact([
            'configuration' => self::messageSendConfiguration($request->getConfiguration() ?? new P\SendMessageConfiguration()),
            'message' => self::message($request->getRequest() ?? new P\Message()),
            'metadata' => self::metadata($request->getMetadata()),
        ]);
    }

    /**
     * @return \stdClass v0.3 TaskPushNotificationConfig ({taskId, pushNotificationConfig})
     *
     * @throws InvalidParamsError when `parent` is not `tasks/{id}`
     */
    public static function taskPushNotificationConfigRequest(P\CreateTaskPushNotificationConfigRequest $request): \stdClass
    {
        if (preg_match(self::TASK_NAME, $request->getParent(), $m) !== 1) {
            throw new InvalidParamsError('No task for ' . $request->getParent());
        }
        $config = $request->getConfig()?->getPushNotificationConfig() ?? new P\PushNotificationConfig();

        return (object) ['pushNotificationConfig' => self::pushNotificationConfig($config), 'taskId' => $m[1]];
    }

    /**
     * @throws InvalidParamsError for a malformed resource name
     */
    public static function taskPushNotificationConfig(P\TaskPushNotificationConfig $config): \stdClass
    {
        if (preg_match(self::TASK_PUSH_CONFIG_NAME, $config->getName(), $m) !== 1) {
            throw new InvalidParamsError('Bad TaskPushNotificationConfig resource name ' . $config->getName());
        }

        return (object) [
            'pushNotificationConfig' => self::pushNotificationConfig($config->getPushNotificationConfig() ?? new P\PushNotificationConfig()),
            'taskId' => $m[1],
        ];
    }

    public static function streamResponse(P\StreamResponse $response): \stdClass
    {
        return match ($response->getPayload()) {
            'msg' => self::message($response->getMsg() ?? new P\Message()),
            'task' => self::task($response->getTask() ?? new P\Task()),
            'status_update' => self::taskStatusUpdateEvent($response->getStatusUpdate() ?? new P\TaskStatusUpdateEvent()),
            'artifact_update' => self::taskArtifactUpdateEvent($response->getArtifactUpdate() ?? new P\TaskArtifactUpdateEvent()),
            default => throw new \ValueError('Unsupported StreamResponse type'),
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function compact(array $values): \stdClass
    {
        return (object) array_filter($values, static fn(mixed $value): bool => $value !== null);
    }

    private static function nonEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * @param iterable<mixed> $values
     *
     * @return list<mixed>|null
     */
    private static function listOrNull(iterable $values): ?array
    {
        $list = [];
        foreach ($values as $value) {
            $list[] = $value;
        }

        return $list === [] ? null : $list;
    }
}
