"""The official A2A Python SDK client, talking to the PHP hello-world server.

Runs a streaming and a blocking SendMessage, then GetTask and ListTasks,
over one protocol binding, and exits non-zero on any mismatch.

    python3 tests/Interop/python/client_against_php.py http://127.0.0.1:41242 JSONRPC
"""

import asyncio
import sys
import uuid

from a2a.client import ClientConfig, create_client
from a2a.types import (
    GetTaskRequest,
    ListTasksRequest,
    Message,
    Part,
    Role,
    SendMessageRequest,
    TaskState,
)

EXPECTED_REPLY = 'Hello World! Nice to meet you!'


def fail(message: str) -> None:
    print(f'FAIL: {message}')
    sys.exit(1)


async def exchange(url: str, binding: str, streaming: bool) -> str:
    client = await create_client(
        url,
        client_config=ClientConfig(
            streaming=streaming, supported_protocol_bindings=[binding]
        ),
    )
    message = Message(
        role=Role.ROLE_USER,
        message_id=str(uuid.uuid4()),
        parts=[Part(text='hello')],
    )

    kinds: list[str] = []
    task_id = ''
    reply = ''
    final_state = TaskState.TASK_STATE_UNSPECIFIED
    async for event in client.send_message(SendMessageRequest(message=message)):
        kind = event.WhichOneof('payload')
        kinds.append(kind)
        if kind == 'task':
            task_id = event.task.id
            final_state = event.task.status.state
            for artifact in event.task.artifacts:
                reply = ''.join(p.text for p in artifact.parts)
        elif kind == 'status_update':
            final_state = event.status_update.status.state
        elif kind == 'artifact_update':
            reply = ''.join(p.text for p in event.artifact_update.artifact.parts)

    if final_state != TaskState.TASK_STATE_COMPLETED:
        fail(f'{binding} streaming={streaming}: final state {TaskState.Name(final_state)}')
    if reply != EXPECTED_REPLY:
        fail(f'{binding} streaming={streaming}: reply {reply!r}')

    task = await client.get_task(GetTaskRequest(id=task_id))
    if task.status.state != TaskState.TASK_STATE_COMPLETED:
        fail(f'{binding}: GetTask state {TaskState.Name(task.status.state)}')

    listed = await client.list_tasks(ListTasksRequest(context_id=task.context_id))
    if task_id not in [t.id for t in listed.tasks]:
        fail(f'{binding}: ListTasks did not return {task_id}')

    await client.close()
    mode = 'streaming' if streaming else 'blocking '
    return (
        f'{binding:<9} {mode}: OK  events={kinds} '
        f'state={TaskState.Name(task.status.state)} reply={reply!r}'
    )


async def main() -> None:
    url, binding = sys.argv[1], sys.argv[2]
    for streaming in (True, False):
        print(await exchange(url, binding, streaming))


if __name__ == '__main__':
    asyncio.run(main())
