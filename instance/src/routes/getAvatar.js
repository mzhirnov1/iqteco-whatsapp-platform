'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || req.query?.chatId || '').toString();
  if (!chatId) return res.status(400).json({ error: 'chatId required' });
  try {
    const url = await ctx.client.getProfilePicUrl(chatId);
    res.json({ urlAvatar: url || '', reason: url ? '' : 'no_avatar', available: !!url });
  } catch (err) {
    res.json({ urlAvatar: '', reason: err.message, available: false });
  }
};
