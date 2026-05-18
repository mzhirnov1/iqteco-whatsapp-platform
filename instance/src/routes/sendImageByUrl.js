'use strict';

const { MessageMedia } = require('whatsapp-web.js');

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, urlFile, fileName, caption, quotedMessageId } = req.body || {};
  if (!chatId || !urlFile) {
    return res.status(400).json({ error: 'chatId and urlFile required' });
  }

  try {
    const media = await MessageMedia.fromUrl(urlFile, { unsafeMime: true, filename: fileName });
    const opts = { sendMediaAsDocument: false };
    if (caption) opts.caption = caption;
    if (quotedMessageId) opts.quotedMessageId = quotedMessageId;

    const sent = await ctx.client.sendMessage(chatId, media, opts);
    if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
    res.json({ idMessage: sent?.id?._serialized || null });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, urlFile }, 'sendImageByUrl failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
