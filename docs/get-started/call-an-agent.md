# Call an agent

The client reads the agent's card, picks a transport both sides support (JSON-RPC or HTTP+JSON), and sends messages. `sendMessage()` returns a **generator of events**: the PHP version of Python's `async for`.

It is tested in CI against the official Python SDK's sample agent, over both transports, with Guzzle, Symfony HttpClient and a plain PSR-18 client.

## Send a message

=== "Plain PHP"

    ```php
    --8<-- "examples/call-an-agent.php"
    ```

=== "Laravel"

    ```php
    --8<-- "examples/laravel/CallAgent.php:call"
    ```

    `A2A::client()` uses Guzzle, which Laravel ships, so streams arrive live. Bind an `A2A\Client\ClientConfig` in the container to set transports or the HTTP client for the whole app.

=== "Python"

    ```python
    from uuid import uuid4

    from a2a.client import create_client
    from a2a.helpers import get_artifact_text
    from a2a.types import Message, Part, Role, SendMessageRequest, TaskState

    client = await create_client('https://agent.example.com')

    request = SendMessageRequest(
        message=Message(message_id=uuid4().hex, role=Role.ROLE_USER, parts=[Part(text='hello')])
    )

    async for event in client.send_message(request):
        if event.HasField('task'):
            print('task', event.task.id)
        elif event.HasField('status_update'):
            print('status', TaskState.Name(event.status_update.status.state))
        elif event.HasField('artifact_update'):
            print('artifact', get_artifact_text(event.artifact_update.artifact))
    ```

Output against the Python sample agent:

```text
task 984d0e8c-022a-44dd-91fa-d71129e99ca0
status TASK_STATE_WORKING
artifact Hello World! Nice to meet you!
status TASK_STATE_COMPLETED
```

Nothing is sent until you start the `foreach`. If you stop early (`break`), the connection is closed.

## Streaming or not

If both the agent's card (`capabilities.streaming`) and your `ClientConfig` allow streaming, the client uses `SendStreamingMessage` and events arrive as the agent produces them. Otherwise it sends one `SendMessage`, waits for the task to finish or pause, and yields it as a single event. Your loop is the same either way.

To get the task back at once and let the agent keep working, ask for it:

=== "Plain PHP"

    ```php
    use A2A\Types\SendMessageConfiguration;

    $request->setConfiguration(new SendMessageConfiguration(['return_immediately' => true]));
    ```

=== "Python"

    ```python
    request.configuration.return_immediately = True
    ```

## The other operations

=== "Plain PHP"

    ```php
    use A2A\Types\{CancelTaskRequest, GetTaskRequest, ListTasksRequest, SubscribeToTaskRequest};

    $task = $client->getTask(new GetTaskRequest(['id' => $taskId, 'history_length' => 10]));
    $page = $client->listTasks(new ListTasksRequest(['context_id' => $contextId, 'page_size' => 20]));
    $task = $client->cancelTask(new CancelTaskRequest(['id' => $taskId]));

    // Re-attach to a running task's event stream.
    foreach ($client->subscribe(new SubscribeToTaskRequest(['id' => $taskId])) as $event) {
        // ...
    }
    ```

=== "Python"

    ```python
    task = await client.get_task(GetTaskRequest(id=task_id, history_length=10))
    page = await client.list_tasks(ListTasksRequest(context_id=context_id, page_size=20))
    task = await client.cancel_task(CancelTaskRequest(id=task_id))

    async for event in client.subscribe(SubscribeToTaskRequest(id=task_id)):
        ...
    ```

Push-notification configs (`create/get/list/deleteTaskPushNotificationConfig`) and `getExtendedAgentCard()` work the same way.

## Choosing the transport and HTTP client

```php
use A2A\Client\{ClientConfig, ClientFactory};

$client = ClientFactory::createClient('https://agent.example.com', new ClientConfig(
    supportedProtocolBindings: ['HTTP+JSON', 'JSONRPC'], // what this client can speak
    useClientPreference: false,                          // false: follow the agent's order
    httpClient: new \GuzzleHttp\Client(['timeout' => 30]),
));
```

| HTTP client | Streaming |
|---|---|
| Guzzle 7 | live |
| Symfony HttpClient (native, `HttpClient::create()`) | live |
| any other PSR-18 client | the events arrive when the stream ends, because PSR-18 hands back complete responses (Symfony's `Psr18Client` is an exception: its body is live) |

With no `httpClient`, the SDK uses Guzzle if it is installed, then Symfony HttpClient, then any PSR-18 client it can find.

!!! note "Guzzle streams over HTTP/1.0"
    Guzzle streams through PHP's `http://` wrapper, which holds an HTTP/1.1 *chunked* body back until the server closes it. So the SDK sends Guzzle's streaming requests as HTTP/1.0, which the server answers unchunked. Ordinary requests stay on HTTP/1.1.

## Timeouts, headers and authentication

Per call, through a `ClientCallContext`:

```php
use A2A\Client\ClientCallContext;

$context = new ClientCallContext(
    timeout: 10.0,                                    // seconds (Guzzle and Symfony; plain PSR-18: set it on the client)
    serviceParameters: ['A2A-Extensions' => 'https://example.com/ext/v1'], // sent as HTTP headers
);
$task = $client->getTask(new GetTaskRequest(['id' => $taskId]), $context);
```

For agents whose card declares security schemes, `AuthInterceptor` adds the credential for you: Bearer tokens for HTTP bearer, OAuth2 and OpenID Connect, or the API-key header.

```php
use A2A\Client\Auth\{AuthInterceptor, InMemoryContextCredentialStore};

$credentials = new InMemoryContextCredentialStore();
$credentials->setCredentials('session-1', 'bearer', $token);

$client = ClientFactory::createClient('https://agent.example.com', interceptors: [new AuthInterceptor($credentials)]);
$client->getTask($request, new ClientCallContext(state: ['sessionId' => 'session-1']));
```

## Errors

Errors from the agent come back as the same exception classes the server uses, so you can catch exactly what you expect:

| Exception | When |
|---|---|
| `A2A\Utils\Errors\TaskNotFoundError`, `TaskNotCancelableError`, `UnsupportedOperationError`, … | the agent returned that A2A error (the error's details are in `$e->data`) |
| `A2A\Client\Errors\A2AClientTimeoutError` | the request timed out |
| `A2A\Client\Errors\AgentCardResolutionError` | the card could not be fetched or read (`$e->statusCode` for HTTP errors) |
| `A2A\Client\Errors\A2AClientError` | any other transport problem: network, unexpected HTTP status, a response that isn't valid A2A |

All of them extend `A2A\Utils\Errors\A2AError`.
