'use strict';

const { mapAckToGreen } = require('../lib/StateMap');

module.exports = (ctx) => async (req, res) => {
  const minutes = parseInt(req.query?.minutes || req.body?.minutes || 1440, 10) || 1440;
  try {
    const items = await ctx.messageStore.query({ direction: 'outgoing', minutes });
    const out = items.map((m) => ({
      idMessage: m.idMessage,
      timestamp: m.timestamp,
      typeMessage: m.payload?.messageData?.typeMessage || m.type,
      chatId: m.chatId,
      textMessage: m.payload?.messageData?.textMessageData?.textMessage || '',
      statusMessage: m.lastAck != null ? mapAckToGreen(m.lastAck) : null,
      sendByApi: m.sendByApi ?? null,
    }));
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'lastOutgoingMessages failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
