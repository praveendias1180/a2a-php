# Your first agent

!!! info "Coming in phase 3"
    The server side lands in phase 3 of the [roadmap](../project/roadmap.md). This page shows the API it will have, which matches the Python SDK's hello-world sample. The code here isn't runnable yet.

An A2A agent has three parts:

1. **An `AgentExecutor`**: your logic. It reads the incoming message and publishes events: status changes and artifacts.
2. **An Agent Card**: the JSON that tells clients who you are, what you can do and where to reach you. It is served at `/.well-known/agent-card.json`.
3. **A request handler + routes**: the SDK part that speaks JSON-RPC and REST, stores tasks and streams events.

## 1. The executor

=== "Plain PHP"

    ```php
    use A2A\Server\AgentExecution\AgentExecutor;
    use A2A\Server\AgentExecution\RequestContext;
    use A2A\Server\Events\EventQueue;
    use A2A\Server\Tasks\TaskUpdater;
    use A2A\Types\Part;

    final class HelloExecutor implements AgentExecutor
    {
        public function execute(RequestContext $context, EventQueue $eventQueue): void
        {
            $updater = new TaskUpdater($eventQueue, $context->taskId(), $context->contextId());
            $updater->startWork($updater->newAgentMessage([new Part(['text' => 'Processing…'])]));

            $reply = 'Hello World! You said: ' . $context->getUserInput();

            $updater->addArtifact([new Part(['text' => $reply])], name: 'response', lastChunk: true);
            $updater->complete();
        }

        public function cancel(RequestContext $context, EventQueue $eventQueue): void
        {
            (new TaskUpdater($eventQueue, $context->taskId(), $context->contextId()))->cancel();
        }
    }
    ```

=== "Laravel"

    ```bash
    php artisan a2a:make-executor Hello
    ```

    It generates the same class as the Plain PHP tab, in `app/A2A/HelloExecutor.php`.

=== "Python"

    ```python
    from a2a.server.agent_execution import AgentExecutor, RequestContext
    from a2a.server.events import EventQueue
    from a2a.server.tasks import TaskUpdater
    from a2a.types import Part

    class HelloExecutor(AgentExecutor):
        async def execute(self, context: RequestContext, event_queue: EventQueue) -> None:
            updater = TaskUpdater(event_queue, context.task_id, context.context_id)
            await updater.start_work(updater.new_agent_message([Part(text='Processing…')]))

            reply = f'Hello World! You said: {context.get_user_input()}'

            await updater.add_artifact([Part(text=reply)], name='response', last_chunk=True)
            await updater.complete()

        async def cancel(self, context: RequestContext, event_queue: EventQueue) -> None:
            await TaskUpdater(event_queue, context.task_id, context.context_id).cancel()
    ```

## 2. The Agent Card

=== "Plain PHP"

    ```php
    use A2A\Types\{AgentCapabilities, AgentCard, AgentInterface, AgentSkill};

    $card = new AgentCard([
        'name' => 'Hello Agent',
        'description' => 'Says hello.',
        'version' => '1.0.0',
        'capabilities' => new AgentCapabilities(['streaming' => true]),
        'default_input_modes' => ['text/plain'],
        'default_output_modes' => ['text/plain'],
        'skills' => [new AgentSkill([
            'id' => 'hello', 'name' => 'Hello', 'description' => 'Say hi.', 'tags' => ['demo'],
        ])],
        'supported_interfaces' => [
            new AgentInterface(['protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0', 'url' => 'https://agent.example.com/a2a/jsonrpc']),
            new AgentInterface(['protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0', 'url' => 'https://agent.example.com/a2a/rest']),
        ],
    ]);
    ```

=== "Laravel"

    ```php
    // config/a2a.php holds the same fields; the bridge fills in the interface URLs from your routes.
    ```

=== "Python"

    ```python
    card = AgentCard(
        name='Hello Agent',
        description='Says hello.',
        version='1.0.0',
        capabilities=AgentCapabilities(streaming=True),
        default_input_modes=['text/plain'],
        default_output_modes=['text/plain'],
        skills=[AgentSkill(id='hello', name='Hello', description='Say hi.', tags=['demo'])],
        supported_interfaces=[
            AgentInterface(protocol_binding='JSONRPC', protocol_version='1.0', url='https://agent.example.com/a2a/jsonrpc'),
            AgentInterface(protocol_binding='HTTP+JSON', protocol_version='1.0', url='https://agent.example.com/a2a/rest'),
        ],
    )
    ```

## 3. Serve it

=== "Plain PHP"

    ```php
    use A2A\Server\RequestHandlers\DefaultRequestHandler;
    use A2A\Server\Routes\Routes;
    use A2A\Server\Tasks\InMemoryTaskStore;

    $handler = new DefaultRequestHandler(
        agentExecutor: new HelloExecutor(),
        taskStore: new InMemoryTaskStore(),
        agentCard: $card,
    );

    // PSR-15 handlers: mount them in any framework or router.
    $routes = [
        '/.well-known/agent-card.json' => Routes::agentCard($card),
        '/a2a/jsonrpc' => Routes::jsonRpc($handler),
        '/a2a/rest' => Routes::rest($handler),
    ];
    ```

=== "Laravel"

    ```php
    // routes/api.php
    Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class)
        ->middleware('auth:sanctum');
    ```

=== "Python"

    ```python
    handler = DefaultRequestHandler(
        agent_executor=HelloExecutor(),
        task_store=InMemoryTaskStore(),
        agent_card=card,
    )

    app = FastAPI()
    add_a2a_routes_to_fastapi(
        app,
        agent_card_routes=create_agent_card_routes(agent_card=card),
        jsonrpc_routes=create_jsonrpc_routes(request_handler=handler, rpc_url='/a2a/jsonrpc'),
        rest_routes=create_rest_routes(request_handler=handler, path_prefix='/a2a/rest'),
    )
    ```
