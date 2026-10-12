# Upstream pins

This SDK tracks these upstream sources. Bump them deliberately, in their own PR. `.github/workflows/upstream-watch.yml` checks them weekly and keeps one GitHub issue (label `upstream`) listing what changed upstream; `scripts/upstream-watch.py` reads the pins from the files below, so keep them there.

| Upstream | Pinned at | Used for |
|---|---|---|
| A2A specification: [`a2aproject/A2A`](https://github.com/a2aproject/A2A) `specification/a2a.proto` | `v1.0.0` (`buf.gen.yaml`) | Generating `generated/` |
| A2A Python SDK: [`a2aproject/a2a-python`](https://github.com/a2aproject/a2a-python) | `494a8ec` (2026-10-08; reviewed from `0d5473c`, released as `a2a-sdk` 1.2.2) | API shape, behaviour, tests to port |
| A2A TCK: [`a2aproject/a2a-tck`](https://github.com/a2aproject/a2a-tck) | `263b9cf` (2026-09-01; `A2A_TCK_REF` in `.github/workflows/ci.yml`) | Conformance checks; `tck/sut-agent.php` ports its generated Python SUT |
| A2A v0.3 proto (compatibility layer) | [`b3b266d`](https://github.com/a2aproject/A2A/blob/b3b266d127dde3d1000ec103b252d1de81289e83/specification/grpc/a2a.proto), vendored as `proto/v0_3/a2a_v0_3.proto` | Generating `generated/A2A/Compat/V0_3/Types` (same commit as the Python SDK's `scripts/gen_proto.sh`) |
| A2A Python SDK release used by the interop tests | `a2a-sdk==1.2.2` (`tests/Interop/python/requirements.txt`) | Python ↔ PHP interop and signing checks in CI |
| A2A Python SDK, v0.3 line | `a2a-sdk==0.3.26` (`tests/Interop/python/requirements-v03.txt`) | v0.3 interop in CI |
| buf PHP plugin | `buf.build/protocolbuffers/php:v36.2` | Code generation |

Notes:
- The spec `v1.0.1` changes only comments in `a2a.proto`. The Python SDK also stays on `v1.0.0`. Checked again 2026-10-12: every `a2a.proto` change from `v1.0.0` to A2A `main` is a comment (`cd87b93` tenant-field docs, `cfc9d34` gRPC URL example), so the pin stays.
- 2026-10-12 catch-up from `0d5473c` to `494a8ec` (33 commits, a2a-sdk 1.2.0 to 1.2.2): ported the interface-version, card-parse, JCS-depth, push-config-id, ListTasks-cursor, `parseError` and If-None-Match fixes, the required-field warning and the cross-SDK signing and JCS-depth vectors. Not ported yet: cluster mode (`a2a.server.cluster`), opt-in input-mode validation, and `application/a2a+json` responses (spec v1.0.0 and the TCK still require `application/json`). See CHANGELOG.
- When the Python SDK adds, renames or removes a public class, update `docs/python-sdk-mapping.md` first, then the code.
- The TCK bundles the v1.0 **release-candidate** spec, which maps `TaskNotCancelableError` to HTTP 409; the released v1.0.0 table says 400. We follow the TCK (see CHANGELOG).
