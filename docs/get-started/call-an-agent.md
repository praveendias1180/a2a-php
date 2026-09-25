# Call an agent

!!! info "Coming in phase 2"
    The client lands in phase 2 of the [roadmap](../project/roadmap.md), and it will be tested against the official Python sample server. This page shows the API it will have.

The client reads the agent's card, picks a transport both sides support (JSON-RPC or REST), and sends messages. `sendMessage()` returns a **generator of events**, the PHP version of Python's `async for`.

=== "Plain PHP"

    ```php
    use A2A\Client\ClientFactory;
    use A2A\Types\{Message, Part, Role, SendMessageRequest};

    $client = ClientFactory::create('https://agent.example.com');

    $request = new SendMessageRequest([
        'message' => new Message([
            'message_id' => bin2hex(random_bytes(16)),
            'role' => Role::ROLE_USER,
            'parts' => [new Part(['text' => 'hello'])],
        ]),
    ]);

    foreach ($client->sendMessage($request) as $event) {
        if ($event->hasStatusUpdate()) {
            echo 'status: ', $event->getStatusUpdate()->getStatus()->getState(), PHP_EOL;
        } elseif ($event->hasArtifactUpdate()) {
            foreach ($event->getArtifactUpdate()->getArtifact()->getParts() as $part) {
                echo $part->getText(), PHP_EOL;
            }
        }
    }
    ```

=== "Laravel"

    ```php
    use A2A\Laravel\Facades\A2A;

    foreach (A2A::client('https://agent.example.com')->sendMessage($request) as $event) {
        // same events as the Plain PHP tab
    }
    ```

=== "Python"

    ```python
    from a2a.client import create_client
    from a2a.types import Message, Part, Role, SendMessageRequest

    client = await create_client('https://agent.example.com')

    request = SendMessageRequest(
        message=Message(message_id=uuid4().hex, role=Role.ROLE_USER, parts=[Part(text='hello')])
    )

    async for event in client.send_message(request):
        if event.HasField('status_update'):
            print('status:', event.status_update.status.state)
        elif event.HasField('artifact_update'):
            for part in event.artifact_update.artifact.parts:
                print(part.text)
    ```

## Streaming or not

If the agent's card says `capabilities.streaming: true`, the client streams over SSE and events arrive as they happen. Otherwise it sends one request and yields the final task. Your loop is the same either way.
