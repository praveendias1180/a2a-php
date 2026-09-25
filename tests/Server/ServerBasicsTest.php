<?php

declare(strict_types=1);

namespace A2A\Tests\Server;

use A2A\Server\IdGeneratorContext;
use A2A\Server\OwnerResolver;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Server\Tasks\TaskStates;
use A2A\Server\UuidGenerator;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors a2a-python tests/server/test_owner_resolver.py,
 * tests/server/tasks/test_id_generator.py and
 * test_inmemory_push_notifications.py (the config store part).
 */
final class ServerBasicsTest extends TestCase
{
    public function testOwnerResolverUsesTheUserName(): void
    {
        self::assertSame('alice', OwnerResolver::resolveUserScope(Fixtures::callContext('alice')));
        self::assertSame('', (OwnerResolver::default())(new ServerCallContext()));
    }

    public function testUuidGenerator(): void
    {
        $generator = new UuidGenerator();
        $ids = array_map(static fn(): string => $generator->generate(new IdGeneratorContext('t', 'c')), range(1, 50));

        self::assertCount(50, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
    }

    public function testCallContextHeaders(): void
    {
        self::assertNull((new ServerCallContext())->headers());
        self::assertSame(['a2a-version' => '1.0'], (new ServerCallContext(state: ['headers' => ['a2a-version' => '1.0', 'bad' => ['x']]]))->headers());
    }

    public function testTaskStates(): void
    {
        self::assertTrue(TaskStates::isTerminal(TaskState::TASK_STATE_REJECTED));
        self::assertFalse(TaskStates::isTerminal(TaskState::TASK_STATE_INPUT_REQUIRED));
        self::assertTrue(TaskStates::isInterrupted(TaskState::TASK_STATE_AUTH_REQUIRED));
        self::assertSame('TASK_STATE_WORKING', TaskStates::name(TaskState::TASK_STATE_WORKING));
        self::assertSame('999', TaskStates::name(999));
    }

    public function testPushConfigStoreIsScopedAndDefaultsTheId(): void
    {
        $store = new InMemoryPushNotificationConfigStore();
        $store->setInfo('t-1', new TaskPushNotificationConfig(['url' => 'https://a']), Fixtures::callContext('alice'));
        $store->setInfo('t-1', new TaskPushNotificationConfig(['id' => 'second', 'url' => 'https://b']), Fixtures::callContext('alice'));

        $configs = $store->getInfo('t-1', Fixtures::callContext('alice'));
        self::assertSame(['t-1', 'second'], array_map(static fn(TaskPushNotificationConfig $c): string => $c->getId(), $configs));
        self::assertSame('t-1', $configs[1]->getTaskId());
        self::assertSame([], $store->getInfo('t-1', Fixtures::callContext('bob')));

        $store->deleteInfo('t-1', Fixtures::callContext('alice'), 'second');
        self::assertCount(1, $store->getInfo('t-1', Fixtures::callContext('alice')));
        $store->deleteInfo('t-1', Fixtures::callContext('alice'));
        self::assertSame([], $store->getInfo('t-1', Fixtures::callContext('alice')));
    }
}
