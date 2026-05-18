'use strict';

module.exports = (ctx) => async (_req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  try {
    const contacts = await ctx.client.getContacts();
    const out = contacts
      .filter((c) => c.id && c.id._serialized && !c.isMe)
      .map((c) => ({
        id: c.id._serialized,
        name: c.name || c.pushname || '',
        type: c.isGroup ? 'group' : 'user',
        contactName: c.name || '',
      }));
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'getContacts failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
