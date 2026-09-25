<?php

declare(strict_types=1);

namespace A2A\Helpers;

use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;
use A2A\Utils\Uuid;
use Google\Protobuf\Value;

/**
 * Helpers for creating and reading A2A types.
 *
 * Mirrors a2a-python: src/a2a/helpers/proto_helpers.py. Python's module
 * functions are static methods here, snake_case becomes camelCase
 * (new_text_message → ProtoHelpers::newTextMessage). `bytes` is a PHP string.
 * Where Python raises ValueError, these throw \InvalidArgumentException.
 */
final class ProtoHelpers
{
    private function __construct() {}

    // --- Message helpers ---

    /**
     * @param list<Part> $parts
     */
    public static function newMessage(array $parts, ?string $contextId = null, ?string $taskId = null, int $role = Role::ROLE_AGENT): Message
    {
        $message = new Message([
            'role' => $role,
            'parts' => $parts,
            'message_id' => Uuid::v4(),
        ]);
        if ($taskId !== null) {
            $message->setTaskId($taskId);
        }
        if ($contextId !== null) {
            $message->setContextId($contextId);
        }

        return $message;
    }

    public static function newTextMessage(string $text, ?string $mediaType = null, ?string $contextId = null, ?string $taskId = null, int $role = Role::ROLE_AGENT): Message
    {
        return self::newMessage([self::newTextPart($text, $mediaType)], $contextId, $taskId, $role);
    }

    public static function getMessageText(Message $message, string $delimiter = "\n"): string
    {
        return implode($delimiter, self::getTextParts($message->getParts()));
    }

    /**
     * @param mixed $data JSON-serializable data (array, string, number, ...)
     */
    public static function newDataMessage(mixed $data, ?string $mediaType = null, ?string $contextId = null, ?string $taskId = null, int $role = Role::ROLE_AGENT): Message
    {
        return self::newMessage([self::newDataPart($data, $mediaType)], $contextId, $taskId, $role);
    }

    public static function newRawMessage(string $raw, ?string $mediaType = null, ?string $filename = null, ?string $contextId = null, ?string $taskId = null, int $role = Role::ROLE_AGENT): Message
    {
        return self::newMessage([self::newRawPart($raw, $mediaType, $filename)], $contextId, $taskId, $role);
    }

    public static function newUrlMessage(string $url, ?string $mediaType = null, ?string $filename = null, ?string $contextId = null, ?string $taskId = null, int $role = Role::ROLE_AGENT): Message
    {
        return self::newMessage([self::newUrlPart($url, $mediaType, $filename)], $contextId, $taskId, $role);
    }

    // --- Artifact helpers ---

    /**
     * @param list<Part> $parts
     */
    public static function newArtifact(array $parts, string $name, ?string $description = null, ?string $artifactId = null): Artifact
    {
        return new Artifact([
            'artifact_id' => $artifactId !== null && $artifactId !== '' ? $artifactId : Uuid::v4(),
            'parts' => $parts,
            'name' => $name,
            'description' => $description ?? '',
        ]);
    }

    public static function newTextArtifact(string $name, string $text, ?string $mediaType = null, ?string $description = null, ?string $artifactId = null): Artifact
    {
        return self::newArtifact([self::newTextPart($text, $mediaType)], $name, $description, $artifactId);
    }

    public static function newDataArtifact(string $name, mixed $data, ?string $mediaType = null, ?string $description = null, ?string $artifactId = null): Artifact
    {
        return self::newArtifact([self::newDataPart($data, $mediaType)], $name, $description, $artifactId);
    }

    public static function newRawArtifact(string $name, string $raw, ?string $mediaType = null, ?string $filename = null, ?string $description = null, ?string $artifactId = null): Artifact
    {
        return self::newArtifact([self::newRawPart($raw, $mediaType, $filename)], $name, $description, $artifactId);
    }

    public static function newUrlArtifact(string $name, string $url, ?string $mediaType = null, ?string $filename = null, ?string $description = null, ?string $artifactId = null): Artifact
    {
        return self::newArtifact([self::newUrlPart($url, $mediaType, $filename)], $name, $description, $artifactId);
    }

    public static function getArtifactText(Artifact $artifact, string $delimiter = "\n"): string
    {
        return implode($delimiter, self::getTextParts($artifact->getParts()));
    }

    // --- Task helpers ---

    /**
     * A SUBMITTED task seeded with the user's first message. Reuses the
     * message's task/context ids when set, else generates them.
     *
     * @throws \InvalidArgumentException
     */
    public static function newTaskFromUserMessage(Message $userMessage): Task
    {
        if ($userMessage->getRole() !== Role::ROLE_USER) {
            throw new \InvalidArgumentException('Message must be from a user');
        }
        if (count($userMessage->getParts()) === 0) {
            throw new \InvalidArgumentException('Message parts cannot be empty');
        }
        foreach ($userMessage->getParts() as $part) {
            if ($part->hasText() && $part->getText() === '') {
                throw new \InvalidArgumentException('Message.text cannot be empty');
            }
        }

        return new Task([
            'status' => new TaskStatus(['state' => TaskState::TASK_STATE_SUBMITTED]),
            'id' => $userMessage->getTaskId() !== '' ? $userMessage->getTaskId() : Uuid::v4(),
            'context_id' => $userMessage->getContextId() !== '' ? $userMessage->getContextId() : Uuid::v4(),
            'history' => [$userMessage],
        ]);
    }

    /**
     * @param list<Artifact>|null $artifacts
     * @param list<Message>|null  $history
     */
    public static function newTask(string $taskId, string $contextId, int $state, ?array $artifacts = null, ?array $history = null): Task
    {
        return new Task([
            'status' => new TaskStatus(['state' => $state]),
            'id' => $taskId,
            'context_id' => $contextId,
            'artifacts' => $artifacts ?? [],
            'history' => $history ?? [],
        ]);
    }

    // --- Part helpers ---

    public static function newTextPart(string $text, ?string $mediaType = null): Part
    {
        return new Part(['text' => $text, 'media_type' => $mediaType ?? '']);
    }

    /**
     * A Part holding structured data as google.protobuf.Value. An empty PHP
     * array becomes an empty list; pass `new \stdClass()` for `{}`.
     */
    public static function newDataPart(mixed $data, ?string $mediaType = null): Part
    {
        return new Part(['data' => ProtoUtils::toValue($data), 'media_type' => $mediaType ?? '']);
    }

    public static function newRawPart(string $raw, ?string $mediaType = null, ?string $filename = null): Part
    {
        return new Part(['raw' => $raw, 'media_type' => $mediaType ?? '', 'filename' => $filename ?? '']);
    }

    public static function newUrlPart(string $url, ?string $mediaType = null, ?string $filename = null): Part
    {
        return new Part(['url' => $url, 'media_type' => $mediaType ?? '', 'filename' => $filename ?? '']);
    }

    /**
     * @param iterable<mixed> $parts
     *
     * @return list<string>
     */
    public static function getTextParts(iterable $parts): array
    {
        $texts = [];
        foreach ($parts as $part) {
            if ($part instanceof Part && $part->hasText()) {
                $texts[] = $part->getText();
            }
        }

        return $texts;
    }

    /**
     * The data of every data Part, converted back to PHP values.
     *
     * @param iterable<mixed> $parts
     *
     * @return list<mixed>
     */
    public static function getDataParts(iterable $parts): array
    {
        $data = [];
        foreach ($parts as $part) {
            if ($part instanceof Part && $part->hasData()) {
                $data[] = ProtoUtils::fromValue($part->getData() ?? new Value());
            }
        }

        return $data;
    }

    /**
     * @param iterable<mixed> $parts
     *
     * @return list<string>
     */
    public static function getRawParts(iterable $parts): array
    {
        $raws = [];
        foreach ($parts as $part) {
            if ($part instanceof Part && $part->hasRaw()) {
                $raws[] = $part->getRaw();
            }
        }

        return $raws;
    }

    /**
     * @param iterable<mixed> $parts
     *
     * @return list<string>
     */
    public static function getUrlParts(iterable $parts): array
    {
        $urls = [];
        foreach ($parts as $part) {
            if ($part instanceof Part && $part->hasUrl()) {
                $urls[] = $part->getUrl();
            }
        }

        return $urls;
    }

    // --- Event & stream helpers ---

    public static function newTextStatusUpdateEvent(string $taskId, string $contextId, int $state, string $text): TaskStatusUpdateEvent
    {
        return new TaskStatusUpdateEvent([
            'task_id' => $taskId,
            'context_id' => $contextId,
            'status' => new TaskStatus([
                'state' => $state,
                'message' => self::newTextMessage($text, null, $contextId, $taskId, Role::ROLE_AGENT),
            ]),
        ]);
    }

    public static function newTextArtifactUpdateEvent(string $taskId, string $contextId, string $name, string $text, bool $append = false, bool $lastChunk = false, ?string $artifactId = null): TaskArtifactUpdateEvent
    {
        return self::artifactUpdate($taskId, $contextId, self::newTextArtifact($name, $text, null, null, $artifactId), $append, $lastChunk);
    }

    public static function newDataArtifactUpdateEvent(string $taskId, string $contextId, string $name, mixed $data, ?string $mediaType = null, bool $append = false, bool $lastChunk = false, ?string $artifactId = null): TaskArtifactUpdateEvent
    {
        return self::artifactUpdate($taskId, $contextId, self::newDataArtifact($name, $data, $mediaType, null, $artifactId), $append, $lastChunk);
    }

    public static function newRawArtifactUpdateEvent(string $taskId, string $contextId, string $name, string $raw, ?string $mediaType = null, ?string $filename = null, bool $append = false, bool $lastChunk = false, ?string $artifactId = null): TaskArtifactUpdateEvent
    {
        return self::artifactUpdate($taskId, $contextId, self::newRawArtifact($name, $raw, $mediaType, $filename, null, $artifactId), $append, $lastChunk);
    }

    public static function newUrlArtifactUpdateEvent(string $taskId, string $contextId, string $name, string $url, ?string $mediaType = null, ?string $filename = null, bool $append = false, bool $lastChunk = false, ?string $artifactId = null): TaskArtifactUpdateEvent
    {
        return self::artifactUpdate($taskId, $contextId, self::newUrlArtifact($name, $url, $mediaType, $filename, null, $artifactId), $append, $lastChunk);
    }

    /**
     * The text carried by any kind of StreamResponse ('' when it has none).
     */
    public static function getStreamResponseText(StreamResponse $response, string $delimiter = "\n"): string
    {
        $message = $response->getMessage();
        if ($message !== null) {
            return self::getMessageText($message, $delimiter);
        }
        $task = $response->getTask();
        if ($task !== null) {
            $texts = [];
            foreach ($task->getArtifacts() as $artifact) {
                $text = self::getArtifactText($artifact, $delimiter);
                if ($text !== '') {
                    $texts[] = $text;
                }
            }

            return implode($delimiter, $texts);
        }
        $statusUpdate = $response->getStatusUpdate();
        if ($statusUpdate !== null) {
            $statusMessage = $statusUpdate->getStatus()?->getMessage();

            return $statusMessage !== null ? self::getMessageText($statusMessage, $delimiter) : '';
        }
        $artifactUpdate = $response->getArtifactUpdate();
        if ($artifactUpdate !== null) {
            $artifact = $artifactUpdate->getArtifact();

            return $artifact !== null ? self::getArtifactText($artifact, $delimiter) : '';
        }

        return '';
    }

    private static function artifactUpdate(string $taskId, string $contextId, Artifact $artifact, bool $append, bool $lastChunk): TaskArtifactUpdateEvent
    {
        return new TaskArtifactUpdateEvent([
            'task_id' => $taskId,
            'context_id' => $contextId,
            'artifact' => $artifact,
            'append' => $append,
            'last_chunk' => $lastChunk,
        ]);
    }
}
