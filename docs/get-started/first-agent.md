# Your first agent

An A2A agent has three parts:

1. **An `AgentExecutor`**: your logic. It reads the incoming message and publishes events: a task, status changes and artifacts.
2. **An Agent Card**: the JSON that tells clients who you are, what you can do and where to reach you. It is served at `/.well-known/agent-card.json`.
3. **A request handler and routes**: the SDK part that speaks JSON-RPC and HTTP+JSON, stores tasks and streams events.

The code on this page is the SDK's `examples/hello-world`, a port of the Python SDK's `hello_world_agent.py`. CI runs it, and the official Python client talks to it over both transports.

## 1. The executor

=== "Plain PHP"

    ```php
    --8<-- "examples/hello-world/HelloExecutor.php"
    ```

=== "Laravel"

    ```php
    // The same class works in Laravel. `php artisan a2a:make-executor`
    // arrives with the Laravel bridge (phase 4).
    ```

=== "Python"

    ```python
    class SampleAgentExecutor(AgentExecutor):
        async def execute(self, context: RequestContext, event_queue: EventQueue) -> None:
            await event_queue.enqueue_event(Task(
                id=context.task_id, context_id=context.context_id,
                status=TaskStatus(state=TaskState.TASK_STATE_SUBMITTED),
                history=[context.message],
            ))
            updater = TaskUpdater(event_queue=event_queue, task_id=context.task_id, context_id=context.context_id)
            await updater.start_work(message=updater.new_agent_message(parts=[Part(text='Processing your question...')]))

            reply = self._parse_input(context.get_user_input())
            await asyncio.sleep(1)

            await updater.add_artifact(parts=[Part(text=reply)], name='response', last_chunk=True)
            await updater.complete()

        async def cancel(self, context: RequestContext, event_queue: EventQueue) -> None:
            await TaskUpdater(event_queue=event_queue, task_id=context.task_id, context_id=context.context_id).cancel()
    ```

Each `enqueueEvent()` and each `TaskUpdater` call is saved and streamed to clients at once, while `execute()` keeps running. [Running agents in PHP](../concepts/running-agents-in-php.md) explains how.

## 2. The Agent Card

=== "Plain PHP"

    ```php
    --8<-- "examples/hello-world/server.php:card"
    ```

=== "Laravel"

    ```php
    // config/a2a.php will hold these fields; the bridge fills in the
    // interface URLs from your routes (phase 4).
    ```

=== "Python"

    ```python
    agent_card = AgentCard(
        name='Sample Agent',
        description='A sample agent to test the stream functionality.',
        provider=AgentProvider(organization='A2A Samples', url='https://example.com'),
        version='1.0.0',
        capabilities=AgentCapabilities(streaming=True, push_notifications=False),
        default_input_modes=['text'],
        default_output_modes=['text', 'task-status'],
        skills=[AgentSkill(id='sample_agent', name='Sample Agent', description='Say hi.',
                           tags=['sample'], examples=['hi'],
                           input_modes=['text'], output_modes=['text', 'task-status'])],
        supported_interfaces=[
            AgentInterface(protocol_binding='JSONRPC', protocol_version='1.0', url=f'http://{host}:{port}/a2a/jsonrpc'),
            AgentInterface(protocol_binding='HTTP+JSON', protocol_version='1.0', url=f'http://{host}:{port}/a2a/rest'),
        ],
    )
    ```

## 3. Serve it

=== "Plain PHP"

    ```php
    --8<-- "examples/hello-world/server.php:serve"
    ```

    Run it with PHP's built-in server:

    ```bash
    PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:41241 examples/hello-world/server.php
    ```

=== "Laravel"

    ```php
    // routes/api.php, with the Laravel bridge (phase 4):
    Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class);
    ```

=== "Python"

    ```python
    request_handler = DefaultRequestHandler(
        agent_executor=SampleAgentExecutor(),
        task_store=InMemoryTaskStore(),
        agent_card=agent_card,
    )
    app = FastAPI()
    add_a2a_routes_to_fastapi(
        app,
        agent_card_routes=create_agent_card_routes(agent_card=agent_card),
        jsonrpc_routes=create_jsonrpc_routes(request_handler=request_handler, rpc_url='/a2a/jsonrpc'),
        rest_routes=create_rest_routes(request_handler=request_handler, path_prefix='/a2a/rest'),
    )
    ```

!!! warning "Use a shared store under PHP-FPM or `php -S`"
    Python keeps tasks in memory because one long-running process serves every request. In PHP each request is a separate process, so `InMemoryTaskStore` starts empty every time. Use `PdoTaskStore` and `PdoQueueManager` on the same database (SQLite is fine for one machine). The in-memory versions are for tests and long-running servers such as RoadRunner or Swoole.

## 4. Try it

```bash
php examples/call-an-agent.php http://127.0.0.1:41241 hello
```

```text
task 5d0c…
status TASK_STATE_WORKING
artifact Hello World! Nice to meet you!
status TASK_STATE_COMPLETED
```

The same server answers the official Python SDK client, the A2A test kit, and any other A2A client. See [Conformance](../reference/conformance.md).

## The pieces you can swap

| Piece | Default | Other options |
|---|---|---|
| `TaskStore` | none (you choose) | `PdoTaskStore` (SQLite, PostgreSQL, MySQL), `InMemoryTaskStore`, your own |
| `QueueManager` | `InMemoryQueueManager` | `PdoQueueManager`; the Laravel bridge adds Redis |
| `TaskRunner` | `InlineTaskRunner` (runs in the request) | the Laravel bridge adds a queued runner |
| `ServerCallContextBuilder` | reads the `a2a.user` request attribute | your own, to plug in authentication |
| `PushNotificationConfigStore` | none (push is off) | `InMemoryPushNotificationConfigStore`; sending arrives in phase 5 |
