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
    ctx.logger.warn({ chatId, reason: resolved.reason }, 'sendFileByUrl skipped (jid)');
    return res.json({ idMessage: null, error: resolved.reason });
  }

  try {
    const media = await MessageMedia.fromUrl(urlFile, {
      unsafeMime: true,
      filename: fileName || undefined,
    });

    const opts = {};
    if (caption) opts.caption = caption;
    if (quotedMessageId) opts.quotedMessageId = quotedMessageId;
    if (fileName) {
      media.filename = fileName;
      opts.sendMediaAsDocument = /\.(pdf|doc|docx|xls|xlsx|zip|rar|7z|txt|csv)$/i.test(fileName);
    }

    const sent = await ctx.client.sendMessage(resolved.jid, media, opts);
    if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
    res.json({ idMessage: sent?.id?._serialized || null });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, jid: resolved.jid, urlFile }, 'sendFileByUrl failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
