<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Routes;

use A2A\Extensions\Common as Extensions;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\JsonRpcDispatcher;
use A2A\Server\Routes\RestDispatcher;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Server\Tasks\TaskUpdater;
use A2A\Tests\Server\Support\CallbackExecutor;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\AgentCard;
use A2A\Types\AgentExtension;
use A2A\Types\Part;
use A2A\Utils\Errors\ExtensionSupportRequiredError;

/**
 * Extension negotiation (spec §3.3.4, §4.6 and the extensions guide):
 * required extensions are enforced, requested ones the card declares are
 * activated and echoed in the `A2A-Extensions` response header.
 */
final class ExtensionNegotiationTest extends DispatcherTestCase
{
    private const OPTIONAL = 'https://example.com/ext/citations/v1';
    private const REQUIRED = 'urn:a2a:tck:required-extension';
    private const UNKNOWN = 'https://example.com/ext/unknown/v1';

    /** @var list<list<string>> activated extensions seen by execute() */
    private array $seen = [];

    public function testHelpers(): void
    {
        $card = self::card(required: true);

        self::assertSame([self::OPTIONAL], Extensions::activatableExtensions($card, [self::UNKNOWN, self::OPTIONAL]));
        self::assertSame([self::REQUIRED], Extensions::missingRequiredExtensions($card, [self::OPTIONAL]));
        self::assertSame([], Extensions::missingRequiredExtensions($card, [self::REQUIRED]));
    }

    public function testRequestedDeclaredExtensionsAreActivatedAndEchoedOverJsonRpc(): void
    {
        $response = (new JsonRpcDispatcher($this->handler(self::card())))->handle(self::request(
            'POST',
            '/',
            self::sendBody(),
            ['A2A-Version' => '1.0', 'Content-Type' => 'application/json', 'A2A-Extensions' => self::UNKNOWN . ', ' . self::OPTIONAL],
        ));

        self::assertSame(self::OPTIONAL, $response->getHeaderLine('A2A-Extensions'));
        self::assertSame('TASK_STATE_COMPLETED', self::at(self::json($response), 'result.task.status.state'));
        self::assertSame([[self::OPTIONAL]], $this->seen);
    }

    public function testEchoedOverRest(): void
    {
        $response = (new RestDispatcher($this->handler(self::card())))->handle(self::request(
            'POST',
            '/message:send',
            self::restBody(),
            ['A2A-Version' => '1.0', 'Content-Type' => 'application/json', 'A2A-Extensions' => self::OPTIONAL],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::OPTIONAL, $response->getHeaderLine('A2A-Extensions'));
    }

    public function testEchoedOnAStream(): void
    {
        $response = (new RestDispatcher($this->handler(self::card())))->handle(self::request(
            'POST',
            '/message:stream',
            self::restBody(),
            ['A2A-Version' => '1.0', 'Content-Type' => 'application/json', 'A2A-Extensions' => self::OPTIONAL],
        ));

        self::assertSame(self::OPTIONAL, $response->getHeaderLine('A2A-Extensions'));
        self::assertNotEmpty(self::sseEvents($response));
    }

    public function testNoHeaderWhenNothingIsActivated(): void
    {
        $response = (new JsonRpcDispatcher($this->handler(self::card())))->handle(self::request('POST', '/', self::sendBody()));

        self::assertFalse($response->hasHeader('A2A-Extensions'));
        self::assertSame([[]], $this->seen);
    }

    public function testAMissingRequiredExtensionFailsOverJsonRpc(): void
    {
        $response = (new JsonRpcDispatcher($this->handler(self::card(required: true))))->handle(self::request('POST', '/', self::sendBody()));

        $body = self::json($response);
        self::assertSame(-32008, self::at($body, 'error.code'));
        self::assertSame('EXTENSION_SUPPORT_REQUIRED', self::at($body, 'error.data.0.reason'));
        self::assertSame([], $this->seen, 'the executor must not run');
    }

    public function testAMissingRequiredExtensionFailsOverRest(): void
    {
        $response = (new RestDispatcher($this->handler(self::card(required: true))))->handle(self::request('POST', '/message:send', self::restBody()));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('EXTENSION_SUPPORT_REQUIRED', self::at(self::json($response), 'error.details.0.reason'));
    }

    public function testRequestingTheRequiredExtensionSucceeds(): void
    {
        $response = (new JsonRpcDispatcher($this->handler(self::card(required: true))))->handle(self::request(
            'POST',
            '/',
            self::sendBody(),
            ['A2A-Version' => '1.0', 'Content-Type' => 'application/json', 'A2A-Extensions' => self::REQUIRED],
        ));

        self::assertSame('TASK_STATE_COMPLETED', self::at(self::json($response), 'result.task.status.state'));
        self::assertSame(self::REQUIRED, $response->getHeaderLine('A2A-Extensions'));
    }

    public function testTheHandlerThrowsTheA2AError(): void
    {
        $context = Fixtures::callContext();

        $this->expectException(ExtensionSupportRequiredError::class);
        $this->handler(self::card(required: true))->onMessageSend(Fixtures::sendRequest(Fixtures::userMessage()), $context);
    }

    public function testAnExecutorCanActivateAnExtensionItself(): void
    {
        $handler = new DefaultRequestHandler(
            new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u, RequestContext $context): void {
                $context->activateExtension('https://example.com/ext/self/v1');
                $u->complete();
            })),
            new InMemoryTaskStore(),
            self::card(),
            new InMemoryQueueManager(),
        );

        $response = (new JsonRpcDispatcher($handler))->handle(self::request('POST', '/', self::sendBody()));

        self::assertSame('https://example.com/ext/self/v1', $response->getHeaderLine('A2A-Extensions'));
    }

    public function testTheTimestampExampleExtension(): void
    {
        require_once dirname(__DIR__, 3) . '/examples/extensions/TimestampExtension.php';
        $card = self::card();
        $card->getCapabilities()?->setExtensions([\TimestampExtension::declaration()]);
        $executor = \TimestampExtension::wrap(new CallbackExecutor(Fixtures::taskScript(static function (TaskUpdater $u): void {
            $u->addArtifact([new Part(['text' => 'answer'])], name: 'result');
            $u->complete();
        })));
        $handler = new DefaultRequestHandler($executor, new InMemoryTaskStore(), $card, new InMemoryQueueManager());
        $dispatcher = new JsonRpcDispatcher($handler);

        $active = $dispatcher->handle(self::request('POST', '/', self::sendBody(), ['A2A-Version' => '1.0', 'Content-Type' => 'application/json', 'A2A-Extensions' => \TimestampExtension::URI]));
        $inactive = $dispatcher->handle(self::request('POST', '/', self::sendBody('m-2')));

        $artifact = self::arrayAt(self::json($active), 'result.task.artifacts.0');
        self::assertSame([\TimestampExtension::URI], $artifact['extensions'] ?? null);
        $generatedAt = self::metadata($artifact)['generatedAt'] ?? null;
        self::assertIsString($generatedAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $generatedAt);
        self::assertSame(\TimestampExtension::URI, $active->getHeaderLine('A2A-Extensions'));

        $plain = self::arrayAt(self::json($inactive), 'result.task.artifacts.0');
        self::assertArrayNotHasKey('extensions', $plain);
        self::assertArrayNotHasKey('metadata', $plain);
    }

    private function handler(AgentCard $card): DefaultRequestHandler
    {
        $seen = &$this->seen;

        return new DefaultRequestHandler(
            new CallbackExecutor(static function (RequestContext $context, EventQueue $queue) use (&$seen): void {
                $seen[] = $context->activatedExtensions();
                Fixtures::taskScript(static function (TaskUpdater $u): void {
                    $u->complete();
                })($context, $queue);
            }),
            new InMemoryTaskStore(),
            $card,
            new InMemoryQueueManager(),
        );
    }

    private static function card(bool $required = false): AgentCard
    {
        $card = Fixtures::agentCard();
        $extensions = [new AgentExtension(['uri' => self::OPTIONAL, 'description' => 'Citations'])];
        if ($required) {
            $extensions[] = new AgentExtension(['uri' => self::REQUIRED, 'required' => true]);
        }
        $card->getCapabilities()?->setExtensions($extensions);

        return $card;
    }

    private static function sendBody(string $messageId = 'm-1'): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage',
            'params' => ['message' => ['messageId' => $messageId, 'role' => 'ROLE_USER', 'parts' => [['text' => 'hi']]]],
        ]);
    }

    private static function restBody(): string
    {
        return (string) json_encode(['message' => ['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'hi']]]]);
    }

    /**
     * @param array<mixed> $artifact
     *
     * @return array<mixed>
     */
    private static function metadata(array $artifact): array
    {
        $metadata = $artifact['metadata'] ?? null;
        self::assertIsArray($metadata);
        $value = $metadata[\TimestampExtension::URI] ?? null;
        self::assertIsArray($value);

        return $value;
    }
}
