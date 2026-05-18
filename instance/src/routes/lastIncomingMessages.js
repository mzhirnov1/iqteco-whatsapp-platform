'use strict';

module.exports = (ctx) => async (req, res) => {
  const minutes = parseInt(req.query?.minutes || req.body?.minutes || 1440, 10) || 1440;
  try {
    const items = await ctx.messageStore.query({ direction: 'incoming', minutes });
    const out = items.map((m) => ({
      idMessage: m.idMessage,
      timestamp: m.timestamp,
      typeMessage: m.payload?.messageData?.typeMessage || m.type,
      chatId: m.chatId,
      senderId: m.payload?.senderData?.sender || m.chatId,
      senderName: m.payload?.senderData?.senderName || '',
      textMessage: m.payload?.messageData?.textMessageData?.textMessage || '',
    }));
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'lastIncomingMessages failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
