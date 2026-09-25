<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\TaskStore;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\ListTasksRequest;
use A2A\Types\Task;
use A2A\Types\TaskState;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\TaskUtils;
use Google\Protobuf\Timestamp;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every TaskStore must have. Ported from a2a-python
 * tests/server/tasks/test_inmemory_task_store.py and
 * test_database_task_store.py, which test the same contract.
 */
abstract class TaskStoreContract extends TestCase
{
    abstract protected function createStore(): TaskStore;

    public function testSaveAndGet(): void
    {
        $store = $this->createStore();
        $task = Fixtures::task('task-abc', TaskState::TASK_STATE_SUBMITTED, 'ctx-1', 1000);
        $task->setHistory([Fixtures::userMessage('hi', 'm-1')]);

        $store->save($task, Fixtures::callContext());
        $loaded = $store->get('task-abc', Fixtures::callContext());

        self::assertNotNull($loaded);
        self::assertSame($task->serializeToJsonString(), $loaded->serializeToJsonString());
    }

    public function testGetMissingReturnsNull(): void
    {
        self::assertNull($this->createStore()->get('nonexistent', Fixtures::callContext()));
    }

    public function testSaveReplacesTheTask(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), Fixtures::callContext());
        $store->save(Fixtures::task('t', TaskState::TASK_STATE_COMPLETED), Fixtures::callContext());

        self::assertSame(TaskState::TASK_STATE_COMPLETED, $store->get('t', Fixtures::callContext())?->getStatus()?->getState());
        self::assertSame(1, $store->list(new ListTasksRequest(), Fixtures::callContext())->getTotalSize());
    }

    public function testDelete(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('t', TaskState::TASK_STATE_WORKING), Fixtures::callContext());

        $store->delete('t', Fixtures::callContext());
        $store->delete('never-existed', Fixtures::callContext());

        self::assertNull($store->get('t', Fixtures::callContext()));
    }

    public function testOwnersAreIsolated(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('alice-task', TaskState::TASK_STATE_WORKING), Fixtures::callContext('alice'));

        self::assertNotNull($store->get('alice-task', Fixtures::callContext('alice')));
        self::assertNull($store->get('alice-task', Fixtures::callContext('bob')));
        self::assertNull($store->get('alice-task', Fixtures::callContext()));
        self::assertCount(0, $store->list(new ListTasksRequest(), Fixtures::callContext('bob'))->getTasks());

        $store->delete('alice-task', Fixtures::callContext('bob'));
        self::assertNotNull($store->get('alice-task', Fixtures::callContext('alice')), "bob can't delete alice's task");
    }

    public function testListSortsByStatusTimestampNewestFirstThenIdDesc(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('old', TaskState::TASK_STATE_COMPLETED, 'c', 100), Fixtures::callContext());
        $store->save(Fixtures::task('new', TaskState::TASK_STATE_COMPLETED, 'c', 300), Fixtures::callContext());
        $store->save(Fixtures::task('mid-a', TaskState::TASK_STATE_COMPLETED, 'c', 200), Fixtures::callContext());
        $store->save(Fixtures::task('mid-b', TaskState::TASK_STATE_COMPLETED, 'c', 200), Fixtures::callContext());
        $store->save(Fixtures::task('no-timestamp', TaskState::TASK_STATE_SUBMITTED, 'c'), Fixtures::callContext());

        $page = $store->list(new ListTasksRequest(), Fixtures::callContext());

        self::assertSame(['new', 'mid-b', 'mid-a', 'old', 'no-timestamp'], self::ids($page->getTasks()));
        self::assertSame(5, $page->getTotalSize());
        self::assertSame('', $page->getNextPageToken());
    }

    public function testListFilters(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('a', TaskState::TASK_STATE_COMPLETED, 'ctx-1', 100), Fixtures::callContext());
        $store->save(Fixtures::task('b', TaskState::TASK_STATE_WORKING, 'ctx-1', 200), Fixtures::callContext());
        $store->save(Fixtures::task('c', TaskState::TASK_STATE_COMPLETED, 'ctx-2', 300), Fixtures::callContext());

        self::assertSame(['b', 'a'], self::ids($store->list(new ListTasksRequest(['context_id' => 'ctx-1']), Fixtures::callContext())->getTasks()));
        self::assertSame(['c', 'a'], self::ids($store->list(new ListTasksRequest(['status' => TaskState::TASK_STATE_COMPLETED]), Fixtures::callContext())->getTasks()));

        $after = new ListTasksRequest(['status_timestamp_after' => new Timestamp(['seconds' => 200])]);
        $page = $store->list($after, Fixtures::callContext());
        self::assertSame(['c', 'b'], self::ids($page->getTasks()));
        self::assertSame(2, $page->getTotalSize());
    }

    public function testListPaginates(): void
    {
        $store = $this->createStore();
        foreach (range(1, 5) as $i) {
            $store->save(Fixtures::task("task-{$i}", TaskState::TASK_STATE_COMPLETED, 'c', 100 * $i), Fixtures::callContext());
        }

        $first = $store->list(new ListTasksRequest(['page_size' => 2]), Fixtures::callContext());
        self::assertSame(['task-5', 'task-4'], self::ids($first->getTasks()));
        self::assertSame(2, $first->getPageSize());
        self::assertSame(5, $first->getTotalSize());
        self::assertSame(TaskUtils::encodePageToken('task-3'), $first->getNextPageToken());

        $second = $store->list(new ListTasksRequest(['page_size' => 2, 'page_token' => $first->getNextPageToken()]), Fixtures::callContext());
        self::assertSame(['task-3', 'task-2'], self::ids($second->getTasks()));

        $third = $store->list(new ListTasksRequest(['page_size' => 2, 'page_token' => $second->getNextPageToken()]), Fixtures::callContext());
        self::assertSame(['task-1'], self::ids($third->getTasks()));
        self::assertSame('', $third->getNextPageToken());
    }

    public function testListPaginatesAcrossTasksWithoutTimestamps(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('dated', TaskState::TASK_STATE_COMPLETED, 'c', 100), Fixtures::callContext());
        foreach (['u1', 'u2', 'u3'] as $id) {
            $store->save(Fixtures::task($id, TaskState::TASK_STATE_SUBMITTED, 'c'), Fixtures::callContext());
        }

        $seen = [];
        $token = '';
        do {
            $page = $store->list(new ListTasksRequest(['page_size' => 2, 'page_token' => $token]), Fixtures::callContext());
            $seen = [...$seen, ...self::ids($page->getTasks())];
            $token = $page->getNextPageToken();
        } while ($token !== '');

        self::assertSame(['dated', 'u3', 'u2', 'u1'], $seen);
    }

    public function testListDefaultPageSizeIs50(): void
    {
        $store = $this->createStore();
        foreach (range(1, 51) as $i) {
            $store->save(Fixtures::task(sprintf('t-%02d', $i), TaskState::TASK_STATE_COMPLETED, 'c', $i), Fixtures::callContext());
        }

        $page = $store->list(new ListTasksRequest(), Fixtures::callContext());

        self::assertCount(50, $page->getTasks());
        self::assertSame(50, $page->getPageSize());
        self::assertSame(TaskUtils::encodePageToken('t-01'), $page->getNextPageToken());
    }

    public function testListRejectsUnknownPageToken(): void
    {
        $store = $this->createStore();
        $store->save(Fixtures::task('t', TaskState::TASK_STATE_COMPLETED, 'c', 1), Fixtures::callContext());

        $this->expectException(InvalidParamsError::class);
        $store->list(new ListTasksRequest(['page_token' => TaskUtils::encodePageToken('nope')]), Fixtures::callContext());
    }

    public function testReturnedTasksAreCopies(): void
    {
        $store = $this->createStore();
        $task = Fixtures::task('t', TaskState::TASK_STATE_WORKING);
        $store->save($task, Fixtures::callContext());

        $task->setContextId('mutated-after-save');
        $loaded = $store->get('t', Fixtures::callContext());
        self::assertNotNull($loaded);
        $loaded->setContextId('mutated-after-get');

        self::assertSame('ctx-1', $store->get('t', Fixtures::callContext())?->getContextId());
    }

    /**
     * @param iterable<Task> $tasks
     *
     * @return list<string>
     */
    protected static function ids(iterable $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = $task->getId();
        }

        return $ids;
    }
}
