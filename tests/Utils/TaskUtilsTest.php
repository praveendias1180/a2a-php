<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Helpers\ProtoHelpers;
use A2A\Types\Artifact;
use A2A\Types\GetTaskRequest;
use A2A\Types\ListTasksRequest;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageConfiguration;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\TaskUtils;
use PHPUnit\Framework\TestCase;

/**
 * Ported from a2a-python tests/utils/test_task.py.
 */
final class TaskUtilsTest extends TestCase
{
    private const PAGE_TOKEN = 'd47a95ba-0f39-4459-965b-3923cdd2ff58';
    private const ENCODED_PAGE_TOKEN = 'ZDQ3YTk1YmEtMGYzOS00NDU5LTk2NWItMzkyM2NkZDJmZjU4';

    /** @var list<Message> */
    private array $history;
    private Task $task;

    protected function setUp(): void
    {
        $this->history = [];
        for ($i = 0; $i < 5; $i++) {
            $this->history[] = new Message(['message_id' => (string) $i, 'role' => Role::ROLE_USER, 'parts' => [new Part(['text' => "msg {$i}"])]]);
        }
        $this->task = ProtoHelpers::newTask(
            't1',
            'c1',
            TaskState::TASK_STATE_COMPLETED,
            [new Artifact(['artifact_id' => 'a1', 'parts' => [new Part(['text' => 'a'])]])],
            $this->history,
        );
    }

    public function testEncodePageToken(): void
    {
        self::assertSame(self::ENCODED_PAGE_TOKEN, TaskUtils::encodePageToken(self::PAGE_TOKEN));
    }

    public function testDecodePageTokenSucceeds(): void
    {
        self::assertSame(self::PAGE_TOKEN, TaskUtils::decodePageToken(self::ENCODED_PAGE_TOKEN));
    }

    public function testDecodePageTokenWithoutPadding(): void
    {
        self::assertSame('ab', TaskUtils::decodePageToken(rtrim(base64_encode('ab'), '=')));
    }

    public function testDecodePageTokenFails(): void
    {
        $this->expectException(InvalidParamsError::class);
        $this->expectExceptionMessage('Token is not a valid base64-encoded cursor.');

        TaskUtils::decodePageToken('invalid');
    }

    public function testNullConfigReturnsFullHistory(): void
    {
        self::assertSame($this->task, TaskUtils::applyHistoryLength($this->task, null));
    }

    public function testUnsetHistoryLengthReturnsFullHistory(): void
    {
        $result = TaskUtils::applyHistoryLength($this->task, new GetTaskRequest());

        self::assertSame($this->history, $this->historyOf($result));
    }

    public function testPositiveHistoryLengthTruncates(): void
    {
        $result = TaskUtils::applyHistoryLength($this->task, new GetTaskRequest(['history_length' => 2]));

        self::assertSame(['3', '4'], array_map(static fn(Message $m): string => $m->getMessageId(), $this->historyOf($result)));
        self::assertCount(5, $this->task->getHistory(), 'the original task is not modified');
    }

    public function testLargeHistoryLengthReturnsFullHistory(): void
    {
        $result = TaskUtils::applyHistoryLength($this->task, new GetTaskRequest(['history_length' => 10]));

        self::assertSame($this->history, $this->historyOf($result));
    }

    public function testZeroHistoryLengthReturnsEmptyHistory(): void
    {
        $result = TaskUtils::applyHistoryLength($this->task, new SendMessageConfiguration(['history_length' => 0]));

        self::assertCount(0, $result->getHistory());
        self::assertCount(1, $result->getArtifacts());
        self::assertCount(5, $this->task->getHistory());
    }

    public function testValidateHistoryLength(): void
    {
        TaskUtils::validateHistoryLength(null);
        TaskUtils::validateHistoryLength(new ListTasksRequest(['history_length' => 0]));

        $this->expectException(InvalidParamsError::class);
        $this->expectExceptionMessage('history length must be non-negative');
        TaskUtils::validateHistoryLength(new ListTasksRequest(['history_length' => -1]));
    }

    public function testValidatePageSize(): void
    {
        TaskUtils::validatePageSize(1);
        TaskUtils::validatePageSize(100);

        try {
            TaskUtils::validatePageSize(0);
            self::fail('expected InvalidParamsError');
        } catch (InvalidParamsError $e) {
            self::assertSame('minimum page size is 1', $e->getMessage());
        }

        $this->expectException(InvalidParamsError::class);
        $this->expectExceptionMessage('maximum page size is 100');
        TaskUtils::validatePageSize(101);
    }

    /**
     * @return list<Message>
     */
    private function historyOf(Task $task): array
    {
        /** @var list<Message> */
        return iterator_to_array($task->getHistory(), false);
    }
}
