from fastapi import FastAPI

from ..runtime import Runtime
from . import state, send, qr, chats, contacts, edit, media, settings, notifications


def mount_routes(app: FastAPI, rt: Runtime) -> None:
    app.state.rt = rt
    state.register(app, rt)
    qr.register(app, rt)
    settings.register(app, rt)
    send.register(app, rt)
    chats.register(app, rt)
    contacts.register(app, rt)
    edit.register(app, rt)
    media.register(app, rt)
    notifications.register(app, rt)

    # Fallback 501 for unimplemented Green API methods
    from .fallback import register as register_fallback
    register_fallback(app, rt)
