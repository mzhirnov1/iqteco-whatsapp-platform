'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, idMessage, message } = req.body || {};
  if (!chatId || !idMessage || typeof message !== 'string') {
    return res.status(400).json({ error: 'chatId, idMessage and message required' });
  }
  try {
    const chat = await ctx.client.getChatById(chatId);
    const msgs = await chat.fetchMessages({ limit: 50 });
    const msg = msgs.find((m) => m.id?._serialized === idMessage);
    if (!msg) return res.status(404).json({ error: 'message_not_found' });
    if (!msg.fromMe) return res.status(400).json({ error: 'can_only_edit_own_messages' });

    const edited = await msg.edit(message);
    res.json({ idMessage: edited?.id?._serialized || idMessage, edited: true });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'editMessage failed');
    res.status(500).json({ error: 'edit_failed', message: err.message });
  }
};
