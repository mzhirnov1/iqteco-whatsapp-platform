'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, chatIdFrom, messages } = req.body || {};
  if (!chatId || !chatIdFrom || !Array.isArray(messages) || messages.length === 0) {
    return res.status(400).json({ error: 'chatId, chatIdFrom, messages[] required' });
  }

  const results = [];
  for (const idMessage of messages) {
    try {
      const chat = await ctx.client.getChatById(chatIdFrom);
      const fetched = await chat.fetchMessages({ limit: 50 });
      const msg = fetched.find((m) => m.id?._serialized === idMessage);
      if (!msg) {
        results.push({ idMessage, error: 'not_found' });
        continue;
      }
      const forwarded = await msg.forward(chatId);
      results.push({ idMessage, forwardedId: forwarded?.id?._serialized || null });
    } catch (err) {
      results.push({ idMessage, error: err.message });
    }
  }
  res.json({ forwarded: results });
};
