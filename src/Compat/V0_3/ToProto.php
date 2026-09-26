<?php

declare(strict_types=1);

namespace A2A\Compat\V0_3;

use A2A\Compat\V0_3\Types as P;
use Google\Protobuf\Struct;
use Google\Protobuf\Timestamp;

/**
 * v0.3 objects (the JSON-RPC shape, as \stdClass) → v0.3 protobuf messages,
 * for the v0.3 HTTP+JSON binding, which speaks the ProtoJSON of the v0.3
 * proto (`content` instead of `parts`, `TASK_STATE_CANCELLED`, ...).
 *
 * Mirrors a2a-python: ToProto in src/a2a/compat/v0_3/proto_utils.py, for the
 * types the REST binding carries. Unlike Python, the status timestamp is
 * kept.
 */
final class ToProto
{
    private const TASK_STATE = [
        'submitted' => P\TaskState::TASK_STATE_SUBMITTED,
        'working' => P\TaskState::TASK_STATE_WORKING,
        'completed' => P\TaskState::TASK_STATE_COMPLETED,
        'canceled' => P\TaskState::TASK_STATE_CANCELLED,
        'failed' => P\TaskState::TASK_STATE_FAILED,
        'input-required' => P\TaskState::TASK_STATE_INPUT_REQUIRED,
        'auth-required' => P\TaskState::TASK_STATE_AUTH_REQUIRED,
        'rejected' => P\TaskState::TASK_STATE_REJECTED,
    ];

    private function __construct() {}

    public static function message(\stdClass $message): P\Message
    {
        $proto = new P\Message([
            'message_id' => self::str($message->messageId ?? null),
            'context_id' => self::str($message->contextId ?? null),
            'task_id' => self::str($message->taskId ?? null),
            'role' => self::role(self::str($message->role ?? null)),
            'extensions' => self::strList($message->extensions ?? null),
        ]);
        $parts = [];
        foreach (self::objList($message->parts ?? null) as $part) {
            $parts[] = self::part($part);
        }
        $proto->setContent($parts);
        if (($metadata = self::metadata($message->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function metadata(mixed $metadata): ?Struct
    {
        if (!$metadata instanceof \stdClass) {
            return null;
        }
        $struct = new Struct();
        $struct->mergeFromJsonString(self::encode($metadata));

        return $struct;
    }

    public static function part(\stdClass $part): P\Part
    {
        $proto = new P\Part();
        $kind = self::str($part->kind ?? null);
        if ($kind === 'text' || ($kind === '' && isset($part->text))) {
            $proto->setText(self::str($part->text ?? null));
        } elseif ($kind === 'file' || ($kind === '' && isset($part->file))) {
            $proto->setFile(self::file($part->file instanceof \stdClass ? $part->file : new \stdClass()));
        } elseif ($kind === 'data' || ($kind === '' && isset($part->data))) {
            $data = new P\DataPart();
            $data->setData(self::metadata($part->data instanceof \stdClass ? $part->data : new \stdClass()));
            $proto->setData($data);
        } else {
            throw new \ValueError('Unsupported part type: ' . $kind);
        }
        if (($metadata = self::metadata($part->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function file(\stdClass $file): P\FilePart
    {
        $proto = new P\FilePart([
            'mime_type' => self::str($file->mimeType ?? null),
            'name' => self::str($file->name ?? null),
        ]);
        if (isset($file->uri)) {
            $proto->setFileWithUri(self::str($file->uri));
        } else {
            // v0.3 put the base64 text itself into the bytes field (Python:
            // file.bytes.encode('utf-8')), so ProtoJSON base64-encodes it again.
            $proto->setFileWithBytes(self::str($file->bytes ?? null));
        }

        return $proto;
    }

    public static function task(\stdClass $task): P\Task
    {
        $proto = new P\Task([
            'id' => self::str($task->id ?? null),
            'context_id' => self::str($task->contextId ?? null),
            'status' => self::taskStatus($task->status instanceof \stdClass ? $task->status : new \stdClass()),
        ]);
        $artifacts = [];
        foreach (self::objList($task->artifacts ?? null) as $artifact) {
            $artifacts[] = self::artifact($artifact);
        }
        $proto->setArtifacts($artifacts);
        $history = [];
        foreach (self::objList($task->history ?? null) as $message) {
            $history[] = self::message($message);
        }
        $proto->setHistory($history);
        if (($metadata = self::metadata($task->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function taskStatus(\stdClass $status): P\TaskStatus
    {
        $proto = new P\TaskStatus(['state' => self::taskState(self::str($status->state ?? null))]);
        if (($status->message ?? null) instanceof \stdClass) {
            $proto->setUpdate(self::message($status->message));
        }
        $timestamp = self::str($status->timestamp ?? null);
        if ($timestamp !== '') {
            try {
                $ts = new Timestamp();
                $ts->mergeFromJsonString(self::encode(str_replace('+00:00', 'Z', $timestamp)));
                $proto->setTimestamp($ts);
            } catch (\Throwable) {
                // Leave an unparseable timestamp out.
            }
        }

        return $proto;
    }

    public static function taskState(string $state): int
    {
        return self::TASK_STATE[$state] ?? P\TaskState::TASK_STATE_UNSPECIFIED;
    }

    public static function artifact(\stdClass $artifact): P\Artifact
    {
        $proto = new P\Artifact([
            'artifact_id' => self::str($artifact->artifactId ?? null),
            'name' => self::str($artifact->name ?? null),
            'description' => self::str($artifact->description ?? null),
            'extensions' => self::strList($artifact->extensions ?? null),
        ]);
        $parts = [];
        foreach (self::objList($artifact->parts ?? null) as $part) {
            $parts[] = self::part($part);
        }
        $proto->setParts($parts);
        if (($metadata = self::metadata($artifact->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function authenticationInfo(\stdClass $info): P\AuthenticationInfo
    {
        return new P\AuthenticationInfo([
            'schemes' => self::strList($info->schemes ?? null),
            'credentials' => self::str($info->credentials ?? null),
        ]);
    }

    public static function pushNotificationConfig(\stdClass $config): P\PushNotificationConfig
    {
        $proto = new P\PushNotificationConfig([
            'id' => self::str($config->id ?? null),
            'url' => self::str($config->url ?? null),
            'token' => self::str($config->token ?? null),
        ]);
        if (($config->authentication ?? null) instanceof \stdClass) {
            $proto->setAuthentication(self::authenticationInfo($config->authentication));
        }

        return $proto;
    }

    public static function taskArtifactUpdateEvent(\stdClass $event): P\TaskArtifactUpdateEvent
    {
        $proto = new P\TaskArtifactUpdateEvent([
            'task_id' => self::str($event->taskId ?? null),
            'context_id' => self::str($event->contextId ?? null),
            'artifact' => self::artifact($event->artifact instanceof \stdClass ? $event->artifact : new \stdClass()),
            'append' => (bool) ($event->append ?? false),
            'last_chunk' => (bool) ($event->lastChunk ?? false),
        ]);
        if (($metadata = self::metadata($event->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function taskStatusUpdateEvent(\stdClass $event): P\TaskStatusUpdateEvent
    {
        $proto = new P\TaskStatusUpdateEvent([
            'task_id' => self::str($event->taskId ?? null),
            'context_id' => self::str($event->contextId ?? null),
            'status' => self::taskStatus($event->status instanceof \stdClass ? $event->status : new \stdClass()),
            'final' => (bool) ($event->final ?? false),
        ]);
        if (($metadata = self::metadata($event->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    public static function messageSendConfiguration(?\stdClass $config): P\SendMessageConfiguration
    {
        if ($config === null) {
            return new P\SendMessageConfiguration();
        }
        $proto = new P\SendMessageConfiguration([
            'accepted_output_modes' => self::strList($config->acceptedOutputModes ?? null),
            'history_length' => is_int($config->historyLength ?? null) ? $config->historyLength : 0,
            'blocking' => (bool) ($config->blocking ?? false),
        ]);
        if (($config->pushNotificationConfig ?? null) instanceof \stdClass) {
            $proto->setPushNotification(self::pushNotificationConfig($config->pushNotificationConfig));
        }

        return $proto;
    }

    /**
     * @param \stdClass $params v0.3 MessageSendParams
     */
    public static function sendMessageRequest(\stdClass $params): P\SendMessageRequest
    {
        $proto = new P\SendMessageRequest([
            'request' => self::message($params->message instanceof \stdClass ? $params->message : new \stdClass()),
            'configuration' => self::messageSendConfiguration(($params->configuration ?? null) instanceof \stdClass ? $params->configuration : null),
        ]);
        if (($metadata = self::metadata($params->metadata ?? null)) !== null) {
            $proto->setMetadata($metadata);
        }

        return $proto;
    }

    /**
     * @param \stdClass $result a v0.3 Task or Message
     */
    public static function taskOrMessage(\stdClass $result): P\SendMessageResponse
    {
        return Conversions::resultKind($result) === 'message'
            ? new P\SendMessageResponse(['msg' => self::message($result)])
            : new P\SendMessageResponse(['task' => self::task($result)]);
    }

    public static function streamResponse(\stdClass $event): P\StreamResponse
    {
        return match (Conversions::resultKind($event)) {
            'message' => new P\StreamResponse(['msg' => self::message($event)]),
            'task' => new P\StreamResponse(['task' => self::task($event)]),
            'status-update' => new P\StreamResponse(['status_update' => self::taskStatusUpdateEvent($event)]),
            'artifact-update' => new P\StreamResponse(['artifact_update' => self::taskArtifactUpdateEvent($event)]),
            default => throw new \ValueError('Unsupported event type'),
        };
    }

    /**
     * @param \stdClass $config v0.3 TaskPushNotificationConfig ({taskId, pushNotificationConfig})
     */
    public static function taskPushNotificationConfig(\stdClass $config): P\TaskPushNotificationConfig
    {
        $inner = ($config->pushNotificationConfig ?? null) instanceof \stdClass ? $config->pushNotificationConfig : new \stdClass();

        return new P\TaskPushNotificationConfig([
            'name' => sprintf('tasks/%s/pushNotificationConfigs/%s', self::str($config->taskId ?? null), self::str($inner->id ?? null)),
            'push_notification_config' => self::pushNotificationConfig($inner),
        ]);
    }

    private static function role(string $role): int
    {
        return match ($role) {
            'user' => P\Role::ROLE_USER,
            'agent' => P\Role::ROLE_AGENT,
            default => P\Role::ROLE_UNSPECIFIED,
        };
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /**
     * @return list<string>
     */
    private static function strList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    /**
     * @return list<\stdClass>
     */
    private static function objList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn(mixed $v): bool => $v instanceof \stdClass)) : [];
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
