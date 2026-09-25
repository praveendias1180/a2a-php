# Contributing

Thanks for helping. This SDK has one design rule above the others:

**Keep the shape of the Python SDK.** Every public Python class has a PHP class with the same name in the same place. The mapping is in [Python → PHP mapping](https://praveendias1180.github.io/a2a-php/python-sdk-mapping/). If you add a public class, it should exist in the Python SDK, or the PR should explain why PHP needs it (e.g. the `TaskRunner` for PHP-FPM).

## Workflow

1. Open an issue first for anything bigger than a small fix.
2. Fork, branch, and open a PR against `main`.
3. Use [Conventional Commits](https://www.conventionalcommits.org/) for the PR title (`feat:`, `fix:`, `docs:` …). The changelog is built from them.
4. CI must pass: PHPUnit on PHP 8.2–8.5 (lowest and highest dependencies), PHPStan level max, php-cs-fixer, and the generated-types check.

## Local checks

```bash
composer install
composer test && composer analyse && composer cs
```

## Generated code

Never edit `generated/` by hand. Change `buf.gen.yaml` (or the pinned spec version) and run `composer generate`. CI fails if `generated/` differs from a fresh generation.

## Porting tests from Python

When porting a behaviour, port its Python test too, and keep the test file path parallel (`tests/server/tasks/test_task_updater.py` → `tests/Server/Tasks/TaskUpdaterTest.php`).
