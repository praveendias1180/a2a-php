<?php

declare(strict_types=1);

namespace App\A2A;

use A2A\Laravel\Contracts\AgentCardProvider;
use A2A\Types\AgentCapabilities;
use A2A\Types\AgentCard;
use A2A\Types\AgentSkill;

// --8<-- [start:card]
final class HelloAgentCard implements AgentCardProvider
{
    public function agentCard(): AgentCard
    {
        // No supported_interfaces: Route::a2a() fills them in from your routes.
        return new AgentCard([
            'name' => 'Hello Agent',
            'description' => 'Says hello.',
            'version' => '1.0.0',
            'capabilities' => new AgentCapabilities(['streaming' => true]),
            'default_input_modes' => ['text/plain'],
            'default_output_modes' => ['text/plain'],
            'skills' => [new AgentSkill([
                'id' => 'hello',
                'name' => 'Hello',
                'description' => 'Say hi.',
                'tags' => ['demo'],
            ])],
        ]);
    }
}
// --8<-- [end:card]
