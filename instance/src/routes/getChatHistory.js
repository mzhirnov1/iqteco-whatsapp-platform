'use strict';

const { mapAckToGreen } = require('../lib/StateMap');

// The page is the primary source, but it can come up thin or broken: a
// restored session with a damaged IndexedDB answers fetchMessages with []
// (or throws) while the instance is happily authorized. Everything that ever
// flowed through webhooks is also in the MessageStore (Mongo, 90d TTL) — fall
// back to it so the customer sees their recent history instead of an empty
// chat while the page's own store is unusable.
function fromMessageStore(items) {
  return items.map((m) => {
    const md = m.payload?.messageData || {};
    return {
      idMessage: m.idMessage,
      timestamp: m.timestamp ?? null,
      type: m.direction === 'incoming' ? 'incoming' : 'outgoing',
      chatId: m.chatId,
      textMessage: md.textMessageData?.textMessage || md.extendedTextMessageData?.text || '',
      typeMessage: md.typeMessage || m.type,
      statusMessage: m.direction === 'outgoing' ? (m.payload?.statusMessage || 'sent') : null,
    };
  });
}

// wweb.js message.type -> Green API typeMessage (same table GreenApiMapper uses)
const TYPE_MAP = {
  chat: 'textMessage',
  image: 'imageMessage',
  video: 'videoMessage',
  audio: 'audioMessage',
  ptt: 'audioMessage',
  document: 'documentMessage',
  sticker: 'stickerMessage',
  location: 'locationMessage',
  vcard: 'contactMessage',
  multi_vcard: 'contactsArrayMessage',
  revoked: 'deletedMessage',
};

// Upstream path is client.getChatById() -> WWebJS.getChat() -> getChatModel():
// on LID-era chats getChatModel dies inside an IndexedDB lookup with a minified
// "r" (the same failure that used to take down getChats for the whole account,
// see getChats.js), so fetchMessages never even starts and every fresh link
// looked like it had no history at all (06.09.2026: 0 of 30 chats).
//
// This fallback never touches getChatModel: the chat comes straight from the
// in-memory collection, earlier pages are pulled with the same loader upstream
// uses, and each message is serialized on its own — a message whose full
// serialization throws still yields a minimal record from its plain attributes.
async function historyPerMessageFallback(ctx, chatId, count) {
  return ctx.client.pupPage.evaluate(async (chatId, count) => {
    const Coll = window.require('WAWebCollections');
    let wid = null;
    try { wid = window.require('WAWebWidFactory').createWid(chatId); } catch (e) { wid = null; }
    let chat = (wid && Coll.Chat.get(wid)) || Coll.Chat.get(chatId) || null;
    if (!chat) {
      chat = Coll.Chat.getModelsArray().find((c) => c && c.id && c.id._serialized === chatId) || null;
    }
    if (!chat) return { error: 'chat_not_found', msgs: [], failed: 0, loadError: null };

    const keep = (m) => m && !m.isNotification;
    let msgs = chat.msgs ? chat.msgs.getModelsArray().filter(keep) : [];
    let loadError = null;
    let guard = 0;
    while (msgs.length < count && guard++ < 20) {
      let loaded;
      try {
        loaded = await window.require('WAWebChatLoadMessages').loadEarlierMsgs({ chat });
      } catch (e) {
        loadError = String((e && (e.message || e)) || 'loadEarlierMsgs failed').slice(0, 200);
        break;
      }
      if (!loaded || !loaded.length) break;
      msgs = [...loaded.filter(keep), ...msgs];
    }

    const seen = new Set();
    msgs = msgs.filter((m) => {
      const k = m && m.id && m.id._serialized;
      if (!k || seen.has(k)) return false;
      seen.add(k);
      return true;
    });
    msgs.sort((a, b) => (a.t || 0) - (b.t || 0));
    if (msgs.length > count) msgs = msgs.slice(msgs.length - count);

    const ser = (v) => (typeof v === 'string' ? v : (v && v._serialized) || null);
    const out = [];
    let failed = 0;
    for (const m of msgs) {
      const plain = () => ({
        id: ser(m.id),
        fromMe: !!(m.id && m.id.fromMe),
        t: m.t || null,
        type: m.type || 'chat',
        body: m.body || m.caption || '',
        from: ser(m.from),
        to: ser(m.to),
        ack: m.ack,
      });
      try {
        const full = window.WWebJS.getMessageModel(m);
        out.push({
          id: ser(full.id) || ser(m.id),
          fromMe: !!((full.id && full.id.fromMe) || (m.id && m.id.fromMe)),
          t: full.t || m.t || null,
          type: full.type || m.type || 'chat',
          body: full.body || full.caption || m.body || '',
          from: ser(full.from) || ser(m.from),
          to: ser(full.to) || ser(m.to),
          ack: full.ack ?? m.ack,
        });
      } catch (e) {
        try { out.push(plain()); } catch (e2) { failed++; }
      }
    }
    return { msgs: out, failed, loadError };
  }, chatId, count);
}

function fromFallback(r, chatId) {
  return r.msgs
    .filter((m) => m && m.id)
    .map((m) => ({
      idMessage: m.id,
      timestamp: m.t,
      type: m.fromMe ? 'outgoing' : 'incoming',
      chatId: (m.fromMe ? m.to : m.from) || chatId,
      textMessage: m.body || '',
      typeMessage: TYPE_MAP[m.type] || m.type,
      statusMessage: m.fromMe ? mapAckToGreen(m.ack) : null,
    }))
    .reverse(); // newest first, like the primary path
}

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || req.query?.chatId || '').toString();
  const count = Math.min(Math.max(parseInt(req.body?.count || req.query?.count || 100, 10) || 100, 1), 500);
  if (!chatId) return res.status(400).json({ error: 'chatId required' });

  let out = null;
  try {
    const chat = await ctx.client.getChatById(chatId);
    const msgs = await chat.fetchMessages({ limit: count });
    out = msgs.map((m) => {
      const payload = m.fromMe
        ? ctx.mapper.toOutgoingMessageReceived(m)
        : ctx.mapper.toIncomingMessageReceived(m);
      return {
        idMessage: m.id?._serialized || '',
        timestamp: m.timestamp ?? null,
        type: payload.typeWebhook === 'incomingMessageReceived' ? 'incoming' : 'outgoing',
        chatId: m.fromMe ? m.to : m.from,
        textMessage: m.body || '',
        typeMessage: payload.messageData?.typeMessage || m.type,
        statusMessage: m.fromMe ? mapAckToGreen(m.ack) : null,
      };
    }).reverse();
  } catch (err) {
    ctx.logger.warn({ err: err.message, chatId }, 'getChatHistory: page fetch failed, per-message fallback');
    try {
      const r = await historyPerMessageFallback(ctx, chatId, count);
      if (r.error) {
        ctx.logger.warn({ chatId, reason: r.error }, 'getChatHistory fallback: chat not in collection');
      } else {
        out = fromFallback(r, chatId);
        ctx.logger.info(
          { chatId, count: out.length, failed: r.failed, loadError: r.loadError },
          'getChatHistory: served by per-message fallback',
        );
      }
    } catch (err2) {
      ctx.logger.warn({ err: err2.message, stack: err2.stack, chatId }, 'getChatHistory fallback failed, trying message store');
    }
  }

  if (!out || out.length === 0) {
    try {
      // minutes: 0 disables the time filter — the store's own 90d TTL bounds it
      const items = await ctx.messageStore.query({ chatId, minutes: 0, limit: count });
      const stored = fromMessageStore(items); // query sorts newest-first already
      if (stored.length || !out) {
        ctx.logger.info({ chatId, fromStore: stored.length }, 'getChatHistory: served from message store');
        return res.json(stored);
      }
    } catch (err) {
      ctx.logger.error({ err: err.message, chatId }, 'getChatHistory failed');
      if (!out) return res.status(500).json({ error: 'fetch_failed', message: err.message });
    }
  }
  res.json(out);
};
