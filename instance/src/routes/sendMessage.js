'use strict';

const { resolveJid } = require('../lib/jid');

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, message, quotedMessageId } = req.body || {};
  if (!chatId || typeof chatId !== 'string') {
    return res.status(400).json({ error: 'chatId required' });
  }
  if (typeof message !== 'string') {
    return res.status(400).json({ error: 'message required' });
  }

  const resolved = await resolveJid(ctx.client, chatId);
  if (!resolved.ok) {
    // Green-API contract: HTTP 200 with idMessage:null even when number is
    // not on WhatsApp — callers (handler.php) decide what to do with it.
    ctx.logger.warn({ chatId, reason: resolved.reason }, 'sendMessage skipped (jid)');
    return res.json({ idMessage: null, error: resolved.reason });
  }

  try {
    const opts = quotedMessageId ? { quotedMessageId } : {};
    const sent = await ctx.client.sendMessage(resolved.jid, message, opts);
    if (sent?.id?._serialized) {
      ctx.outgoingApiIds.add(sent.id._serialized);
    }
    res.json({ idMessage: sent?.id?._serialized || null });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, jid: resolved.jid }, 'sendMessage failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
