'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const chatId = (req.body?.chatId || req.query?.chatId || '').toString();
  if (!chatId) return res.status(400).json({ error: 'chatId required' });
  try {
    const contact = await ctx.client.getContactById(chatId);
    const avatar = await contact.getProfilePicUrl().catch(() => null);
    res.json({
      avatar: avatar || '',
      name: contact.name || '',
      contactName: contact.name || '',
      email: '',
      category: '',
      description: '',
      products: [],
      chatId,
      lastSeen: null,
    });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId }, 'getContactInfo failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
