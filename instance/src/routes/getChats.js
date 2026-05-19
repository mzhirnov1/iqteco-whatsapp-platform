'use strict';

const { mapAckToGreen } = require('../lib/StateMap');

/**
 * Returns full list of chats (as WhatsApp Web sees it), including
 * personal chats, groups, archived. Filters out status broadcasts.
 *
 * Each item: { chatId, name, isGroup, lastMessage, lastTimestamp,
 *              unreadCount, lastStatus }
 */
module.exports = (ctx) => async (_req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  try {
    const chats = await ctx.client.getChats();
    const out = chats
      .filter((c) => c.id?._serialized && !c.id._serialized.endsWith('@broadcast'))
      .map((c) => {
        const last = c.lastMessage || null;
        const isGroup = !!c.isGroup;
        let lastText = '';
        if (last) {
          if (last.body) lastText = last.body;
          else if (last.type) lastText = '[' + last.type + ']';
        }
        return {
          chatId: c.id._serialized,
          name: c.name || c.formattedTitle || c.id._serialized,
          isGroup,
          archived: !!c.archived,
          unreadCount: c.unreadCount ?? 0,
          lastTimestamp: c.timestamp ?? last?.timestamp ?? null,
          lastMessage: lastText,
          lastFromMe: !!last?.fromMe,
          lastStatus: last?.fromMe ? mapAckToGreen(last.ack) : null,
        };
      });
    out.sort((a, b) => (b.lastTimestamp || 0) - (a.lastTimestamp || 0));
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'getChats failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
