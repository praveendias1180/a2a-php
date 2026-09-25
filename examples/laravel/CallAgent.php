<?php

declare(strict_types=1);

namespace App\A2A;

use A2A\Laravel\Facades\A2A;
use A2A\Types\Message;
use A2A\Types\Part;
use A2A\Types\Role;
use A2A\Types\SendMessageRequest;

final class CallAgent
{
    /**
     * Sends $text to the agent at $url and returns the text of its answer.
     */
    // --8<-- [start:call]
    public function ask(string $url, string $text): string
    {
        $client = A2A::client($url); // fetches $url/.well-known/agent-card.json

        $request = new SendMessageRequest(['message' => new Message([
            'message_id' => (string) \Illuminate\Support\Str::uuid(),
            'role' => Role::ROLE_USER,
            'parts' => [new Part(['text' => $text])],
        ])]);

        $answer = '';
        foreach ($client->sendMessage($request) as $event) {
            if ($event->hasArtifactUpdate()) {
                foreach ($event->getArtifactUpdate()->getArtifact()?->getParts() ?? [] as $part) {
                    $answer .= $part->getText();
                }
            } elseif ($event->hasTask()) {
                foreach ($event->getTask()->getArtifacts() as $artifact) {
                    foreach ($artifact->getParts() as $part) {
                        $answer .= $part->getText();
                    }
                }
            }
        }

        return $answer;
    }
    // --8<-- [end:call]
}
