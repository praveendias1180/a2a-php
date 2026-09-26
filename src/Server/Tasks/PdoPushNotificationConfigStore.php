<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\OwnerResolver;
use A2A\Server\Pdo\PdoDriver;
use A2A\Server\ServerCallContext;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Stores push-notification configs in SQLite, PostgreSQL or MySQL through
 * PDO, so every PHP process on the same database sees them (what PHP-FPM
 * and `php -S` need; see PdoTaskStore).
 *
 * One table, `{prefix}push_notification_configs` (created on first use
 * unless $createTable is false): task_id, config_id, owner, config_json,
 * with (task_id, owner, config_id) unique. An empty config id defaults to
 * the task id and a repeat id replaces the old config, as in the in-memory
 * store.
 *
 * The config holds the webhook's token and credentials. Pass $encrypt and
 * $decrypt closures (e.g. sodium_crypto_secretbox, or Laravel's Crypt as the
 * Laravel bridge does) to keep them encrypted at rest; by default they are
 * stored as plain ProtoJSON, like Python's DatabasePushNotificationConfigStore
 * without an encryption key.
 *
 * Mirrors a2a-python: DatabasePushNotificationConfigStore in
 * src/a2a/server/tasks/database_push_notification_config_store.py
 */
final class PdoPushNotificationConfigStore implements PushNotificationConfigStore
{
    private readonly string $driver;

    private readonly string $table;

    private bool $tableReady;

    /** @var \Closure(ServerCallContext): string */
    private readonly \Closure $ownerResolver;

    /**
     * @param (\Closure(ServerCallContext): string)|null $ownerResolver
     * @param (\Closure(string): string)|null            $encrypt       turns the config JSON into the stored value
     * @param (\Closure(string): string)|null            $decrypt       the reverse of $encrypt
     */
    public function __construct(
        private readonly \PDO $pdo,
        string $tablePrefix = 'a2a_',
        bool $createTable = true,
        ?\Closure $ownerResolver = null,
        private readonly ?\Closure $encrypt = null,
        private readonly ?\Closure $decrypt = null,
    ) {
        if (($encrypt === null) !== ($decrypt === null)) {
            throw new \InvalidArgumentException('Pass both $encrypt and $decrypt, or neither.');
        }
        $this->driver = PdoDriver::prepare($pdo);
        $this->table = PdoDriver::assertIdentifier($tablePrefix . 'push_notification_configs');
        $this->tableReady = !$createTable;
        $this->ownerResolver = $ownerResolver ?? OwnerResolver::default();
    }

    public function createTable(): void
    {
        $text = PdoDriver::longText($this->driver);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            task_id VARCHAR(255) NOT NULL,
            owner VARCHAR(255) NOT NULL,
            config_id VARCHAR(255) NOT NULL,
            config_json {$text} NOT NULL,
            PRIMARY KEY (task_id, owner, config_id)
        )");
        $this->tableReady = true;
    }

    public function setInfo(string $taskId, TaskPushNotificationConfig $config, ServerCallContext $context): void
    {
        $this->ensureTable();
        $copy = new TaskPushNotificationConfig();
        $copy->mergeFrom($config);
        if ($copy->getId() === '') {
            // Python defaults the id to the task id.
            $copy->setId($taskId);
        }
        $copy->setTaskId($taskId);

        $statement = $this->pdo->prepare(PdoDriver::upsert(
            $this->driver,
            $this->table,
            ['task_id', 'owner', 'config_id', 'config_json'],
            ['task_id', 'owner', 'config_id'],
        ));
        $statement->execute([
            'task_id' => $taskId,
            'owner' => ($this->ownerResolver)($context),
            'config_id' => $copy->getId(),
            'config_json' => $this->seal($copy->serializeToJsonString()),
        ]);
    }

    public function getInfo(string $taskId, ServerCallContext $context): array
    {
        $this->ensureTable();
        $statement = $this->pdo->prepare("SELECT config_json FROM {$this->table} WHERE task_id = :task_id AND owner = :owner ORDER BY config_id");
        $statement->execute(['task_id' => $taskId, 'owner' => ($this->ownerResolver)($context)]);

        return $this->decodeAll($statement);
    }

    public function getInfoForDispatch(string $taskId): array
    {
        $this->ensureTable();
        $statement = $this->pdo->prepare("SELECT config_json FROM {$this->table} WHERE task_id = :task_id ORDER BY owner, config_id");
        $statement->execute(['task_id' => $taskId]);

        return $this->decodeAll($statement);
    }

    public function deleteInfo(string $taskId, ServerCallContext $context, ?string $configId = null): void
    {
        $this->ensureTable();
        $sql = "DELETE FROM {$this->table} WHERE task_id = :task_id AND owner = :owner";
        $bind = ['task_id' => $taskId, 'owner' => ($this->ownerResolver)($context)];
        if ($configId !== null) {
            $sql .= ' AND config_id = :config_id';
            $bind['config_id'] = $configId;
        }
        $this->pdo->prepare($sql)->execute($bind);
    }

    private function ensureTable(): void
    {
        if (!$this->tableReady) {
            $this->createTable();
        }
    }

    /**
     * @return list<TaskPushNotificationConfig>
     */
    private function decodeAll(\PDOStatement $statement): array
    {
        $configs = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $stored) {
            if (!is_string($stored)) {
                continue;
            }
            $config = new TaskPushNotificationConfig();
            $config->mergeFromJsonString($this->open($stored));
            $configs[] = $config;
        }

        return $configs;
    }

    private function seal(string $json): string
    {
        return $this->encrypt === null ? $json : ($this->encrypt)($json);
    }

    private function open(string $stored): string
    {
        return $this->decrypt === null ? $stored : ($this->decrypt)($stored);
    }
}
