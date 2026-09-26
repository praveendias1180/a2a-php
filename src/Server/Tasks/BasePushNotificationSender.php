<?php

declare(strict_types=1);

namespace A2A\Server\Tasks;

use A2A\Client\Errors\A2AClientError;
use A2A\Client\Http\HttpRequest;
use A2A\Client\Http\HttpSender;
use A2A\Client\Http\HttpSenderFactory;
use A2A\Client\Http\PinsAddresses;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskPushNotificationConfig;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;
use A2A\Utils\PushUrlValidator;
use A2A\Utils\Uuid;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POSTs each task update to every webhook registered for the task.
 *
 * The body is the update wrapped in a StreamResponse, as ProtoJSON (spec
 * §4.3.3). Headers:
 * - `Authorization: <scheme> <credentials>` when the config has
 *   `authentication` (spec §4.3.2; the A2A TCK checks it);
 * - `X-A2A-Notification-Token: <token>` when the config has a token (as in
 *   Python);
 * - `X-A2A-Notification-Id`: one id per notification, the same on every
 *   retry, so a receiver can drop duplicates.
 *
 * Delivery is at least once: network errors, 408, 425, 429 and 5xx are
 * retried with exponential backoff (Retry-After is honoured, capped), up to
 * $maxAttempts; other responses end the delivery. Failures are logged, never
 * thrown.
 *
 * SSRF: every attempt first resolves the URL through PushUrlValidator and
 * gives up if any address is not public. When the HttpSender can pin
 * addresses (Guzzle with ext-curl, Symfony HttpClient) the request then
 * connects to exactly the address that passed, so DNS rebinding between the
 * check and the connection is not possible. Redirects are never followed.
 *
 * How it differs from Python's BasePushNotificationSender:
 * - screening is on by default (Python's push_url_validator defaults to
 *   None); pass `pushUrlValidator: null` to turn it off, or a
 *   PushUrlValidator with allowedHosts for a local webhook;
 * - Python sends only the token header and does not retry;
 * - deliveries run one after another in the calling process. With the
 *   inline task runner that means a slow webhook delays the stream it is
 *   attached to (at most $timeoutSeconds per attempt); the Laravel bridge
 *   sends from a queue job instead.
 *
 * Mirrors a2a-python: BasePushNotificationSender in
 * src/a2a/server/tasks/base_push_notification_sender.py
 */
final class BasePushNotificationSender implements PushNotificationSender
{
    public const TOKEN_HEADER = 'X-A2A-Notification-Token';
    public const NOTIFICATION_ID_HEADER = 'X-A2A-Notification-Id';

    private const MAX_RETRY_AFTER_SECONDS = 30.0;

    private readonly HttpSender $http;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param object|null                          $httpClient       an HttpSender, Guzzle client, Symfony HttpClient or
     *                                                               PSR-18 client; null finds one (see HttpSenderFactory)
     * @param PushUrlValidator|(\Closure(string): bool)|null $pushUrlValidator a PushUrlValidator (resolves and pins), a
     *                                                               closure (checks only) or null (no screening)
     * @param (\Closure(float): void)|null          $sleep            waits between attempts (tests pass a no-op)
     */
    public function __construct(
        private readonly PushNotificationConfigStore $configStore,
        ?object $httpClient = null,
        private readonly PushUrlValidator|\Closure|null $pushUrlValidator = new PushUrlValidator(),
        private readonly int $maxAttempts = 3,
        private readonly float $initialBackoffSeconds = 0.5,
        private readonly float $timeoutSeconds = 5.0,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?\Closure $sleep = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('$maxAttempts must be at least 1.');
        }
        $this->http = HttpSenderFactory::create($httpClient);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function sendNotification(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        $configs = $this->configStore->getInfoForDispatch($taskId);
        if ($configs === []) {
            return;
        }

        $notificationId = Uuid::v4();
        $failed = 0;
        foreach ($configs as $config) {
            if (!$this->deliver($config, $event, $notificationId, $taskId)) {
                ++$failed;
            }
        }
        if ($failed > 0) {
            $this->logger->warning('Some push notifications failed to send for task_id={task_id}', ['task_id' => $taskId]);
        }
    }

    /**
     * Delivers one update to one webhook, with retries. True when the
     * webhook answered 2xx.
     */
    public function deliver(
        TaskPushNotificationConfig $config,
        Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event,
        ?string $notificationId = null,
        ?string $taskId = null,
    ): bool {
        $url = $config->getUrl();
        $taskId ??= $config->getTaskId();
        $body = ProtoUtils::toStreamResponse($event)->serializeToJsonString();
        $headers = $this->headersFor($config, $notificationId ?? Uuid::v4());

        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            $pinned = null;
            if ($this->pushUrlValidator instanceof PushUrlValidator) {
                $addresses = $this->pushUrlValidator->resolve($url);
                if ($addresses === null) {
                    $this->logger->warning('Push-notification URL rejected for task_id={task_id}: {url}', ['task_id' => $taskId, 'url' => $url]);

                    return false;
                }
                $pinned = $this->http instanceof PinsAddresses && $this->http->pinsAddresses() ? $addresses[0] : null;
            } elseif ($this->pushUrlValidator !== null && !($this->pushUrlValidator)($url)) {
                $this->logger->warning('Push-notification URL rejected for task_id={task_id}: {url}', ['task_id' => $taskId, 'url' => $url]);

                return false;
            }

            $retryAfter = null;
            try {
                $response = $this->http->send(new HttpRequest(
                    'POST',
                    $url,
                    $headers,
                    $body,
                    $this->timeoutSeconds,
                    $pinned,
                    followRedirects: false,
                ));
                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    $this->logger->info('Push-notification sent for task_id={task_id} to URL: {url}', ['task_id' => $taskId, 'url' => $url]);

                    return true;
                }
                if (!self::isRetryable($response->statusCode)) {
                    $this->logger->error('Push-notification for task_id={task_id} to URL {url} failed with HTTP {status}.', [
                        'task_id' => $taskId, 'url' => $url, 'status' => $response->statusCode,
                    ]);

                    return false;
                }
                $retryAfter = self::retryAfterSeconds($response->header('retry-after'));
                $reason = 'HTTP ' . $response->statusCode;
            } catch (A2AClientError $e) {
                $reason = $e->getMessage();
            }

            if ($attempt < $this->maxAttempts) {
                $delay = $retryAfter ?? $this->initialBackoffSeconds * (2 ** ($attempt - 1));
                $this->logger->notice('Push-notification for task_id={task_id} to URL {url} failed ({reason}); retry {next} in {delay}s.', [
                    'task_id' => $taskId, 'url' => $url, 'reason' => $reason, 'next' => $attempt + 1, 'delay' => $delay,
                ]);
                ($this->sleep)($delay);
            } else {
                $this->logger->error('Error sending push-notification for task_id={task_id} to URL: {url} ({reason}); giving up after {attempts} attempts.', [
                    'task_id' => $taskId, 'url' => $url, 'reason' => $reason, 'attempts' => $attempt,
                ]);
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(TaskPushNotificationConfig $config, string $notificationId): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            self::NOTIFICATION_ID_HEADER => $notificationId,
        ];
        if ($config->getToken() !== '') {
            $headers[self::TOKEN_HEADER] = $config->getToken();
        }
        $auth = $config->getAuthentication();
        if ($auth !== null && $auth->getScheme() !== '') {
            $headers['Authorization'] = trim($auth->getScheme() . ' ' . $auth->getCredentials());
        }

        return $headers;
    }

    private static function isRetryable(int $status): bool
    {
        return $status === 408 || $status === 425 || $status === 429 || $status >= 500;
    }

    private static function retryAfterSeconds(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return min(max(0.0, (float) $value), self::MAX_RETRY_AFTER_SECONDS);
        }
        $time = strtotime($value);

        return $time === false ? null : min(max(0.0, (float) ($time - time())), self::MAX_RETRY_AFTER_SECONDS);
    }
}
