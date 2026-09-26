<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Server\RequestHandlers\ResponseHelpers;
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
 * With a $signer (Signing::createAgentCardSigner()), the card is served
 * signed. The signer gets a copy, applied after $cardModifier, so
 * signatures never accumulate on the configured card; without a modifier
 * the signed card is computed once per process. ECDSA/RSA-PSS signatures
 * differ on each signing, so under PHP-FPM each worker's ETag differs; to
 * serve identical bytes everywhere, sign once at deploy time and pass the
 * already-signed card instead.
 *
 * Mirrors a2a-python: create_agent_card_routes() in
 * src/a2a/server/routes/agent_card_routes.py. As in Python, a card that
 * offers a v0.3 interface also carries the v0.3 fields (url,
 * preferredTransport, ...) so v0.3 clients can read it
 * (ResponseHelpers::agentCardToDict()).
 */
final class AgentCardHandler implements RequestHandlerInterface
{
    private readonly ResponseFactoryInterface $responseFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly int $loadedAt;

    /**
     * @param (\Closure(AgentCard): AgentCard)|null $cardModifier
     */
    private ?AgentCard $signedCard = null;

    public function __construct(
        private readonly AgentCard $agentCard,
        private readonly ?\Closure $cardModifier = null,
        private readonly int $maxAgeSeconds = 300,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?\Closure $signer = null,
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
        $card = $this->cardToServe();
        $body = Common::encode(ResponseHelpers::agentCardToDict($card));
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

    private function cardToServe(): AgentCard
    {
        if ($this->signer === null) {
            return $this->cardModifier !== null ? self::expectCard(($this->cardModifier)($this->agentCard)) : $this->agentCard;
        }
        if ($this->cardModifier === null && $this->signedCard !== null) {
            return $this->signedCard;
        }

        $copy = new AgentCard();
        $copy->mergeFrom($this->agentCard);
        if ($this->cardModifier !== null) {
            $copy = self::expectCard(($this->cardModifier)($copy));
        }
        $signed = self::expectCard(($this->signer)($copy));
        if ($this->cardModifier === null) {
            $this->signedCard = $signed;
        }

        return $signed;
    }

    private static function expectCard(mixed $card): AgentCard
    {
        if (!$card instanceof AgentCard) {
            throw new \UnexpectedValueException(sprintf('The card modifier and signer must return an AgentCard, got %s.', get_debug_type($card)));
        }

        return $card;
    }
}
