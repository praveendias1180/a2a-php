# Roadmap

Each phase ended with a check that proves it's done. The SDK was `0.x` through phase 6 and follows SemVer from 1.0 ([backward compatibility](../reference/backward-compatibility.md)).

| # | Phase | Done when |
|---|---|---|
| 0 | **Skeleton**: CI, generated types | ✅ |
| 1 | **Types + utilities**: errors, helpers, validators | ✅ |
| 2 | **Client**: JSON-RPC + REST + SSE | ✅ tested in CI against the official Python sample server |
| 3 | **Server core** + inline runner | ✅ the [A2A TCK](https://github.com/a2aproject/a2a-tck) passes at the MUST level (and SHOULD and MAY); see [Conformance](../reference/conformance.md) |
| 4 | **Laravel bridge** + queued runner | ✅ the TCK passes (MUST, SHOULD, MAY) against a Laravel app on PHP-FPM + nginx whose executors run on queue workers; see [Conformance](../reference/conformance.md#laravel-bridge) |
| 5 | **Push notifications, card signing, extensions** (PDO task store and queue manager arrived early, in phase 3) | ✅ the push-notification, extended-card and required-extension TCK requirements run and pass (MUST 161 distinct tests, SHOULD, MAY, 0 failures; plain PHP and Laravel), and card signatures verify across the PHP and Python SDKs; see [Conformance](../reference/conformance.md) |
| 6 | **A2A 0.3 compatibility** | ✅ the last 0.3 release of the Python SDK (a2a-sdk 0.3.26) works against the PHP server (plain PHP and Laravel), and the PHP client works against it, over JSON-RPC and HTTP+JSON; see [Conformance](../reference/conformance.md#a2a-03-compatibility) |
| 7 | **1.0.0** | ✅ public API reviewed (`@internal` marked), [API reference](../reference/api.md), [upgrade guide](../guides/upgrading.md), [benchmarks](../reference/performance.md), weekly upstream watch against the Python SDK, spec and TCK |
| 8 | Later | gRPC client, long-running runners, OpenTelemetry |

## Keeping up with Python

The SDK tracks a pinned commit of the Python SDK and a pinned spec version ([UPSTREAM.md](https://github.com/praveendias1180/a2a-php/blob/main/UPSTREAM.md)). When Python adds, renames or removes a public class, the [mapping](../python-sdk-mapping.md) changes first, then the code.
