<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Server\Tasks\PushNotificationConfigStore;

final class InMemoryPushNotificationConfigStoreTest extends PushNotificationConfigStoreContract
{
    protected function createStore(): PushNotificationConfigStore
    {
        return new InMemoryPushNotificationConfigStore();
    }
}
