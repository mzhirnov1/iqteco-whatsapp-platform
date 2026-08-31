'use strict';

const { MessageMedia } = require('whatsapp-web.js');
const { resolveJid } = require('../lib/jid');

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, urlFile, fileName, caption, quotedMessageId } = req.body || {};
  if (!chatId || !urlFile) {
    return res.status(400).json({ error: 'chatId and urlFile required' });
  }

  const resolved = await resolveJid(ctx.client, chatId);
  if (!resolved.ok) {
    ctx.logger.warn({ chatId, reason: resolved.reason }, 'sendImageByUrl skipped (jid)');
    return res.json({ idMessage: null, error: resolved.reason });
  }

  try {
    const media = await MessageMedia.fromUrl(urlFile, { unsafeMime: true, filename: fileName });
    const opts = { sendMediaAsDocument: false };
    if (caption) opts.caption = caption;
    if (quotedMessageId) opts.quotedMessageId = quotedMessageId;

    const sent = await ctx.client.sendMessage(resolved.jid, media, opts);
    if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
    if (sent?.id?._serialized) {
      res.json({ idMessage: sent.id._serialized });
    } else {
      // The message DID go out — wweb.js returned undefined because the Store
      // failed to serialize the sent-message model (same broken-Store family
      // as getChats/avatars, 31.08.2026). Reporting an error here made the
      // operator retry and the customer got duplicates; `accepted` tells the
      // caller to trust the outgoing webhook for the real id.
      ctx.logger.warn({ chatId, jid: resolved.jid }, 'send: sent but the model failed to serialize (no id)');
      res.json({ idMessage: null, accepted: true });
    }
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, jid: resolved.jid, urlFile }, 'sendImageByUrl failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
