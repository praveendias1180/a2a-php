# Laravel: storage and pruning

## What is stored where

| Data | Table / keys | Notes |
|---|---|---|
| Tasks | `a2a_tasks` | owner-scoped, the whole task as ProtoJSON |
| Events (database driver) | `a2a_task_events`, `a2a_task_flags` | the event log, cancel flags, run leases |
| Events (redis driver) | `a2a:events:{task}` etc. | expire on their own |
| Push-notification configs | `a2a_push_notification_configs` | **encrypted** with your app key: they hold webhook tokens |

The task and event tables are created by the core SDK's own stores (`PdoTaskStore`, `PdoQueueManager`) on your connection, so they behave exactly like the plain-PHP SDK that the [TCK](../reference/conformance.md) checks. They are not Eloquent models. Two consequences:

- `a2a.storage.table_prefix` (default `a2a_`) names them; the connection's own `prefix` setting is not applied to them.
- Read tasks through the SDK (`A2A::taskStore()->get($id, $context)`), which applies owner scoping, rather than querying the table.

SQLite, PostgreSQL and MySQL are supported. With SQLite and several PHP processes, set `busy_timeout` and `journal_mode => 'wal'` on the connection.

## Pruning

Finished tasks are kept until you delete them:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('a2a:prune --days=7')->daily();
```

`a2a:prune` deletes tasks that finished (completed, failed, canceled or rejected) more than `--days` ago, their push configs, and database events older than that. Tasks that are still working, or waiting for input, are never deleted.
