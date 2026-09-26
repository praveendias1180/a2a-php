# Hello-world agent

A port of the A2A Python SDK's `samples/hello_world_agent.py`: an agent that greets you, served over JSON-RPC (`/a2a/jsonrpc`) and HTTP+JSON (`/a2a/rest`), with its Agent Card at `/.well-known/agent-card.json`.

```bash
composer install
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:41241 examples/hello-world/server.php

# in another terminal
php examples/call-an-agent.php http://127.0.0.1:41241 hello
```

`php -S` is fine for trying it out. For real traffic with streaming, serve it with PHP-FPM behind nginx: the built-in server can queue a request behind a long-running stream.

Tasks and stream events are stored in SQLite (`A2A_DB`, default a file in the system temp dir), because every PHP request runs in its own process.

Like the Python sample, it also answers **A2A 0.3** clients (for example `a2a-sdk` 0.3.x): its card lists 0.3 interfaces next to the 1.0 ones, and the routes are built with `enableV03Compat: true`. See [Talking to A2A 0.3 agents and clients](https://praveendias1180.github.io/a2a-php/guides/a2a-0-3/).
