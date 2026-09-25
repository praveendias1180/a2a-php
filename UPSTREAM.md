# Upstream pins

This SDK tracks two upstream sources. Bump both deliberately, in their own PR.

| Upstream | Pinned at | Used for |
|---|---|---|
| A2A specification: [`a2aproject/A2A`](https://github.com/a2aproject/A2A) `specification/a2a.proto` | `v1.0.0` (`buf.gen.yaml`) | Generating `generated/` |
| A2A Python SDK: [`a2aproject/a2a-python`](https://github.com/a2aproject/a2a-python) | `0d5473c` (2026-09-24) | API shape, behaviour, tests to port |
| buf PHP plugin | `buf.build/protocolbuffers/php:v36.2` | Code generation |

Notes:
- The spec `v1.0.1` changes only comments in `a2a.proto`. The Python SDK also stays on `v1.0.0`.
- When the Python SDK adds, renames or removes a public class, update `docs/python-sdk-mapping.md` first, then the code.
