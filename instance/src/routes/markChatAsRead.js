'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId } = req.body || {};
  if (!chatId) return res.status(400).json({ error: 'chatId required' });
  try {
    const chat = await ctx.client.getChatById(chatId);
    await chat.sendSeen();
    res.json({ setUnreadMessages: true });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'markChatAsRead failed');
    res.status(500).json({ error: 'mark_failed', message: err.message });
  }
};
