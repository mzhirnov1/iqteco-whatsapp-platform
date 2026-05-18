'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || req.query?.chatId || '').toString();
  const count = Math.min(Math.max(parseInt(req.body?.count || req.query?.count || 100, 10) || 100, 1), 500);
  if (!chatId) return res.status(400).json({ error: 'chatId required' });

  try {
    const chat = await ctx.client.getChatById(chatId);
    const msgs = await chat.fetchMessages({ limit: count });
    const out = msgs.map((m) => {
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
        statusMessage: m.fromMe ? require('../lib/StateMap').mapAckToGreen(m.ack) : null,
      };
    }).reverse();
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId }, 'getChatHistory failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
