# Push notifications

Push notifications let a client leave instead of holding a stream open: it registers a **webhook**, and the agent POSTs every task update to it. The spec calls this out for long-running tasks and mobile or serverless clients (§3.5, §4.3).

## Turn them on

Three pieces: declare the capability in the card, give the handler a **config store** (where clients' webhooks are kept) and a **sender** (what POSTs to them).

=== "Plain PHP"

    ```php
    use A2A\Server\RequestHandlers\DefaultRequestHandler;
    use A2A\Server\Tasks\BasePushNotificationSender;
    use A2A\Server\Tasks\PdoPushNotificationConfigStore;
    use A2A\Types\AgentCapabilities;
    use A2A\Utils\PushUrlValidator;

    $card->setCapabilities(new AgentCapabilities(['streaming' => true, 'push_notifications' => true]));

    $pdo = new PDO('sqlite:/var/lib/my-agent/a2a.sqlite');
    $pushStore = new PdoPushNotificationConfigStore($pdo);
    $urls = new PushUrlValidator();          // SSRF guard, see below

    $handler = new DefaultRequestHandler(
        agentExecutor: new MyExecutor(),
        taskStore: new PdoTaskStore($pdo),
        agentCard: $card,
        queueManager: new PdoQueueManager($pdo),
        pushConfigStore: $pushStore,
        pushUrlValidator: $urls,              // checked when a client registers a webhook
        pushSender: new BasePushNotificationSender($pushStore, pushUrlValidator: $urls),
    );
    ```

=== "Laravel"

    Declare `push_notifications` in your card; the bridge does the rest. Settings live in `config/a2a.php`:

    ```php
    'push' => [
        'enabled' => true,
        'queue' => true,            // send from a queue job (recommended)
        'connection' => null,       // queue connection / name for the push jobs
        'queue_name' => null,
        'max_attempts' => 3,
        'backoff' => 0.5,
        'timeout' => 5.0,
        'allowed_hosts' => [],      // e.g. ['localhost'] for a local receiver in development
    ],
    ```

=== "Python"

    ```python
    push_store = InMemoryPushNotificationConfigStore()
    handler = DefaultRequestHandler(
        agent_executor=MyExecutor(),
        task_store=task_store,
        agent_card=card,
        push_config_store=push_store,
        push_sender=BasePushNotificationSender(httpx.AsyncClient(), push_store,
                                               push_url_validator=validate_push_notification_url),
    )
    ```

Clients register a webhook either with the message (`configuration.taskPushNotificationConfig`) or later with `CreateTaskPushNotificationConfig`. Get, List and Delete work the same way. Each client only sees its own configs; the sender delivers to every config registered for the task.

## What the webhook receives

A `POST` with the update wrapped in a `StreamResponse`, exactly like one event of a stream:

```http
POST /hooks/a2a HTTP/1.1
Content-Type: application/json
Authorization: Bearer <credentials from the config>
X-A2A-Notification-Token: <token from the config>
X-A2A-Notification-Id: 7d9c…   (same on every retry)

{"statusUpdate":{"taskId":"…","contextId":"…","status":{"state":"TASK_STATE_COMPLETED"}}}
```

- `Authorization` is sent when the config has `authentication` (`scheme` + `credentials`); the token header when it has a `token`. Check at least one of them in your receiver.
- Every task update is sent, in order: the new Task, each status change, each artifact.
- **At least once.** Network errors, `408`, `425`, `429` and `5xx` are retried with exponential backoff (`Retry-After` wins, capped at 30 s), up to `maxAttempts`. Use `X-A2A-Notification-Id` to drop duplicates.
- A redirect is never followed; it counts as a failure.

## Security: webhook URLs are checked

A webhook URL comes from a client, so the agent must not let it reach internal services (SSRF). `PushUrlValidator`:

- accepts only `http`/`https`;
- resolves the host and rejects it if **any** address is loopback, private, link-local (e.g. `169.254.169.254`), carrier-grade NAT, multicast, reserved or documentation space — for IPv6 anything outside global unicast, and the IPv4 inside mapped, NAT64 and 6to4 addresses;
- fails closed when the host doesn't resolve.

It runs when a config is created **and again before every delivery attempt**. With Guzzle (ext-curl) or Symfony HttpClient, the request then connects to exactly the address that was checked, so a DNS answer that changes between the check and the connection (DNS rebinding) can't redirect it. Other PSR-18 clients are checked but not pinned.

For a receiver on your own machine during development, allow its host explicitly: `new PushUrlValidator(allowedHosts: ['localhost'])` (Laravel: `a2a.push.allowed_hosts`). Never allow-list a host someone else controls.

## Where sending happens

| | Plain PHP (`BasePushNotificationSender`) | Laravel (queued, default) |
|---|---|---|
| When | right after each update is saved, in the process running the agent | a `SendPushNotification` job per update |
| Slow webhook | delays that task's stream by up to `timeoutSeconds` per attempt | never delays the agent |
| Credentials in the queue | — | no: the job carries the task id and the update, and reads the (encrypted) webhook configs when it runs |
| Order | strict | usually in order; with several workers two updates for one task can arrive out of order (compare the status timestamps) |

## How it differs from Python

- Screening is **on by default** (`pushUrlValidator` defaults to a `PushUrlValidator`); Python's is off unless you pass one. Pass `null` to turn it off.
- Python sends only the token header and doesn't retry.
- `PdoPushNotificationConfigStore` can encrypt configs at rest with any `encrypt`/`decrypt` pair you pass (the Laravel store always encrypts with the app key).
