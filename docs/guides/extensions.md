# Extensions

Extensions add features on top of the core protocol (spec §4.6). An agent **declares** them in its card; a client **activates** the ones it wants per request with the `A2A-Extensions` header; data travels in `metadata` on messages and artifacts, keyed by the extension URI.

## Declare

```php
use A2A\Types\AgentExtension;

$card->getCapabilities()->setExtensions([
    new AgentExtension([
        'uri' => 'https://example.com/ext/citations/v1',
        'description' => 'Adds sources to answers',
    ]),
    new AgentExtension([
        'uri' => 'https://example.com/ext/billing/v1',
        'description' => 'Clients must send a billing reference',
        'required' => true,
    ]),
]);
```

## What the SDK does for you

For every `SendMessage` / `SendStreamingMessage`:

1. **Required extensions are enforced.** If the card marks an extension `required` and the client didn't list it in `A2A-Extensions`, the request fails with `ExtensionSupportRequiredError` (JSON-RPC `-32008`, HTTP `400`, reason `EXTENSION_SUPPORT_REQUIRED`) before your executor runs. The spec makes this a MUST.
2. **Requested extensions the card declares are activated.** Unknown ones are ignored, as the spec says.
3. **Activated extensions are echoed** in the response's `A2A-Extensions` header (the spec's SHOULD).

The Python SDK leaves all three to the application.

## Use them in the executor

```php
public function execute(RequestContext $context, EventQueue $eventQueue): void
{
    if ($context->isExtensionActive('https://example.com/ext/citations/v1')) {
        // add sources…
    }
    $context->requestedExtensions();   // everything the client asked for
    $context->activateExtension('https://example.com/ext/other/v1');  // activate one yourself
}
```

On a unary response, an extension the executor activates is echoed too. A streaming response has already sent its headers when `execute()` runs, so there only the SDK's activations are echoed.

## A complete example

`examples/extensions/TimestampExtension.php` is a small, working extension: when a client activates `https://praveendias1180.github.io/a2a-php/extensions/timestamp/v1`, every artifact lists the URI in `extensions` and carries `{"generatedAt": "…"}` in its metadata.

```php
require 'examples/extensions/TimestampExtension.php';

$card->getCapabilities()->setExtensions([TimestampExtension::declaration()]);
$handler = new DefaultRequestHandler(TimestampExtension::wrap(new MyExecutor()), $taskStore, $card, $queueManager);
```

A client opts in by sending the header:

```php
use A2A\Client\{ClientCallContext, ClientFactory, ServiceParameters, ServiceParametersFactory};

$client = ClientFactory::createClient('https://agent.example.com');
$context = new ClientCallContext(serviceParameters: ServiceParametersFactory::create([
    ServiceParameters::withA2aExtensions([TimestampExtension::URI]),
]));
foreach ($client->sendMessage($request, $context) as $event) { /* artifacts carry generatedAt */ }
```

Python does the same with `ServiceParametersFactory.create([with_a2a_extensions([...])])`.
