# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Wire types generated from the A2A v1.0.0 `a2a.proto` (`A2A\Types\*`).
- `A2A\Utils\Constants` and `A2A\Utils\TransportProtocol`.
- ProtoJSON round-trip tests against the specification's JSON shapes.
- Laravel bridge package skeleton (`packages/laravel`).
- Errors (`A2A\Utils\Errors\*`): one exception per A2A and JSON-RPC error, with the
  same JSON-RPC codes, HTTP statuses, gRPC statuses and ErrorInfo reasons as the
  Python SDK (`ErrorMapping`).
- `A2A\Utils\ErrorHandlers`: REST error payloads and JSON-RPC error objects, including
  `google.rpc.ErrorInfo` and `google.rpc.BadRequest` details. Unlike the Python
  SDK, a non-A2A exception becomes JSON-RPC `-32603 "Internal error"` without its
  message, so internal details never reach the caller (REST already hid them).
- `A2A\Utils\ProtoUtils`: stream-response wrapping, `Struct`/`Value` ↔ PHP conversion,
  REST query-parameter parsing, and REQUIRED-field validation driven by a table
  generated from `a2a.proto` (`A2A\Types\Meta\RequiredFields`,
  `scripts/generate-required-fields.php`).
- `A2A\Utils\TaskUtils` (history length, page size, page tokens), `JsonUtils`,
  `VersionValidator` (`A2A-Version` header) and `PushUrlValidator` (SSRF guard for
  push-notification URLs, with an injectable resolver).
- `A2A\Utils\Jcs`: RFC 8785 JSON canonicalization, checked against the a2a-jcs-v01
  vector corpus and against ECMAScript number formatting.
- `A2A\Helpers\ProtoHelpers` and `AgentCardHelpers`, `A2A\Extensions\Common`,
  `A2A\Auth\User` and `UnauthenticatedUser`.
- The client (`A2A\Client\*`), in the shape of the Python SDK's `a2a.client`:
  `ClientFactory` (with `createClient()` and `minimalAgentCard()`), `BaseClient`,
  `ClientConfig`, `ClientCallContext`, `A2ACardResolver` (including the pre-1.0 card
  field mapping), interceptors (`ClientCallInterceptor`, `BeforeArgs`, `AfterArgs`),
  `AuthInterceptor` with `InMemoryContextCredentialStore`, service parameters, and the
  client errors (`A2AClientError`, `A2AClientTimeoutError`, `AgentCardResolutionError`).
- JSON-RPC and HTTP+JSON transports (`JsonRpcTransport`, `RestTransport`,
  `TenantTransportDecorator`) with every A2A operation. Errors from the agent come back
  as the matching `A2A\Utils\Errors\*` exception. Streams are PHP Generators.
- `A2A\Client\Http\HttpSender` with senders for Guzzle and Symfony HttpClient (live
  SSE streaming) and any PSR-18 client (buffered), picked by `HttpSenderFactory`; an
  incremental SSE parser (`A2A\Client\Sse\EventStreamParser`).
- Interop tests against the official Python SDK's sample agent (a2a-sdk 1.1.5): every
  client operation over JSON-RPC and HTTP+JSON, with Guzzle, Symfony HttpClient and a
  PSR-18 client (`scripts/run-python-interop.sh`, CI job `python-interop`).
- `examples/call-an-agent.php`, shown on the docs site and run in CI.

### Changed
- Requires `php-http/discovery` (finds a PSR-18 client when none is given) and
  `psr/http-factory` ^1.1.
