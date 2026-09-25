<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Server\OwnerResolver;
use A2A\Server\Pdo\PdoDriver;
use A2A\Server\ServerCallContext;
use A2A\Types\ListTasksRequest;
use A2A\Types\ListTasksResponse;
use A2A\Types\Task;
use A2A\Utils\Constants;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\TaskUtils;
use A2A\Utils\VersionValidator;

/**
 * Stores tasks in SQLite, PostgreSQL or MySQL through PDO. Shared by every
 * PHP process on the same database, which is what PHP-FPM and `php -S` need.
 *
 * One table, `{prefix}tasks` (created on first use unless $createTable is
 * false): id, context_id, owner, status_state, status_timestamp (µs),
 * protocol_version, task_json. The whole task is kept as ProtoJSON; the
 * other columns exist for filtering and sorting.
 *
 * Mirrors a2a-python: DatabaseTaskStore in
 * src/a2a/server/tasks/database_task_store.py (SQLAlchemy there, plain PDO
 * here; same owner scoping, ordering and page tokens).
 */
final class PdoTaskStore implements TaskStore
{
    private readonly string $driver;

    private readonly string $table;

    private bool $tableReady;

    /** @var \Closure(ServerCallContext): string */
    private readonly \Closure $ownerResolver;

    /**
     * @param (\Closure(ServerCallContext): string)|null $ownerResolver
     */
    public function __construct(
        private readonly \PDO $pdo,
        string $tablePrefix = 'a2a_',
        bool $createTable = true,
        ?\Closure $ownerResolver = null,
    ) {
        $this->driver = PdoDriver::prepare($pdo);
        $this->table = PdoDriver::assertIdentifier($tablePrefix . 'tasks');
        $this->tableReady = !$createTable;
        $this->ownerResolver = $ownerResolver ?? OwnerResolver::default();
    }

    public function createTable(): void
    {
        $text = PdoDriver::longText($this->driver);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            id VARCHAR(255) NOT NULL PRIMARY KEY,
            context_id VARCHAR(255) NOT NULL,
            owner VARCHAR(255) NULL,
            status_state INTEGER NOT NULL,
            status_timestamp BIGINT NULL,
            protocol_version VARCHAR(16) NULL,
            task_json {$text} NOT NULL
        )");
        $index = $this->table . '_owner_ts';
        if ($this->driver === PdoDriver::MYSQL) {
            $exists = $this->pdo->query("SHOW INDEX FROM {$this->table} WHERE Key_name = '{$index}'");
            if ($exists !== false && $exists->fetch() === false) {
                $this->pdo->exec("CREATE INDEX {$index} ON {$this->table} (owner, status_timestamp)");
            }
        } else {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$this->table} (owner, status_timestamp)");
        }
        $this->tableReady = true;
    }

    public function save(Task $task, ServerCallContext $context): void
    {
        $this->ensureTable();
        $headers = $context->headers();
        $statement = $this->pdo->prepare(PdoDriver::upsert(
            $this->driver,
            $this->table,
            ['id', 'context_id', 'owner', 'status_state', 'status_timestamp', 'protocol_version', 'task_json'],
            ['id'],
        ));
        $statement->execute([
            'id' => $task->getId(),
            'context_id' => $task->getContextId(),
            'owner' => ($this->ownerResolver)($context),
            'status_state' => $task->getStatus()?->getState() ?? 0,
            'status_timestamp' => TaskListing::statusMicros($task),
            'protocol_version' => $headers === null ? null : VersionValidator::actualVersion($headers),
            'task_json' => $task->serializeToJsonString(),
        ]);
    }

    public function get(string $taskId, ServerCallContext $context): ?Task
    {
        $this->ensureTable();
        $statement = $this->pdo->prepare("SELECT task_json FROM {$this->table} WHERE id = :id AND owner = :owner");
        $statement->execute(['id' => $taskId, 'owner' => ($this->ownerResolver)($context)]);
        $json = $statement->fetchColumn();

        return is_string($json) ? self::decode($json) : null;
    }

    public function list(ListTasksRequest $params, ServerCallContext $context): ListTasksResponse
    {
        $this->ensureTable();
        $where = ['owner = :owner'];
        $bind = ['owner' => ($this->ownerResolver)($context)];

        if ($params->getContextId() !== '') {
            $where[] = 'context_id = :context_id';
            $bind['context_id'] = $params->getContextId();
        }
        if ($params->getStatus() !== 0) {
            $where[] = 'status_state = :status_state';
            $bind['status_state'] = $params->getStatus();
        }
        $after = $params->getStatusTimestampAfter();
        if ($after !== null) {
            $where[] = 'status_timestamp >= :ts_after';
            $bind['ts_after'] = TaskListing::micros($after);
        }

        $filter = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$filter}");
        $count->execute($bind);
        $total = (int) $count->fetchColumn();

        if ($params->getPageToken() !== '') {
            $startId = TaskUtils::decodePageToken($params->getPageToken());
            $start = $this->pdo->prepare("SELECT status_timestamp FROM {$this->table} WHERE id = :id AND owner = :owner");
            $start->execute(['id' => $startId, 'owner' => $bind['owner']]);
            $row = $start->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new InvalidParamsError(sprintf('Invalid page token: %s', $params->getPageToken()));
            }
            // Keyset pagination over (timestamp present, timestamp desc, id desc).
            $bind['start_id'] = $startId;
            $startTimestamp = $row['status_timestamp'] ?? null;
            if (!is_numeric($startTimestamp)) {
                $filter .= ' AND status_timestamp IS NULL AND id <= :start_id';
            } else {
                $bind['start_ts'] = (int) $startTimestamp;
                $bind['start_ts2'] = (int) $startTimestamp;
                $filter .= ' AND ((status_timestamp IS NOT NULL AND (status_timestamp < :start_ts OR (status_timestamp = :start_ts2 AND id <= :start_id))) OR status_timestamp IS NULL)';
            }
        }

        $pageSize = $params->hasPageSize() && $params->getPageSize() > 0 ? $params->getPageSize() : Constants::DEFAULT_LIST_TASKS_PAGE_SIZE;
        $limit = $pageSize + 1;
        $statement = $this->pdo->prepare(
            "SELECT id, task_json FROM {$this->table} WHERE {$filter} "
            . 'ORDER BY CASE WHEN status_timestamp IS NULL THEN 1 ELSE 0 END ASC, status_timestamp DESC, id DESC '
            . "LIMIT {$limit}",
        );
        $statement->execute($bind);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['task_json'] ?? null)) {
                $rows[] = ['id' => $row['id'], 'task_json' => $row['task_json']];
            }
        }

        $next = '';
        if (count($rows) > $pageSize) {
            $next = TaskUtils::encodePageToken($rows[$pageSize]['id']);
            $rows = array_slice($rows, 0, $pageSize);
        }

        return new ListTasksResponse([
            'tasks' => array_map(static fn(array $r): Task => self::decode($r['task_json']), $rows),
            'next_page_token' => $next,
            'page_size' => $pageSize,
            'total_size' => $total,
        ]);
    }

    public function delete(string $taskId, ServerCallContext $context): void
    {
        $this->ensureTable();
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id AND owner = :owner")
            ->execute(['id' => $taskId, 'owner' => ($this->ownerResolver)($context)]);
    }

    private function ensureTable(): void
    {
        if (!$this->tableReady) {
            $this->createTable();
        }
    }

    private static function decode(string $json): Task
    {
        $task = new Task();
        $task->mergeFromJsonString($json, true);

        return $task;
    }
}
