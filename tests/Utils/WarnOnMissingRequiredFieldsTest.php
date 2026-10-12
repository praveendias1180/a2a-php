<?php

declare(strict_types=1);

namespace A2A\Tests\Utils;

use A2A\Server\Events\InMemoryQueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Routes\Routes;
use A2A\Server\Tasks\InMemoryTaskStore;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Types\AgentSkill;
use A2A\Utils\ProtoUtils;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Python a2a-sdk 1.2.2 (4f4101c): warn_on_missing_required_fields() logs, never raises.
 */
final class WarnOnMissingRequiredFieldsTest extends TestCase
{
    public function testCompleteCardIsSilent(): void
    {
        $logger = self::logger();

        self::assertTrue(ProtoUtils::warnOnMissingRequiredFields(self::completeCard(), 'test:', $logger));
        self::assertSame([], $logger->records);
    }

    public function testIncompleteCardLogsOneWarningNamingTheFields(): void
    {
        $logger = self::logger();
        $card = new AgentCard(['name' => 'Partial']);

        self::assertFalse(ProtoUtils::warnOnMissingRequiredFields($card, 'agent_card passed to X:', $logger));
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        $message = $logger->records[0]['message'];
        self::assertStringStartsWith('agent_card passed to X: AgentCard is not spec-compliant', $message);
        self::assertStringContainsString('description', $message);
        self::assertStringContainsString('version', $message);
        self::assertStringNotContainsString('name,', $message);
        self::assertStringContainsString(ProtoUtils::SPEC_FIELD_PRESENCE_URL, $message);
    }

    public function testRequestHandlerAndCardRouteWarnButStillWork(): void
    {
        $logger = self::logger();
        $card = new AgentCard(['name' => 'Partial']);

        new DefaultRequestHandler(
            agentExecutor: new \A2A\Tests\Server\Support\CallbackExecutor(),
            taskStore: new InMemoryTaskStore(),
            agentCard: $card,
            queueManager: new InMemoryQueueManager(),
            extendedAgentCard: new AgentCard(['name' => 'Partial extended']),
            logger: $logger,
        );
        Routes::agentCard($card, logger: $logger);

        self::assertCount(3, $logger->records);
        self::assertStringStartsWith('agent_card passed to DefaultRequestHandler:', $logger->records[0]['message']);
        self::assertStringStartsWith('extended_agent_card passed to DefaultRequestHandler:', $logger->records[1]['message']);
        self::assertStringStartsWith('agent_card passed to AgentCardHandler:', $logger->records[2]['message']);
    }

    private static function completeCard(): AgentCard
    {
        return new AgentCard([
            'name' => 'Agent',
            'description' => 'Does things.',
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(['streaming' => true]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'skills' => [new AgentSkill(['id' => 's', 'name' => 'S', 'description' => 'Skill.', 'tags' => ['t']])],
            'supported_interfaces' => [new AgentInterface(['protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0', 'url' => 'https://agent.example.com/'])],
        ]);
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: mixed, message: string}>}
     */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
    }
}
