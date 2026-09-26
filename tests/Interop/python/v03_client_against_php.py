"""An A2A v0.3 client (the Python a2a-sdk 0.3.x) against the PHP SDK's v1.0
hello-world server with the v0.3 compatibility layer on.

Run with a2a-sdk 0.3.x on PYTHONPATH (see requirements-v03.txt):

    python v03_client_against_php.py http://127.0.0.1:41241
    python v03_client_against_php.py http://127.0.0.1:9998 --agent tck

`--agent hello` (default) expects examples/hello-world/server.php; `--agent
tck` expects the TCK executor (tck/TckAgentExecutor.php, e.g. the Laravel
app from scripts/run-tck-laravel.sh v03-interop), which picks its behaviour
from the messageId prefix.

Prints one OK line per transport and operation; exits non-zero on the first
failure.
"""

import argparse
import asyncio
import uuid

import httpx

from a2a.client import A2ACardResolver, ClientConfig, ClientFactory
from a2a.client.errors import A2AClientError, A2AClientHTTPError, A2AClientJSONRPCError
from a2a.types import (
    Message,
    MessageSendConfiguration,
    Part,
    PushNotificationConfig,
    Role,
    Task,
    TaskIdParams,
    TaskPushNotificationConfig,
    TaskQueryParams,
    TaskState,
    TextPart,
    TransportProtocol,
)


# What each agent does for a scenario: (messageId prefix, expected artifact text).
SCENARIOS = {
    'hello': {
        'stream': ('', 'Hello World! Nice to meet you!'),
        'blocking': ('', 'Hello World! Nice to meet you!'),
        'resubscribe': '',
        'cancel': '',
    },
    'tck': {
        'stream': ('tck-stream-001-', 'Stream hello from TCK'),
        'blocking': ('tck-artifact-text-', 'Generated text content'),
        'resubscribe': 'test-resubscribe-message-id-',
        'cancel': 'tck-input-required-',
    },
}


def user_message(text: str, prefix: str = '') -> Message:
    return Message(
        role=Role.user,
        parts=[Part(root=TextPart(text=text))],
        message_id=prefix + uuid.uuid4().hex,
    )


def artifact_text(task: Task) -> str:
    texts = []
    for artifact in task.artifacts or []:
        for part in artifact.parts:
            if isinstance(part.root, TextPart):
                texts.append(part.root.text)
    return ' '.join(texts)


def ok(label: str, op: str, detail: str) -> None:
    print(f'{label:<10} {op:<22}: OK  {detail}', flush=True)


async def run(base_url: str, transport: str, label: str, agent: str) -> None:
    scenario = SCENARIOS[agent]
    async with httpx.AsyncClient(timeout=30) as http:
        card = await A2ACardResolver(http, base_url).get_agent_card()
        assert card.protocol_version.startswith('0.3'), card.protocol_version

        def client(streaming: bool, polling: bool = False):
            config = ClientConfig(
                httpx_client=http,
                streaming=streaming,
                polling=polling,
                supported_transports=[transport],
            )
            return ClientFactory(config).create(card)

        # 1. Streaming send: a Task, status updates and the artifact, live.
        events = []
        last_task = None
        prefix, expected = scenario['stream']
        async for event in client(streaming=True).send_message(user_message('hello', prefix)):
            if isinstance(event, Message):
                raise AssertionError(f'unexpected Message: {event}')
            task, update = event
            last_task = task
            events.append(type(update).__name__ if update is not None else 'Task')
        assert last_task is not None and last_task.status.state == TaskState.completed, last_task
        reply = artifact_text(last_task)
        assert expected in reply, reply
        ok(label, 'send (stream)', f'events={events} state={last_task.status.state.value} reply={reply!r}')

        # 2. Blocking send.
        result = None
        prefix, expected = scenario['blocking']
        async for event in client(streaming=False).send_message(user_message('hello', prefix)):
            result = event
        assert isinstance(result, tuple), result
        task = result[0]
        assert task.status.state == TaskState.completed, task.status
        assert expected in artifact_text(task), artifact_text(task)
        ok(label, 'send (blocking)', f'state={task.status.state.value} reply={artifact_text(task)!r}')

        # 3. GetTask, with a history limit.
        got = await client(streaming=False).get_task(TaskQueryParams(id=task.id, history_length=1))
        assert got.id == task.id and got.status.state == TaskState.completed, got
        assert got.history is not None and len(got.history) <= 1, got.history
        ok(label, 'get task', f'state={got.status.state.value} history={len(got.history or [])}')

        # 4. Resubscribe to a running task (non-blocking send first).
        polling = client(streaming=False, polling=True)
        started = None
        async for event in polling.send_message(user_message('hello', scenario['resubscribe'])):
            started = event[0]
        assert started is not None and started.status.state in (TaskState.submitted, TaskState.working), started
        states = []
        async for task_now, update in client(streaming=True).resubscribe(TaskIdParams(id=started.id)):
            states.append(task_now.status.state.value)
        assert states and states[-1] == TaskState.completed.value, states
        ok(label, 'resubscribe', f'states={states}')

        # 5. Cancel a running (hello) or paused (tck: input-required) task.
        running = None
        async for event in polling.send_message(user_message('hello', scenario['cancel'])):
            running = event[0]
        canceled = await polling.cancel_task(TaskIdParams(id=running.id))
        assert canceled.status.state == TaskState.canceled, canceled.status
        ok(label, 'cancel', f'state={canceled.status.state.value}')

        # 6. Unknown task: the v0.3 "task not found" error.
        try:
            await polling.get_task(TaskQueryParams(id='does-not-exist'))
            raise AssertionError('expected an error for an unknown task')
        except A2AClientJSONRPCError as e:
            assert e.error.code == -32001, e.error
            ok(label, 'unknown task', f'error code={e.error.code} ({e.error.message})')
        except A2AClientHTTPError as e:
            assert e.status_code == 404, e
            ok(label, 'unknown task', f'HTTP {e.status_code}')

        # 7. Push config on an agent without push support: the v0.3 error.
        try:
            await polling.set_task_callback(TaskPushNotificationConfig(
                task_id=task.id,
                push_notification_config=PushNotificationConfig(id='c1', url='https://example.com/hook'),
            ))
            raise AssertionError('expected an error: push notifications are off')
        except A2AClientJSONRPCError as e:
            assert e.error.code == -32003, e.error
            ok(label, 'push unsupported', f'error code={e.error.code} ({e.error.message})')
        except A2AClientHTTPError as e:
            assert e.status_code == 400, e
            ok(label, 'push unsupported', f'HTTP {e.status_code}')


async def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('base_url', nargs='?', default='http://127.0.0.1:41241')
    parser.add_argument('--agent', choices=sorted(SCENARIOS), default='hello')
    args = parser.parse_args()
    await run(args.base_url, TransportProtocol.jsonrpc, 'JSONRPC', args.agent)
    await run(args.base_url, TransportProtocol.http_json, 'HTTP+JSON', args.agent)
    print('all v0.3 client checks passed', flush=True)


if __name__ == '__main__':
    asyncio.run(main())
