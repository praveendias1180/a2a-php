<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\AuthenticationInfo;
use A2A\Types\TaskPushNotificationConfig;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour every PushNotificationConfigStore shares.
 *
 * Ports tests/server/tasks/test_inmemory_push_notifications.py and
 * test_database_push_notification_config_store.py (the store parts).
 */
abstract class PushNotificationConfigStoreContract extends TestCase
{
    abstract protected function createStore(): PushNotificationConfigStore;

    public function testSetAndGetInfoSingleConfig(): void
    {
        $store = $this->createStore();
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook', 'tok'), Fixtures::callContext('alice'));

        $configs = $store->getInfo('task-1', Fixtures::callContext('alice'));
        self::assertCount(1, $configs);
        self::assertSame('cfg-1', $configs[0]->getId());
        self::assertSame('task-1', $configs[0]->getTaskId());
        self::assertSame('https://one.example/hook', $configs[0]->getUrl());
        self::assertSame('tok', $configs[0]->getToken());
    }

    public function testSetAndGetInfoMultipleConfigs(): void
    {
        $store = $this->createStore();
        $alice = Fixtures::callContext('alice');
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook'), $alice);
        $store->setInfo('task-1', self::config('cfg-2', 'https://two.example/hook'), $alice);

        $ids = array_map(static fn(TaskPushNotificationConfig $c): string => $c->getId(), $store->getInfo('task-1', $alice));
        sort($ids);
        self::assertSame(['cfg-1', 'cfg-2'], $ids);
    }

    public function testSetInfoUpdatesAnExistingConfig(): void
    {
        $store = $this->createStore();
        $alice = Fixtures::callContext('alice');
        $store->setInfo('task-1', self::config('cfg-1', 'https://old.example/hook'), $alice);
        $store->setInfo('task-1', self::config('cfg-1', 'https://new.example/hook'), $alice);

        $configs = $store->getInfo('task-1', $alice);
        self::assertCount(1, $configs);
        self::assertSame('https://new.example/hook', $configs[0]->getUrl());
    }

    public function testSetInfoDefaultsTheConfigIdToTheTaskId(): void
    {
        $store = $this->createStore();
        $store->setInfo('task-1', self::config('', 'https://one.example/hook'), Fixtures::callContext('alice'));

        self::assertSame('task-1', $store->getInfo('task-1', Fixtures::callContext('alice'))[0]->getId());
    }

    public function testGetInfoForAnUnknownTaskIsEmpty(): void
    {
        self::assertSame([], $this->createStore()->getInfo('missing', Fixtures::callContext('alice')));
    }

    public function testKeepsAuthentication(): void
    {
        $store = $this->createStore();
        $config = self::config('cfg-1', 'https://one.example/hook');
        $config->setAuthentication(new AuthenticationInfo(['scheme' => 'Bearer', 'credentials' => 'abc']));
        $store->setInfo('task-1', $config, Fixtures::callContext('alice'));

        $auth = $store->getInfo('task-1', Fixtures::callContext('alice'))[0]->getAuthentication();
        self::assertNotNull($auth);
        self::assertSame('Bearer', $auth->getScheme());
        self::assertSame('abc', $auth->getCredentials());
    }

    public function testDeleteOneConfig(): void
    {
        $store = $this->createStore();
        $alice = Fixtures::callContext('alice');
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook'), $alice);
        $store->setInfo('task-1', self::config('cfg-2', 'https://two.example/hook'), $alice);

        $store->deleteInfo('task-1', $alice, 'cfg-1');

        $configs = $store->getInfo('task-1', $alice);
        self::assertCount(1, $configs);
        self::assertSame('cfg-2', $configs[0]->getId());
    }

    public function testDeleteAllConfigsOfATask(): void
    {
        $store = $this->createStore();
        $alice = Fixtures::callContext('alice');
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook'), $alice);
        $store->setInfo('task-1', self::config('cfg-2', 'https://two.example/hook'), $alice);

        $store->deleteInfo('task-1', $alice);

        self::assertSame([], $store->getInfo('task-1', $alice));
    }

    public function testDeletingSomethingMissingIsANoOp(): void
    {
        $store = $this->createStore();
        $store->deleteInfo('missing', Fixtures::callContext('alice'), 'nope');
        $store->deleteInfo('missing', Fixtures::callContext('alice'));

        self::assertSame([], $store->getInfo('missing', Fixtures::callContext('alice')));
    }

    public function testOwnersOnlySeeAndDeleteTheirOwnConfigs(): void
    {
        $store = $this->createStore();
        $alice = Fixtures::callContext('alice');
        $bob = Fixtures::callContext('bob');
        $store->setInfo('task-1', self::config('cfg-a', 'https://alice.example/hook'), $alice);
        $store->setInfo('task-1', self::config('cfg-b', 'https://bob.example/hook'), $bob);

        self::assertSame(['cfg-a'], array_map(static fn($c) => $c->getId(), $store->getInfo('task-1', $alice)));
        self::assertSame(['cfg-b'], array_map(static fn($c) => $c->getId(), $store->getInfo('task-1', $bob)));

        $store->deleteInfo('task-1', $bob);
        self::assertCount(1, $store->getInfo('task-1', $alice));
    }

    public function testGetInfoForDispatchReturnsEveryOwnersConfigs(): void
    {
        $store = $this->createStore();
        $store->setInfo('task-1', self::config('cfg-a', 'https://alice.example/hook'), Fixtures::callContext('alice'));
        $store->setInfo('task-1', self::config('cfg-b', 'https://bob.example/hook'), Fixtures::callContext('bob'));
        $store->setInfo('task-2', self::config('cfg-c', 'https://other.example/hook'), Fixtures::callContext('alice'));

        $ids = array_map(static fn(TaskPushNotificationConfig $c): string => $c->getId(), $store->getInfoForDispatch('task-1'));
        sort($ids);
        self::assertSame(['cfg-a', 'cfg-b'], $ids);
        self::assertSame([], $store->getInfoForDispatch('missing'));
    }

    protected static function config(string $id, string $url, string $token = ''): TaskPushNotificationConfig
    {
        return new TaskPushNotificationConfig(['id' => $id, 'url' => $url, 'token' => $token]);
    }
}
