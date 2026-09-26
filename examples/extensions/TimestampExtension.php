<?php

declare(strict_types=1);

use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\Events\EventQueue;
use A2A\Types\AgentExtension;
use A2A\Types\Artifact;
use A2A\Types\Message;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;

/**
 * A small example A2A extension: when a client activates it, every
 * artifact the agent produces carries the time it was generated.
 *
 * It shows the three extension pieces the spec defines (§4.6):
 * - declaration: an AgentExtension in the Agent Card's capabilities;
 * - activation: the client sends the URI in the `A2A-Extensions` header,
 *   the SDK activates it (it is declared) and echoes it back;
 * - extension point: artifacts list the URI in `extensions` and carry the
 *   extension's data in `metadata`, keyed by the URI.
 *
 * Wrap any executor: `new DefaultRequestHandler(TimestampExtension::wrap($executor), ...)`
 * and add TimestampExtension::declaration() to the card's extensions.
 */
final class TimestampExtension
{
    public const URI = 'https://praveendias1180.github.io/a2a-php/extensions/timestamp/v1';

    public static function declaration(bool $required = false): AgentExtension
    {
        return new AgentExtension([
            'uri' => self::URI,
            'description' => 'Stamps each artifact with the time it was generated (metadata key: the extension URI).',
            'required' => $required,
        ]);
    }

    public static function wrap(AgentExecutor $inner): AgentExecutor
    {
        return new class ($inner) implements AgentExecutor {
            public function __construct(private readonly AgentExecutor $inner) {}

            public function execute(RequestContext $context, EventQueue $eventQueue): void
            {
                $queue = $context->isExtensionActive(TimestampExtension::URI) ? TimestampExtension::stamping($eventQueue) : $eventQueue;
                $this->inner->execute($context, $queue);
            }

            public function cancel(RequestContext $context, EventQueue $eventQueue): void
            {
                $this->inner->cancel($context, $eventQueue);
            }
        };
    }

    /**
     * An EventQueue that stamps artifacts on their way through.
     */
    public static function stamping(EventQueue $inner): EventQueue
    {
        return new class ($inner) implements EventQueue {
            public function __construct(private readonly EventQueue $inner) {}

            public function enqueueEvent(Message|Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
            {
                if ($event instanceof TaskArtifactUpdateEvent && $event->getArtifact() !== null) {
                    TimestampExtension::stamp($event->getArtifact());
                } elseif ($event instanceof Task) {
                    foreach ($event->getArtifacts() as $artifact) {
                        TimestampExtension::stamp($artifact);
                    }
                }
                $this->inner->enqueueEvent($event);
            }
        };
    }

    public static function stamp(Artifact $artifact, ?\DateTimeInterface $now = null): void
    {
        $extensions = iterator_to_array($artifact->getExtensions(), false);
        if (!in_array(self::URI, $extensions, true)) {
            $extensions[] = self::URI;
            $artifact->setExtensions($extensions);
        }
        $metadata = $artifact->getMetadata() !== null ? ProtoUtils::fromStruct($artifact->getMetadata()) : [];
        $metadata[self::URI] = ['generatedAt' => ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::RFC3339)];
        $artifact->setMetadata(ProtoUtils::toStruct($metadata));
    }
}
