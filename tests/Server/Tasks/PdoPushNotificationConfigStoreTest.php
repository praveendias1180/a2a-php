<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Server\Tasks\PdoPushNotificationConfigStore;
use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Tests\Server\Support\Fixtures;

/**
 * Ports tests/server/tasks/test_database_push_notification_config_store.py
 * (encryption: Python uses Fernet with a key; here any encrypt/decrypt pair).
 */
final class PdoPushNotificationConfigStoreTest extends PushNotificationConfigStoreContract
{
    protected function createStore(): PushNotificationConfigStore
    {
        return new PdoPushNotificationConfigStore(new \PDO('sqlite::memory:'));
    }

    public function testCreatesTheTableOnceAndIsIdempotent(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoPushNotificationConfigStore($pdo);
        $store->createTable();
        $store->createTable();

        $tables = self::query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('a2a_push_notification_configs', $tables);
    }

    public function testCustomTablePrefix(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoPushNotificationConfigStore($pdo, 'custom_');
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook'), Fixtures::callContext('alice'));

        self::assertSame(1, (int) self::query($pdo, 'SELECT COUNT(*) FROM custom_push_notification_configs')->fetchColumn());
    }

    public function testRejectsAnUnsafeTablePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoPushNotificationConfigStore(new \PDO('sqlite::memory:'), 'x; DROP TABLE y; --');
    }

    public function testDataIsNotEncryptedWithoutAKey(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoPushNotificationConfigStore($pdo);
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook', 'secret-token'), Fixtures::callContext('alice'));

        self::assertStringContainsString('secret-token', (string) self::query($pdo, 'SELECT config_json FROM a2a_push_notification_configs')->fetchColumn());
    }

    public function testDataIsEncryptedWithAKey(): void
    {
        $key = random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $encrypt = static function (string $plain) use ($key): string {
            $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        };
        $decrypt = static function (string $sealed) use ($key): string {
            $raw = (string) base64_decode($sealed, true);
            $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            if ($plain === false) {
                throw new \RuntimeException('Cannot decrypt');
            }

            return $plain;
        };
        $pdo = new \PDO('sqlite::memory:');
        $store = new PdoPushNotificationConfigStore($pdo, encrypt: $encrypt, decrypt: $decrypt);
        $store->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook', 'secret-token'), Fixtures::callContext('alice'));

        $raw = (string) self::query($pdo, 'SELECT config_json FROM a2a_push_notification_configs')->fetchColumn();
        self::assertStringNotContainsString('secret-token', $raw);
        self::assertStringNotContainsString('one.example', $raw);
        self::assertSame('secret-token', $store->getInfo('task-1', Fixtures::callContext('alice'))[0]->getToken());
        self::assertSame('secret-token', $store->getInfoForDispatch('task-1')[0]->getToken());
    }

    public function testRequiresBothEncryptionClosures(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PdoPushNotificationConfigStore(new \PDO('sqlite::memory:'), encrypt: static fn(string $s): string => $s);
    }

    public function testTwoConnectionsShareConfigs(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'a2a-push-');
        self::assertIsString($file);
        try {
            $writer = new PdoPushNotificationConfigStore(new \PDO('sqlite:' . $file));
            $reader = new PdoPushNotificationConfigStore(new \PDO('sqlite:' . $file));
            $writer->setInfo('task-1', self::config('cfg-1', 'https://one.example/hook'), Fixtures::callContext('alice'));

            self::assertCount(1, $reader->getInfoForDispatch('task-1'));
        } finally {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private static function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }
}
