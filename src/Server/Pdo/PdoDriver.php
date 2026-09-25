<?php

declare(strict_types=1);

namespace A2A\Server\Pdo;

/**
 * The SQL differences between the three databases the PDO stores support.
 *
 * @internal
 */
final class PdoDriver
{
    public const SQLITE = 'sqlite';
    public const PGSQL = 'pgsql';
    public const MYSQL = 'mysql';

    private function __construct() {}

    /**
     * Puts the connection into the mode the stores rely on and returns the
     * driver name. SQLite gets WAL + a busy timeout so concurrent PHP
     * processes (php -S workers, FPM children) wait instead of failing.
     */
    public static function prepare(\PDO $pdo): string
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver) || !in_array($driver, [self::SQLITE, self::PGSQL, self::MYSQL], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported PDO driver "%s". Use sqlite, pgsql or mysql.', is_string($driver) ? $driver : '?'));
        }
        if ($driver === self::SQLITE) {
            $pdo->exec('PRAGMA busy_timeout = 10000');
            // WAL needs a file; it is a no-op for :memory:.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        return $driver;
    }

    public static function autoIncrementPrimaryKey(string $driver, string $column): string
    {
        return match ($driver) {
            self::SQLITE => "{$column} INTEGER PRIMARY KEY AUTOINCREMENT",
            self::PGSQL => "{$column} BIGSERIAL PRIMARY KEY",
            default => "{$column} BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY",
        };
    }

    public static function longText(string $driver): string
    {
        return $driver === self::MYSQL ? 'LONGTEXT' : 'TEXT';
    }

    /**
     * An insert-or-update statement keyed on $keyColumns.
     *
     * @param list<string> $columns
     * @param list<string> $keyColumns
     */
    public static function upsert(string $driver, string $table, array $columns, array $keyColumns): string
    {
        $placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns));
        $columnList = implode(', ', $columns);
        $updates = array_values(array_diff($columns, $keyColumns));

        if ($driver === self::MYSQL) {
            $set = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", $updates));

            return "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$set}";
        }

        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = excluded.{$c}", $updates));

        return "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) ON CONFLICT (" . implode(', ', $keyColumns) . ") DO UPDATE SET {$set}";
    }

    /**
     * Checks a table prefix is a plain identifier, since it is interpolated
     * into SQL.
     */
    public static function assertIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid SQL identifier.', $identifier));
        }

        return $identifier;
    }
}
