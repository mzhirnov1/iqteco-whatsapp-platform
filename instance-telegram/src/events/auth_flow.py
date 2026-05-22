import asyncio
import base64
import io
import logging
import time
from typing import Optional

import qrcode
from telethon import errors as tg_errors

from ..runtime import Runtime
from .on_message import register_message_handlers
from .on_state import register_state_handlers


log = logging.getLogger("auth-flow")


async def run_auth_flow(rt: Runtime) -> None:
    """
    Top-level loop running for the lifetime of the container.
    Handles auth (qr or phone-code), then sticks around streaming events.
    Mirrors instance/src/app.js + onReady/onAuthFailure/onQR/onCode behaviour.
    """
    client = rt.client
    if client is None:
        log.error("client not built — cannot run auth flow")
        return

    await client.connect()
    authorized = False
    try:
        authorized = await client.is_user_authorized()
    except Exception as e:
        log.warning("is_user_authorized check failed: %s", e)

    if not authorized:
        method = rt.cfg.tg_auth_method or "tg_qr"
        log.info("auth needed method=%s", method)
        try:
            if method == "tg_phone_code":
                await _phone_code_login(rt)
            else:
                await _qr_login(rt)
        except Exception as e:
            log.exception("auth flow failed: %s", e)
            rt.state.authorized = False
            rt.state.last_state = "auth_failure"
            try:
                await rt.admin_client.state_change("auth_needed", "notAuthorized", "auth_failure")
            except Exception:
                pass
    else:
        log.info("already authorized via restored session")

    if await client.is_user_authorized():
        await _on_ready(rt)
        register_state_handlers(rt)
        register_message_handlers(rt)
        # Keep the loop alive — Telethon dispatches updates internally.
        await client.run_until_disconnected()
    else:
        # Keep process alive so /getQrCode and /getAuthorizationCode work for retries.
        log.info("not authorized; waiting for operator action")
        while True:
            await asyncio.sleep(30)


async def _qr_login(rt: Runtime) -> None:
    client = rt.client
    qr_login = await client.qr_login()
    rt.qr_cache.qr_url = qr_login.url
    rt.qr_cache.expires_at = qr_login.expires.timestamp() if qr_login.expires else time.time() + 30
    png = _qr_png_base64(qr_login.url)
    rt.qr_cache.png_base64 = png
    log.info("qr login url generated, expires=%s", qr_login.expires)
    await rt.admin_client.send_qr(qr=png, expires_at=int(rt.qr_cache.expires_at), kind="tg_qr")

    # Refresh QR every time it expires until user scans
    while not rt.state.authorized:
        try:
            user = await qr_login.wait(timeout=qr_login.expires.timestamp() - time.time() if qr_login.expires else 60)
            rt.state.wid = f"{user.id}@c.us"
            return
        except tg_errors.SessionPasswordNeededError:
            await _handle_2fa(rt)
            return
        except asyncio.TimeoutError:
            try:
                qr_login = await client.qr_login()
                rt.qr_cache.qr_url = qr_login.url
                rt.qr_cache.expires_at = qr_login.expires.timestamp() if qr_login.expires else time.time() + 30
                rt.qr_cache.png_base64 = _qr_png_base64(qr_login.url)
                await rt.admin_client.send_qr(qr=rt.qr_cache.png_base64,
                                              expires_at=int(rt.qr_cache.expires_at), kind="tg_qr")
            except Exception as e:
                log.warning("qr refresh failed: %s", e)
                await asyncio.sleep(2)


async def _phone_code_login(rt: Runtime) -> None:
    client = rt.client
    phone = rt.cfg.tg_phone
    if not phone:
        log.error("phone-code auth selected but TG_PHONE is empty")
        await rt.admin_client.state_change("auth_needed", "notAuthorized", "phone_missing")
        return
    sent = await client.send_code_request(phone)
    rt.code_cache.phone = phone
    rt.code_cache.phone_code_hash = sent.phone_code_hash
    rt.code_cache.expires_at = time.time() + 300
    log.info("phone code requested for %s, awaiting operator input", phone)
    await rt.admin_client.send_qr(qr="", expires_at=int(rt.code_cache.expires_at), kind="tg_phone_code")
    await rt.admin_client.state_change("auth_needed", "auth_needed", "awaiting_phone_code")
    # The code arrives via POST /getAuthorizationCode (see submit_phone_auth below).
    while not rt.state.authorized:
        if time.time() > rt.code_cache.expires_at + 60:
            log.warning("phone code timeout, re-requesting")
            sent = await client.send_code_request(phone)
            rt.code_cache.phone_code_hash = sent.phone_code_hash
            rt.code_cache.expires_at = time.time() + 300
        await asyncio.sleep(2)


async def submit_phone_auth(rt: Runtime, payload: dict) -> dict:
    """
    Called from POST /waInstance{id}/getAuthorizationCode/{token}
    Body: {code: "..."} or {password: "..."} or {phone: "..."}.
    """
    client = rt.client
    if not client:
        return {"status": False, "error": "client_not_ready"}
    new_phone = payload.get("phone")
    if new_phone:
        sent = await client.send_code_request(str(new_phone))
        rt.code_cache.phone = str(new_phone)
        rt.code_cache.phone_code_hash = sent.phone_code_hash
        rt.code_cache.expires_at = time.time() + 300
        return {"status": True, "stage": "code_sent"}

    if payload.get("password"):
        try:
            user = await client.sign_in(password=str(payload["password"]))
            rt.state.wid = f"{user.id}@c.us"
            rt.state.authorized = True
            await _on_ready(rt)
            return {"status": True, "stage": "authorized"}
        except Exception as e:
            return {"status": False, "error": f"2fa_failed: {e}"}

    code = payload.get("code")
    if code is None:
        return {"status": False, "error": "missing_code_or_password_or_phone"}

    phone = rt.code_cache.phone or rt.cfg.tg_phone
    if not phone or not rt.code_cache.phone_code_hash:
        return {"status": False, "error": "no_pending_code_request"}
    try:
        user = await client.sign_in(
            phone=phone, code=str(code),
            phone_code_hash=rt.code_cache.phone_code_hash,
        )
        rt.state.wid = f"{user.id}@c.us"
        rt.state.authorized = True
        await _on_ready(rt)
        return {"status": True, "stage": "authorized"}
    except tg_errors.SessionPasswordNeededError:
        await rt.admin_client.send_qr(qr="", expires_at=int(time.time() + 300), kind="tg_2fa_password")
        return {"status": True, "stage": "awaiting_2fa_password"}
    except Exception as e:
        return {"status": False, "error": str(e)}


async def _handle_2fa(rt: Runtime) -> None:
    """When QR-login surfaces 2FA needed, ask operator for password via admin UI."""
    log.info("2fa password required; signaling admin")
    await rt.admin_client.send_qr(qr="", expires_at=int(time.time() + 300), kind="tg_2fa_password")
    await rt.admin_client.state_change("auth_needed", "auth_needed", "awaiting_2fa_password")
    while not rt.state.authorized:
        await asyncio.sleep(2)


async def _on_ready(rt: Runtime) -> None:
    rt.state.authorized = True
    rt.state.last_state = "authorized"
    rt.qr_cache.png_base64 = None
    rt.qr_cache.qr_url = None
    rt.code_cache.code = None
    me = None
    try:
        me = await rt.client.get_me()
    except Exception as e:
        log.warning("get_me failed: %s", e)
    wid = None
    phone = None
    if me is not None:
        wid = f"{me.id}@c.us"
        phone = (me.phone and f"+{me.phone}") or None
        rt.state.wid = wid
        rt.mapper.set_wid(wid)
    try:
        await rt.admin_client.heartbeat(state="authorized", wid=wid, phone_number=phone)
    except Exception:
        pass
    try:
        await rt.webhook_sender.enqueue(
            "stateInstanceChanged",
            rt.mapper.to_state_instance_changed("authorized"),
        )
    except Exception:
        pass
    log.info("on_ready: authorized wid=%s phone=%s", wid, phone)


def _qr_png_base64(url: str) -> str:
    img = qrcode.make(url)
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode("ascii")
