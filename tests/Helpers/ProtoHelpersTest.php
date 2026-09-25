<?php

declare(strict_types=1);

namespace A2A\Tests\Helpers;

use A2A\Helpers\ProtoHelpers;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskState;
use Google\Protobuf\Value;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/helpers/test_proto_helpers.py.
 */
final class ProtoHelpersTest extends TestCase
{
    // --- Message helpers ---

    public function testNewMessage(): void
    {
        $parts = [new Part(['text' => 'hello'])];
        $message = ProtoHelpers::newMessage($parts, 'ctx1', 'task1', Role::ROLE_USER);

        self::assertSame(Role::ROLE_USER, $message->getRole());
        self::assertSame($parts, iterator_to_array($message->getParts(), false));
        self::assertSame('ctx1', $message->getContextId());
        self::assertSame('task1', $message->getTaskId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $message->getMessageId());
    }

    public function testNewMessageDefaultsToAgentRoleAndLeavesIdsUnset(): void
    {
        $message = ProtoHelpers::newMessage([new Part(['text' => 'hi'])]);

        self::assertSame(Role::ROLE_AGENT, $message->getRole());
        self::assertSame('', $message->getContextId());
        self::assertSame('', $message->getTaskId());
        self::assertNotSame($message->getMessageId(), ProtoHelpers::newMessage([])->getMessageId());
    }

    public function testNewTextMessage(): void
    {
        $message = ProtoHelpers::newTextMessage('hello', 'text/plain', 'ctx1', 'task1', Role::ROLE_USER);

        self::assertSame(Role::ROLE_USER, $message->getRole());
        self::assertCount(1, $message->getParts());
        self::assertSame('hello', $this->part($message)->getText());
        self::assertSame('text/plain', $this->part($message)->getMediaType());
        self::assertSame('ctx1', $message->getContextId());
        self::assertSame('task1', $message->getTaskId());
        self::assertNotSame('', $message->getMessageId());
    }

    public function testNewDataMessage(): void
    {
        $message = ProtoHelpers::newDataMessage(['key' => 'value'], 'application/json', 'ctx1', 'task1', Role::ROLE_USER);
        $part = $this->part($message);

        self::assertTrue($part->hasData());
        self::assertSame('value', self::structField($part->getData(), 'key')->getStringValue());
        self::assertSame('application/json', $part->getMediaType());
        self::assertSame('ctx1', $message->getContextId());
        self::assertSame('task1', $message->getTaskId());
    }

    public function testNewRawMessage(): void
    {
        $message = ProtoHelpers::newRawMessage("\x89PNG", 'image/png', 'img.png', 'ctx1', 'task1', Role::ROLE_USER);
        $part = $this->part($message);

        self::assertTrue($part->hasRaw());
        self::assertSame("\x89PNG", $part->getRaw());
        self::assertSame('image/png', $part->getMediaType());
        self::assertSame('img.png', $part->getFilename());
        self::assertSame(Role::ROLE_USER, $message->getRole());
    }

    public function testNewUrlMessage(): void
    {
        $message = ProtoHelpers::newUrlMessage('https://example.com/file.pdf', 'application/pdf', 'file.pdf', 'ctx1', 'task1', Role::ROLE_USER);
        $part = $this->part($message);

        self::assertTrue($part->hasUrl());
        self::assertSame('https://example.com/file.pdf', $part->getUrl());
        self::assertSame('application/pdf', $part->getMediaType());
        self::assertSame('file.pdf', $part->getFilename());
    }

    public function testGetMessageText(): void
    {
        $message = new Message(['parts' => [new Part(['text' => 'hello']), new Part(['text' => 'world'])]]);

        self::assertSame("hello\nworld", ProtoHelpers::getMessageText($message));
        self::assertSame('hello world', ProtoHelpers::getMessageText($message, ' '));
    }

    // --- Artifact helpers ---

    public function testNewArtifact(): void
    {
        $parts = [new Part(['text' => 'content'])];
        $artifact = ProtoHelpers::newArtifact($parts, 'test', 'desc');

        self::assertSame('test', $artifact->getName());
        self::assertSame('desc', $artifact->getDescription());
        self::assertSame($parts, iterator_to_array($artifact->getParts(), false));
        self::assertNotSame('', $artifact->getArtifactId());
    }

    public function testNewTextArtifact(): void
    {
        $artifact = ProtoHelpers::newTextArtifact('test', 'content', null, 'desc');

        self::assertSame('test', $artifact->getName());
        self::assertSame('desc', $artifact->getDescription());
        self::assertSame(['content'], ProtoHelpers::getTextParts($artifact->getParts()));
        self::assertNotSame('', $artifact->getArtifactId());
    }

    public function testNewTextArtifactWithId(): void
    {
        self::assertSame('art1', ProtoHelpers::newTextArtifact('test', 'content', null, 'desc', 'art1')->getArtifactId());
    }

    public function testNewDataArtifact(): void
    {
        $artifact = ProtoHelpers::newDataArtifact('result', ['score' => 1.0], null, 'desc');
        $part = $artifact->getParts()[0];

        self::assertSame('result', $artifact->getName());
        self::assertSame('desc', $artifact->getDescription());
        self::assertTrue($part->hasData());
        self::assertSame(1.0, self::structField($part->getData(), 'score')->getNumberValue());
        self::assertNotSame('', $artifact->getArtifactId());
    }

    public function testNewDataArtifactWithId(): void
    {
        $artifact = ProtoHelpers::newDataArtifact('result', ['x' => 'y'], null, null, 'art1');

        self::assertSame('art1', $artifact->getArtifactId());
        self::assertSame([['x' => 'y']], ProtoHelpers::getDataParts($artifact->getParts()));
    }

    public function testNewRawArtifact(): void
    {
        $artifact = ProtoHelpers::newRawArtifact('screenshot', "\x89PNG", 'image/png', 'screen.png', 'desc', 'art1');
        $part = $artifact->getParts()[0];

        self::assertSame(['screenshot', 'desc', 'art1'], [$artifact->getName(), $artifact->getDescription(), $artifact->getArtifactId()]);
        self::assertSame(["\x89PNG", 'image/png', 'screen.png'], [$part->getRaw(), $part->getMediaType(), $part->getFilename()]);
    }

    public function testNewRawArtifactMinimal(): void
    {
        $artifact = ProtoHelpers::newRawArtifact('file', 'data');

        self::assertSame(['data'], ProtoHelpers::getRawParts($artifact->getParts()));
        self::assertNotSame('', $artifact->getArtifactId());
    }

    public function testNewUrlArtifact(): void
    {
        $artifact = ProtoHelpers::newUrlArtifact('report', 'https://example.com/report.pdf', 'application/pdf', 'report.pdf', 'desc', 'art1');
        $part = $artifact->getParts()[0];

        self::assertSame(['report', 'desc', 'art1'], [$artifact->getName(), $artifact->getDescription(), $artifact->getArtifactId()]);
        self::assertSame(['https://example.com/report.pdf', 'application/pdf', 'report.pdf'], [$part->getUrl(), $part->getMediaType(), $part->getFilename()]);
    }

    public function testNewUrlArtifactMinimal(): void
    {
        $artifact = ProtoHelpers::newUrlArtifact('img', 'https://example.com/img.png');

        self::assertSame(['https://example.com/img.png'], ProtoHelpers::getUrlParts($artifact->getParts()));
        self::assertNotSame('', $artifact->getArtifactId());
    }

    public function testGetArtifactText(): void
    {
        $artifact = new Artifact(['parts' => [new Part(['text' => 'hello']), new Part(['text' => 'world'])]]);

        self::assertSame("hello\nworld", ProtoHelpers::getArtifactText($artifact));
        self::assertSame('hello world', ProtoHelpers::getArtifactText($artifact, ' '));
    }

    // --- Task helpers ---

    public function testNewTaskFromUserMessage(): void
    {
        $message = new Message(['role' => Role::ROLE_USER, 'parts' => [new Part(['text' => 'hello'])], 'task_id' => 'task1', 'context_id' => 'ctx1']);
        $task = ProtoHelpers::newTaskFromUserMessage($message);

        self::assertSame('task1', $task->getId());
        self::assertSame('ctx1', $task->getContextId());
        self::assertSame(TaskState::TASK_STATE_SUBMITTED, $task->getStatus()?->getState());
        self::assertCount(1, $task->getHistory());
        self::assertSame($message, $task->getHistory()[0]);
    }

    public function testNewTaskFromUserMessageGeneratesIds(): void
    {
        $task = ProtoHelpers::newTaskFromUserMessage(new Message(['role' => Role::ROLE_USER, 'parts' => [new Part(['text' => 'hi'])]]));

        self::assertNotSame('', $task->getId());
        self::assertNotSame('', $task->getContextId());
    }

    public function testNewTaskFromUserMessageRejectsAgentMessages(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message must be from a user');

        ProtoHelpers::newTaskFromUserMessage(new Message(['role' => Role::ROLE_AGENT, 'parts' => [new Part(['text' => 'hi'])]]));
    }

    public function testNewTaskFromUserMessageEmptyParts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message parts cannot be empty');

        ProtoHelpers::newTaskFromUserMessage(new Message(['role' => Role::ROLE_USER, 'parts' => []]));
    }

    public function testNewTaskFromUserMessageEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message.text cannot be empty');

        ProtoHelpers::newTaskFromUserMessage(new Message(['role' => Role::ROLE_USER, 'parts' => [new Part(['text' => ''])]]));
    }

    public function testNewTask(): void
    {
        $task = ProtoHelpers::newTask('task1', 'ctx1', TaskState::TASK_STATE_WORKING);

        self::assertSame('task1', $task->getId());
        self::assertSame('ctx1', $task->getContextId());
        self::assertSame(TaskState::TASK_STATE_WORKING, $task->getStatus()?->getState());
        self::assertCount(0, $task->getHistory());
        self::assertCount(0, $task->getArtifacts());
    }

    // --- Part helpers ---

    public function testGetTextParts(): void
    {
        $parts = [new Part(['text' => 'hello']), new Part(['url' => 'http://example.com']), new Part(['text' => 'world'])];

        self::assertSame(['hello', 'world'], ProtoHelpers::getTextParts($parts));
    }

    public function testNewTextPart(): void
    {
        $part = ProtoHelpers::newTextPart('hello');

        self::assertTrue($part->hasText());
        self::assertSame('hello', $part->getText());
        self::assertSame('', $part->getMediaType());
        self::assertSame('text/markdown', ProtoHelpers::newTextPart('# Hello', 'text/markdown')->getMediaType());
    }

    public function testNewDataPartFromDict(): void
    {
        $part = ProtoHelpers::newDataPart(['key' => 'value', 'count' => 42]);
        self::assertTrue($part->hasData());
        self::assertSame('value', self::structField($part->getData(), 'key')->getStringValue());
        self::assertSame(42.0, self::structField($part->getData(), 'count')->getNumberValue());
        self::assertSame('', $part->getMediaType());
        self::assertSame('application/json', ProtoHelpers::newDataPart(['key' => 'value'], 'application/json')->getMediaType());
    }

    public function testNewDataPartFromList(): void
    {
        $values = ProtoHelpers::newDataPart([1, 2, 3])->getData()?->getListValue()?->getValues();

        self::assertNotNull($values);
        self::assertSame([1.0, 2.0, 3.0], array_map(static fn(Value $v): float => $v->getNumberValue(), iterator_to_array($values, false)));
    }

    public function testNewRawPart(): void
    {
        $part = ProtoHelpers::newRawPart("\x89PNG", 'image/png', 'img.png');

        self::assertTrue($part->hasRaw());
        self::assertSame(["\x89PNG", 'image/png', 'img.png'], [$part->getRaw(), $part->getMediaType(), $part->getFilename()]);

        $minimal = ProtoHelpers::newRawPart('data');
        self::assertSame(['data', '', ''], [$minimal->getRaw(), $minimal->getMediaType(), $minimal->getFilename()]);
    }

    public function testNewUrlPart(): void
    {
        $part = ProtoHelpers::newUrlPart('https://example.com/file.pdf', 'application/pdf', 'file.pdf');

        self::assertTrue($part->hasUrl());
        self::assertSame(['https://example.com/file.pdf', 'application/pdf', 'file.pdf'], [$part->getUrl(), $part->getMediaType(), $part->getFilename()]);

        $minimal = ProtoHelpers::newUrlPart('https://example.com/img.png');
        self::assertSame(['https://example.com/img.png', '', ''], [$minimal->getUrl(), $minimal->getMediaType(), $minimal->getFilename()]);
    }

    // --- Event & stream helpers ---

    public function testNewTextStatusUpdateEvent(): void
    {
        $event = ProtoHelpers::newTextStatusUpdateEvent('task1', 'ctx1', TaskState::TASK_STATE_WORKING, 'progress');
        $message = $event->getStatus()?->getMessage();

        self::assertSame(['task1', 'ctx1'], [$event->getTaskId(), $event->getContextId()]);
        self::assertSame(TaskState::TASK_STATE_WORKING, $event->getStatus()?->getState());
        self::assertNotNull($message);
        self::assertSame('progress', ProtoHelpers::getMessageText($message));
        self::assertSame(Role::ROLE_AGENT, $message->getRole());
        self::assertSame(['task1', 'ctx1'], [$message->getTaskId(), $message->getContextId()]);
    }

    public function testNewTextArtifactUpdateEvent(): void
    {
        $event = ProtoHelpers::newTextArtifactUpdateEvent('task1', 'ctx1', 'test', 'content', true, true);

        self::assertSame(['task1', 'ctx1'], [$event->getTaskId(), $event->getContextId()]);
        $artifact = $event->getArtifact();
        self::assertNotNull($artifact);
        self::assertSame('test', $artifact->getName());
        self::assertSame('content', ProtoHelpers::getArtifactText($artifact));
        self::assertTrue($event->getAppend());
        self::assertTrue($event->getLastChunk());
    }

    public function testNewTextArtifactUpdateEventWithId(): void
    {
        $event = ProtoHelpers::newTextArtifactUpdateEvent('task1', 'ctx1', 'test', 'content', artifactId: 'art1');

        self::assertSame('art1', $event->getArtifact()?->getArtifactId());
        self::assertFalse($event->getAppend());
        self::assertFalse($event->getLastChunk());
    }

    public function testGetStreamResponseTextMessage(): void
    {
        self::assertSame('hello', ProtoHelpers::getStreamResponseText(new StreamResponse(['message' => new Message(['parts' => [new Part(['text' => 'hello'])]])])));
    }

    public function testGetStreamResponseTextTask(): void
    {
        $task = new Task(['artifacts' => [new Artifact(['parts' => [new Part(['text' => 'hello'])]]), new Artifact(), new Artifact(['parts' => [new Part(['text' => 'again'])]])]]);

        self::assertSame("hello\nagain", ProtoHelpers::getStreamResponseText(new StreamResponse(['task' => $task])));
    }

    public function testGetStreamResponseTextStatusUpdate(): void
    {
        $event = ProtoHelpers::newTextStatusUpdateEvent('t', 'c', TaskState::TASK_STATE_WORKING, 'hello');

        self::assertSame('hello', ProtoHelpers::getStreamResponseText(new StreamResponse(['status_update' => $event])));
    }

    public function testGetStreamResponseTextArtifactUpdate(): void
    {
        $event = ProtoHelpers::newTextArtifactUpdateEvent('t', 'c', 'n', 'hello');

        self::assertSame('hello', ProtoHelpers::getStreamResponseText(new StreamResponse(['artifact_update' => $event])));
    }

    public function testGetStreamResponseTextEmpty(): void
    {
        self::assertSame('', ProtoHelpers::getStreamResponseText(new StreamResponse()));
    }

    // --- Part extractors ---

    public function testGetDataParts(): void
    {
        $parts = [ProtoHelpers::newDataPart(['key' => 'value']), new Part(['text' => 'hello']), ProtoHelpers::newDataPart([1, 2])];

        self::assertSame([['key' => 'value'], [1, 2]], ProtoHelpers::getDataParts($parts));
        self::assertSame([], ProtoHelpers::getDataParts([new Part(['text' => 'hello']), new Part(['url' => 'http://example.com'])]));
    }

    public function testGetRawParts(): void
    {
        $parts = [new Part(['raw' => "\x89PNG"]), new Part(['text' => 'hello']), new Part(['raw' => "\xff\xd8"])];

        self::assertSame(["\x89PNG", "\xff\xd8"], ProtoHelpers::getRawParts($parts));
        self::assertSame([], ProtoHelpers::getRawParts([new Part(['text' => 'hello'])]));
    }

    public function testGetUrlParts(): void
    {
        $parts = [new Part(['url' => 'https://example.com/a.png']), new Part(['text' => 'hello']), new Part(['url' => 'https://example.com/b.pdf'])];

        self::assertSame(['https://example.com/a.png', 'https://example.com/b.pdf'], ProtoHelpers::getUrlParts($parts));
        self::assertSame([], ProtoHelpers::getUrlParts([new Part(['text' => 'hello'])]));
    }

    // --- Non-text artifact update events ---

    public function testNewDataArtifactUpdateEvent(): void
    {
        $event = ProtoHelpers::newDataArtifactUpdateEvent('task1', 'ctx1', 'result', ['score' => 0.95], 'application/json', true, true, 'art1');
        $artifact = $event->getArtifact() ?? new Artifact();
        $part = $artifact->getParts()[0];

        self::assertSame(['task1', 'ctx1', 'result', 'art1'], [$event->getTaskId(), $event->getContextId(), $artifact->getName(), $artifact->getArtifactId()]);
        self::assertSame(0.95, self::structField($part->getData(), 'score')->getNumberValue());
        self::assertSame('application/json', $part->getMediaType());
        self::assertTrue($event->getAppend());
        self::assertTrue($event->getLastChunk());
    }

    public function testNewDataArtifactUpdateEventMinimal(): void
    {
        $event = ProtoHelpers::newDataArtifactUpdateEvent('task1', 'ctx1', 'result', [1, 2, 3]);

        self::assertTrue(($event->getArtifact() ?? new Artifact())->getParts()[0]->hasData());
        self::assertFalse($event->getAppend());
        self::assertFalse($event->getLastChunk());
        self::assertNotSame('', $event->getArtifact()?->getArtifactId());
    }

    public function testNewRawArtifactUpdateEvent(): void
    {
        $event = ProtoHelpers::newRawArtifactUpdateEvent('task1', 'ctx1', 'screenshot', "\x89PNG", 'image/png', 'screen.png', false, true, 'art1');
        $artifact = $event->getArtifact() ?? new Artifact();
        $part = $artifact->getParts()[0];

        self::assertSame(['screenshot', 'art1'], [$artifact->getName(), $artifact->getArtifactId()]);
        self::assertSame(["\x89PNG", 'image/png', 'screen.png'], [$part->getRaw(), $part->getMediaType(), $part->getFilename()]);
        self::assertTrue($event->getLastChunk());
        self::assertSame(['data'], ProtoHelpers::getRawParts((ProtoHelpers::newRawArtifactUpdateEvent('t', 'c', 'file', 'data')->getArtifact() ?? new Artifact())->getParts()));
    }

    public function testNewUrlArtifactUpdateEvent(): void
    {
        $event = ProtoHelpers::newUrlArtifactUpdateEvent('task1', 'ctx1', 'report', 'https://example.com/report.pdf', 'application/pdf', 'report.pdf', true, false, 'art1');
        $artifact = $event->getArtifact() ?? new Artifact();
        $part = $artifact->getParts()[0];

        self::assertSame(['report', 'art1'], [$artifact->getName(), $artifact->getArtifactId()]);
        self::assertSame(['https://example.com/report.pdf', 'application/pdf', 'report.pdf'], [$part->getUrl(), $part->getMediaType(), $part->getFilename()]);
        self::assertTrue($event->getAppend());
        self::assertSame(['https://example.com/img.png'], ProtoHelpers::getUrlParts((ProtoHelpers::newUrlArtifactUpdateEvent('t', 'c', 'img', 'https://example.com/img.png')->getArtifact() ?? new Artifact())->getParts()));
    }

    private static function structField(?Value $data, string $key): Value
    {
        $value = $data?->getStructValue()?->getFields()[$key] ?? null;
        self::assertInstanceOf(Value::class, $value);

        return $value;
    }

    private function part(Message $message): Part
    {
        return $message->getParts()[0];
    }
}
