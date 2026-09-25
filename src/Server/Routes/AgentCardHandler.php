<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Types\AgentCard;
use Http\Discovery\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the Agent Card (normally at /.well-known/agent-card.json) with the
 * caching headers the spec recommends (section 8.6): Cache-Control with
 * max-age, an ETag and Last-Modified, and 304 for a matching If-None-Match.
 *
 * Mirrors a2a-python: create_agent_card_routes() in
 * src/a2a/server/routes/agent_card_routes.py. Python also merges v0.3
 * compat fields into the card; that arrives with the compat layer
 * (phase 6).
 */
final class AgentCardHandler implements RequestHandlerInterface
{
    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly int $loadedAt;

    /**
     * @param (\Closure(AgentCard): AgentCard)|null $cardModifier
     */
    public function __construct(
        private readonly AgentCard $agentCard,
        private readonly ?\Closure $cardModifier = null,
        private readonly int $maxAgeSeconds = 300,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $factory = new Psr17Factory();
        $this->responseFactory = $responseFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        // Stable across requests (each PHP request is a new process): the
        // entry script's modification time.
        $modified = getlastmod();
        $this->loadedAt = $modified === false ? time() : $modified;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $card = $this->cardModifier !== null ? ($this->cardModifier)($this->agentCard) : $this->agentCard;
        $body = Common::encode(Common::toJsonValue($card));
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Cache-Control', sprintf('public, max-age=%d', $this->maxAgeSeconds))
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $this->loadedAt) . ' GMT');

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
            return $response->withStatus(304);
        }

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
