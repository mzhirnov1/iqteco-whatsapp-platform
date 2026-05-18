'use strict';

module.exports = (ctx, archive = true) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || '').toString();
  if (!chatId) return res.status(400).json({ error: 'chatId required' });
  try {
    const chat = await ctx.client.getChatById(chatId);
    if (archive) await chat.archive();
    else await chat.unarchive();
    res.json({ chatId, archived: archive });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, archive }, 'archiveChat failed');
    res.status(500).json({ error: 'archive_failed', message: err.message });
  }
};
