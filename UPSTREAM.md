# Upstream pins

This SDK tracks these upstream sources. Bump both deliberately, in their own PR.

| Upstream | Pinned at | Used for |
|---|---|---|
| A2A specification: [`a2aproject/A2A`](https://github.com/a2aproject/A2A) `specification/a2a.proto` | `v1.0.0` (`buf.gen.yaml`) | Generating `generated/` |
| A2A Python SDK: [`a2aproject/a2a-python`](https://github.com/a2aproject/a2a-python) | `0d5473c` (2026-09-24) | API shape, behaviour, tests to port |
| A2A TCK: [`a2aproject/a2a-tck`](https://github.com/a2aproject/a2a-tck) | `263b9cf` (2026-09-01; `A2A_TCK_REF` in `.github/workflows/ci.yml`) | Conformance checks; `tck/sut-agent.php` ports its generated Python SUT |
| buf PHP plugin | `buf.build/protocolbuffers/php:v36.2` | Code generation |

Notes:
- The spec `v1.0.1` changes only comments in `a2a.proto`. The Python SDK also stays on `v1.0.0`.
- When the Python SDK adds, renames or removes a public class, update `docs/python-sdk-mapping.md` first, then the code.
- The TCK bundles the v1.0 **release-candidate** spec, which maps `TaskNotCancelableError` to HTTP 409; the released v1.0.0 table says 400. We follow the TCK (see CHANGELOG).
