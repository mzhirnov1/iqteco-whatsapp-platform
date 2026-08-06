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
    ctx.logger.warn({ err: err.message, stack: err.stack, chatId }, 'getChatHistory: page fetch failed, trying message store');
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
