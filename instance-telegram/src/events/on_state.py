import asyncio
import logging

from ..runtime import Runtime


log = logging.getLogger("on_state")


def register_state_handlers(rt: Runtime) -> None:
    # Telethon's TelegramClient surfaces connection state through internal
    # events on the network; we monitor via a polling loop because there is
    # no clean disconnect event for user code.
    asyncio.create_task(_watch_state(rt))


async def _watch_state(rt: Runtime) -> None:
    prev_connected = True
    prev_authorized = True
    while True:
        try:
            connected = rt.client.is_connected()
            try:
                authorized = await rt.client.is_user_authorized() if connected else False
            except Exception:
                authorized = False

            if connected != prev_connected or authorized != prev_authorized:
                state = "authorized" if (connected and authorized) else "notAuthorized"
                rt.state.authorized = bool(connected and authorized)
                rt.state.last_state = state
                try:
                    await rt.admin_client.heartbeat(state=state, wid=rt.state.wid)
                except Exception:
                    pass
                try:
                    await rt.webhook_sender.enqueue(
                        "stateInstanceChanged",
                        rt.mapper.to_state_instance_changed(state),
                    )
                except Exception:
                    pass
                prev_connected = connected
                prev_authorized = authorized
        except Exception as e:
            log.warning("state watch tick failed: %s", e)
        await asyncio.sleep(15)
