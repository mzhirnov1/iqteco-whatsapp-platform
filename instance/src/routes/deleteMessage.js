'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, idMessage, onlyForMe } = req.body || {};
  if (!chatId || !idMessage) {
    return res.status(400).json({ error: 'chatId and idMessage required' });
  }
  try {
    const chat = await ctx.client.getChatById(chatId);
    const msgs = await chat.fetchMessages({ limit: 50 });
    const msg = msgs.find((m) => m.id?._serialized === idMessage);
    if (!msg) return res.status(404).json({ error: 'message_not_found' });

    // msg.delete(everyone=true) → revoke for everyone
    await msg.delete(!onlyForMe);
    res.json({ idMessage, deleted: true });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'deleteMessage failed');
    res.status(500).json({ error: 'delete_failed', message: err.message });
  }
};
