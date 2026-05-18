'use strict';

const { Location } = require('whatsapp-web.js');

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, latitude, longitude, nameLocation, address, quotedMessageId } = req.body || {};
  if (!chatId || latitude == null || longitude == null) {
    return res.status(400).json({ error: 'chatId, latitude, longitude required' });
  }
  try {
    const loc = new Location(Number(latitude), Number(longitude), {
      name: nameLocation || '',
      address: address || '',
    });
    const opts = quotedMessageId ? { quotedMessageId } : {};
    const sent = await ctx.client.sendMessage(chatId, loc, opts);
    if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
    res.json({ idMessage: sent?.id?._serialized || null });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'sendLocation failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
