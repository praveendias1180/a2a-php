# Roadmap

Each phase ends with a check that proves it's done. Versions stay at `0.x` until the server passes the A2A test kit's MUST level.

| # | Phase | Done when |
|---|---|---|
| 0 | **Skeleton**: CI, generated types | ✅ |
| 1 | **Types + utilities**: errors, helpers, validators | ✅ |
| 2 | **Client**: JSON-RPC + REST + SSE | ✅ tested in CI against the official Python sample server |
| 3 | **Server core** + inline runner | ✅ the [A2A TCK](https://github.com/a2aproject/a2a-tck) passes at the MUST level (and SHOULD and MAY); see [Conformance](../reference/conformance.md) |
| 4 | **Laravel bridge** + queued runner | the TCK passes against a Laravel app on php-fpm + nginx |
| 5 | **Push notifications, card signing, extensions** (PDO task store and queue manager arrived early, in phase 3) | the TCK passes at the SHOULD level |
| 6 | **A2A 0.3 compatibility** | a 0.3 client works against a 1.0 server |
| 7 | **1.0.0** | stable release |
| 8 | Later | gRPC client, long-running runners, OpenTelemetry |

## Keeping up with Python

The SDK tracks a pinned commit of the Python SDK and a pinned spec version ([UPSTREAM.md](https://github.com/praveendias1180/a2a-php/blob/main/UPSTREAM.md)). When Python adds, renames or removes a public class, the [mapping](../python-sdk-mapping.md) changes first, then the code.
