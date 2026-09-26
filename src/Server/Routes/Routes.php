<?php

declare(strict_types=1);

namespace A2A\Server\Routes;

use A2A\Server\RequestHandlers\RequestHandler;
use A2A\Types\AgentCard;
use A2A\Utils\Constants;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factories for the A2A HTTP handlers, all PSR-15.
 *
 * Mirrors a2a-python: create_jsonrpc_routes(), create_rest_routes() and
 * create_agent_card_routes() in src/a2a/server/routes/.
 *
 *     $router = Routes::router($handler, $card, jsonRpcPath: '/a2a/jsonrpc', restPrefix: '/a2a/rest');
 *     (new ResponseEmitter($handler))->emit($router->handle(ServerRequestFactory::fromGlobals()));
 */
final class Routes
{
    private function __construct() {}

    public static function jsonRpc(
        RequestHandler $requestHandler,
        ?ServerCallContextBuilder $contextBuilder = null,
        LoggerInterface $logger = new NullLogger(),
    ): JsonRpcDispatcher {
        return new JsonRpcDispatcher($requestHandler, $contextBuilder, logger: $logger);
    }

    public static function rest(
        RequestHandler $requestHandler,
        string $pathPrefix = '',
        ?ServerCallContextBuilder $contextBuilder = null,
        LoggerInterface $logger = new NullLogger(),
    ): RestDispatcher {
        return new RestDispatcher($requestHandler, $pathPrefix, $contextBuilder, logger: $logger);
    }

    /**
     * @param (\Closure(AgentCard): AgentCard)|null $cardModifier
     * @param (\Closure(AgentCard): AgentCard)|null $signer       from Signing::createAgentCardSigner(); serves the card signed
     */
    public static function agentCard(AgentCard $agentCard, ?\Closure $cardModifier = null, ?\Closure $signer = null): AgentCardHandler
    {
        return new AgentCardHandler($agentCard, $cardModifier, signer: $signer);
    }

    /**
     * One handler serving the Agent Card, JSON-RPC and REST, for plain-PHP
     * front controllers. Pass null to leave a binding out.
     */
    public static function router(
        RequestHandler $requestHandler,
        AgentCard $agentCard,
        ?string $jsonRpcPath = '/',
        ?string $restPrefix = '/a2a/rest',
        string $agentCardPath = Constants::AGENT_CARD_WELL_KNOWN_PATH,
        ?ServerCallContextBuilder $contextBuilder = null,
        LoggerInterface $logger = new NullLogger(),
        ?\Closure $cardSigner = null,
    ): Router {
        $router = new Router();
        $router->add($agentCardPath, self::agentCard($agentCard, signer: $cardSigner));
        if ($jsonRpcPath !== null) {
            $router->add($jsonRpcPath, self::jsonRpc($requestHandler, $contextBuilder, $logger));
        }
        if ($restPrefix !== null) {
            $router->addRest(self::rest($requestHandler, $restPrefix, $contextBuilder, $logger));
        }

        return $router;
    }
}
