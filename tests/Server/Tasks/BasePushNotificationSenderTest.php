<?php

declare(strict_types=1);

namespace A2A\Tests\Server\Tasks;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpResponse;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\PinsAddresses;
use A2A\Client\Http\StreamedResponse;
use A2A\Server\Tasks\BasePushNotificationSender;
use A2A\Server\Tasks\InMemoryPushNotificationConfigStore;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Tests\Server\Support\Fixtures;
use A2A\Types\Artifact;
use A2A\Types\AuthenticationInfo;
use A2A\Types\Part;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskState;
use A2A\Types\TaskStatus;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\PushUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Ports tests/server/tasks/test_push_notification_sender.py, plus the parts
 * that are PHP additions: the Authorization header, retries, notification
 * ids, address pinning and redirects.
 */
final class BasePushNotificationSenderTest extends TestCase
{
    private InMemoryPushNotificationConfigStore $store;

    private FakeHttpSender $http;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryPushNotificationConfigStore();
        $this->http = new FakeHttpSender();
        $this->sleeps = [];
    }

    public function testSendNotificationPostsTheTaskAsAStreamResponse(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('https://hooks.example/a2a', $request->url);
        $json = $this->http->lastJson();
        self::assertSame('task-1', self::at($json, 'task', 'id'));
        self::assertSame('TASK_STATE_WORKING', self::at($json, 'task', 'status', 'state'));
        self::assertSame('application/json', $request->headers['Content-Type'] ?? null);
        self::assertFalse($request->followRedirects);
    }

    public function testSendsStatusUpdateEvents(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', new TaskStatusUpdateEvent([
            'task_id' => 'task-1', 'context_id' => 'ctx-1', 'status' => new TaskStatus(['state' => TaskState::TASK_STATE_COMPLETED]),
        ]));

        self::assertSame('TASK_STATE_COMPLETED', self::at($this->http->lastJson(), 'statusUpdate', 'status', 'state'));
    }

    public function testSendsArtifactUpdateEvents(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', new TaskArtifactUpdateEvent([
            'task_id' => 'task-1', 'context_id' => 'ctx-1',
            'artifact' => new Artifact(['artifact_id' => 'a-1', 'parts' => [new Part(['text' => 'hi'])]]),
        ]));

        self::assertSame('a-1', self::at($this->http->lastJson(), 'artifactUpdate', 'artifact', 'artifactId'));
    }

    public function testSendsTheTokenHeader(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a', token: 'secret-token');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame('secret-token', $this->http->lastRequest()->headers[BasePushNotificationSender::TOKEN_HEADER] ?? null);
        self::assertArrayNotHasKey('Authorization', $this->http->lastRequest()->headers);
    }

    public function testSendsTheAuthenticationAsAnAuthorizationHeader(): void
    {
        $config = new TaskPushNotificationConfig(['id' => 'c', 'url' => 'https://hooks.example/a2a']);
        $config->setAuthentication(new AuthenticationInfo(['scheme' => 'Bearer', 'credentials' => 'tck-token']));
        $this->store->setInfo('task-1', $config, Fixtures::callContext());
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame('Bearer tck-token', $this->http->lastRequest()->headers['Authorization'] ?? null);
    }

    public function testNoConfigSendsNothing(): void
    {
        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame([], $this->http->requests);
    }

    public function testSendsToEveryConfigWithOneNotificationId(): void
    {
        $this->store->setInfo('task-1', new TaskPushNotificationConfig(['id' => 'a', 'url' => 'https://one.example/a2a']), Fixtures::callContext('alice'));
        $this->store->setInfo('task-1', new TaskPushNotificationConfig(['id' => 'b', 'url' => 'https://two.example/a2a']), Fixtures::callContext('bob'));
        $this->http->queueBody('')->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        $urls = array_map(static fn(HttpRequest $r): string => $r->url, $this->http->requests);
        sort($urls);
        self::assertSame(['https://one.example/a2a', 'https://two.example/a2a'], $urls);
        $ids = array_unique(array_map(static fn(HttpRequest $r): string => $r->headers[BasePushNotificationSender::NOTIFICATION_ID_HEADER] ?? '', $this->http->requests));
        self::assertCount(1, $ids);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) reset($ids));
    }

    public function testANonRetryableErrorIsLoggedAndNotRetried(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('nope', 404);
        $logger = $this->logger();

        $this->sender(logger: $logger)->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertCount(1, $this->http->requests);
        self::assertSame([], $this->sleeps);
        self::assertContains('error', array_column($logger->records, 'level'));
    }

    public function testRetriesServerErrorsWithBackoffAndKeepsTheNotificationId(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('', 503)->queueBody('', 500)->queueBody('');

        $ok = $this->sender(maxAttempts: 3)->deliver(
            $this->store->getInfoForDispatch('task-1')[0],
            Fixtures::task('task-1', TaskState::TASK_STATE_WORKING),
            'fixed-id',
        );

        self::assertTrue($ok);
        self::assertCount(3, $this->http->requests);
        self::assertSame([0.5, 1.0], $this->sleeps);
        foreach ($this->http->requests as $request) {
            self::assertSame('fixed-id', $request->headers[BasePushNotificationSender::NOTIFICATION_ID_HEADER] ?? null);
        }
    }

    public function testRetriesNetworkErrors(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueException(new A2AClientError('connection refused'))->queueBody('');

        $ok = $this->sender()->deliver($this->store->getInfoForDispatch('task-1')[0], Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertTrue($ok);
        self::assertCount(2, $this->http->requests);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('', 500)->queueBody('', 500);
        $logger = $this->logger();

        $ok = $this->sender(maxAttempts: 2, logger: $logger)->deliver($this->store->getInfoForDispatch('task-1')[0], Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertFalse($ok);
        self::assertCount(2, $this->http->requests);
        self::assertSame([0.5], $this->sleeps);
        self::assertContains('error', array_column($logger->records, 'level'));
    }

    public function testHonoursRetryAfter(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('', 429, ['Retry-After' => '2'])->queueBody('');

        $this->sender()->deliver($this->store->getInfoForDispatch('task-1')[0], Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame([2.0], $this->sleeps);
    }

    public function testARedirectIsAFailureNotFollowed(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('', 302, ['Location' => 'http://169.254.169.254/']);

        $ok = $this->sender()->deliver($this->store->getInfoForDispatch('task-1')[0], Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertFalse($ok);
        self::assertCount(1, $this->http->requests);
    }

    public function testASenderFailureNeverThrows(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueException(new A2AClientError('down'))->queueException(new A2AClientError('down'))->queueException(new A2AClientError('down'));

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertCount(3, $this->http->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrls(): iterable
    {
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data'];
        yield 'loopback' => ['http://127.0.0.1:8080/hook'];
        yield 'private range' => ['http://10.0.0.5/hook'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
        yield 'invalid port' => ['http://hooks.example:99999/hook'];
        yield 'unresolvable host' => ['https://unresolvable.invalid/hook'];
        yield 'host resolving to a private address' => ['https://internal.example/hook'];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedUrlsAreNeverRequested(string $url): void
    {
        $this->register('task-1', $url);

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame([], $this->http->requests);
    }

    public function testPublicHostIsAllowed(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertCount(1, $this->http->requests);
    }

    public function testNullValidatorSkipsScreening(): void
    {
        $this->register('task-1', 'http://127.0.0.1:8080/hook');
        $this->http->queueBody('');

        $sender = new BasePushNotificationSender($this->store, $this->http, pushUrlValidator: null, sleep: $this->sleeper());
        $sender->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertCount(1, $this->http->requests);
    }

    public function testAClosureValidatorOnlyChecks(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $sender = new BasePushNotificationSender($this->store, $this->http, pushUrlValidator: static fn(string $url): bool => false, sleep: $this->sleeper());

        $sender->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame([], $this->http->requests);
    }

    public function testAllowedHostsExemptALocalWebhook(): void
    {
        $this->register('task-1', 'http://localhost:9000/hook');
        $this->http->queueBody('');
        $validator = new PushUrlValidator(static fn(string $host): array => ['127.0.0.1'], allowedHosts: ['localhost']);

        $sender = new BasePushNotificationSender($this->store, $this->http, pushUrlValidator: $validator, sleep: $this->sleeper());
        $sender->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertCount(1, $this->http->requests);
    }

    public function testPinsTheCheckedAddressWhenTheSenderSupportsIt(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $pinning = new class ($this->http) implements HttpSender, PinsAddresses {
            public function __construct(private readonly FakeHttpSender $inner) {}

            public function send(HttpRequest $request): HttpResponse
            {
                return $this->inner->send($request);
            }

            public function stream(HttpRequest $request): StreamedResponse
            {
                return $this->inner->stream($request);
            }

            public function supportsIncrementalStreaming(): bool
            {
                return true;
            }

            public function pinsAddresses(): bool
            {
                return true;
            }
        };
        $this->http->queueBody('');

        $sender = new BasePushNotificationSender($this->store, $pinning, pushUrlValidator: $this->validator(), sleep: $this->sleeper());
        $sender->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertSame('93.184.215.14', $this->http->lastRequest()->pinnedAddress);
    }

    public function testDoesNotPinWhenTheSenderCannot(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $this->http->queueBody('');

        $this->sender()->sendNotification('task-1', Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        self::assertNull($this->http->lastRequest()->pinnedAddress);
    }

    public function testDnsIsCheckedAgainBeforeEachAttempt(): void
    {
        $this->register('task-1', 'https://hooks.example/a2a');
        $answers = [['93.184.215.14'], ['10.0.0.1']];
        $validator = new PushUrlValidator(static function (string $host) use (&$answers): array {
            return array_shift($answers) ?? [];
        });
        $this->http->queueBody('', 503);

        $sender = new BasePushNotificationSender($this->store, $this->http, pushUrlValidator: $validator, maxAttempts: 3, sleep: $this->sleeper());
        $ok = $sender->deliver($this->store->getInfoForDispatch('task-1')[0], Fixtures::task('task-1', TaskState::TASK_STATE_WORKING));

        // The second attempt re-resolves, now gets a private address, and stops.
        self::assertFalse($ok);
        self::assertCount(1, $this->http->requests);
    }

    public function testRejectsZeroAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BasePushNotificationSender($this->store, $this->http, maxAttempts: 0);
    }

    private function register(string $taskId, string $url, string $token = ''): void
    {
        $this->store->setInfo($taskId, new TaskPushNotificationConfig(['id' => 'cfg', 'url' => $url, 'token' => $token]), Fixtures::callContext());
    }

    private function sender(int $maxAttempts = 3, ?RecordingLogger $logger = null): BasePushNotificationSender
    {
        return new BasePushNotificationSender(
            $this->store,
            $this->http,
            pushUrlValidator: $this->validator(),
            maxAttempts: $maxAttempts,
            logger: $logger ?? new \Psr\Log\NullLogger(),
            sleep: $this->sleeper(),
        );
    }

    /**
     * A validator with fixed DNS answers, so tests never touch the network.
     */
    private function validator(): PushUrlValidator
    {
        return new PushUrlValidator(static fn(string $host): array => match ($host) {
            'hooks.example', 'one.example', 'two.example' => ['93.184.215.14'],
            'internal.example' => ['10.1.2.3'],
            default => [],
        });
    }

    /**
     * @return \Closure(float): void
     */
    private function sleeper(): \Closure
    {
        return function (float $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    private static function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
