<?php

declare(strict_types=1);

namespace App\A2A;

use A2A\Laravel\Contracts\AgentCardProvider;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentProvider;
use A2A\Types\AgentSkill;

/**
 * The TCK system under test's card (same as tck/sut-agent.php). The
 * interfaces are left out: the bridge fills them in from the routes.
 */
final class TckAgentCard implements AgentCardProvider
{
    public function agentCard(): AgentCard
    {
        return new AgentCard([
            'name' => 'A2A PHP SDK Laravel System Under Test (SUT)',
            'description' => 'System Under Test for A2A TCK conformance, built on praveendias1180/a2a-laravel (queued runner)',
            'version' => '1.0.0',
            'provider' => new AgentProvider(['organization' => 'a2a-php', 'url' => 'https://github.com/praveendias1180/a2a-php']),
            'capabilities' => new AgentCapabilities(['streaming' => true, 'push_notifications' => false]),
            'default_input_modes' => ['text'],
            'default_output_modes' => ['text'],
            'skills' => [new AgentSkill([
                'id' => 'tck',
                'name' => 'TCK Conformance',
                'description' => 'Handles TCK conformance test messages',
                'tags' => ['tck'],
            ])],
        ]);
    }
}
