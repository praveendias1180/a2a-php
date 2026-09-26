"""A hello-world agent served by the A2A Python SDK 0.3.x (a real v0.3
server), for testing the PHP SDK's v0.3 client transports.

It mirrors the v1.0 hello_world_agent.py next to it (same replies, same
1-second pause, cancellable), written against the a2a-sdk 0.3 API.
JSON-RPC is served at /a2a/jsonrpc and HTTP+JSON at /a2a/rest.

    python v03_hello_world_agent.py --port 41242
"""

import argparse
import asyncio
import uuid

import uvicorn

from fastapi import FastAPI

from a2a.server.agent_execution import AgentExecutor, RequestContext
from a2a.server.apps import A2ARESTFastAPIApplication, A2AStarletteApplication
from a2a.server.events import EventQueue
from a2a.server.request_handlers import DefaultRequestHandler
from a2a.server.tasks import InMemoryTaskStore, TaskUpdater
from a2a.types import (
    AgentCapabilities,
    AgentCard,
    AgentInterface,
    AgentSkill,
    Part,
    Task,
    TaskState,
    TaskStatus,
    TextPart,
    TransportProtocol,
)


class HelloExecutor(AgentExecutor):
    def __init__(self) -> None:
        self.running: set[str] = set()

    async def execute(self, context: RequestContext, event_queue: EventQueue) -> None:
        task_id, context_id = context.task_id, context.context_id
        if not context.message or not task_id or not context_id:
            return
        self.running.add(task_id)
        await event_queue.enqueue_event(Task(
            id=task_id,
            context_id=context_id,
            status=TaskStatus(state=TaskState.submitted),
            history=[context.message],
        ))
        updater = TaskUpdater(event_queue, task_id, context_id)
        await updater.start_work(updater.new_agent_message([Part(root=TextPart(text='Processing your question...'))]))
        query = context.get_user_input()
        await asyncio.sleep(1)
        if task_id not in self.running:
            return
        reply = 'Hello World! Nice to meet you!' if 'hello' in query.lower() else f"Hello World! You said: '{query}'."
        await updater.add_artifact([Part(root=TextPart(text=reply))], name='response', last_chunk=True)
        await updater.complete()

    async def cancel(self, context: RequestContext, event_queue: EventQueue) -> None:
        self.running.discard(context.task_id or '')
        await TaskUpdater(event_queue, context.task_id or '', context.context_id or '').cancel()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=41242)
    args = parser.parse_args()
    base = f'http://{args.host}:{args.port}'

    card = AgentCard(
        name='Sample Agent (v0.3)',
        description='A v0.3 hello-world agent for interop tests.',
        version='1.0.0',
        protocol_version='0.3.0',
        url=f'{base}/a2a/jsonrpc',
        preferred_transport=TransportProtocol.jsonrpc,
        additional_interfaces=[AgentInterface(url=f'{base}/a2a/rest', transport=TransportProtocol.http_json)],
        capabilities=AgentCapabilities(streaming=True, push_notifications=False),
        default_input_modes=['text'],
        default_output_modes=['text'],
        skills=[AgentSkill(id='hello', name='Hello', description='Say hi.', tags=['sample'], examples=['hi'])],
    )
    handler = DefaultRequestHandler(agent_executor=HelloExecutor(), task_store=InMemoryTaskStore())

    app = FastAPI()
    jsonrpc = A2AStarletteApplication(agent_card=card, http_handler=handler)
    jsonrpc.add_routes_to_app(app, rpc_url='/a2a/jsonrpc')
    rest = A2ARESTFastAPIApplication(agent_card=card, http_handler=handler)
    app.mount('/a2a/rest', rest.build(rpc_url=''))

    uvicorn.run(app, host=args.host, port=args.port, log_level='warning')


if __name__ == '__main__':
    main()
