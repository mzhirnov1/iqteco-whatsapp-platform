'use strict';

const { mapAckToGreen } = require('../lib/StateMap');

/**
 * Returns full list of chats (as WhatsApp Web sees it), including
 * personal chats, groups, archived. Filters out status broadcasts.
 *
 * Each item: { chatId, name, isGroup, lastMessage, lastTimestamp,
 *              unreadCount, lastStatus }
 */

// Upstream client.getChats() is a single Promise.all over the serialization
// of EVERY chat model — one chat with a malformed model rejects the whole
// list (observed as a minified "Evaluation failed: r" on a heavy account
// with bulk sends to raw JIDs). The fallback below serializes per-chat with
// allSettled, skips the poisoned ones and logs their chatId + reason, so a
// single bad chat costs one list entry instead of the whole endpoint.
async function chatsPerChatFallback(ctx) {
  return ctx.client.pupPage.evaluate(async () => {
    const models = window.Store.Chat.getModelsArray();
    const settled = await Promise.allSettled(models.map((c) => window.WWebJS.getChatModel(c)));
    return {
      chats: settled.filter((s) => s.status === 'fulfilled').map((s) => s.value),
      failed: settled
        .map((s, i) => (s.status === 'rejected' ? {
          chatId: models[i]?.id?._serialized || null,
          reason: String((s.reason && (s.reason.stack || s.reason.message)) || s.reason).slice(0, 500),
        } : null))
        .filter(Boolean),
    };
  });
}

// Works for both shapes: a Chat instance (primary path) and the serialized
// chat model from the fallback — the fields read here exist on both.
function toItem(c) {
  const last = c.lastMessage || null;
  let lastText = '';
  if (last) {
    if (last.body) lastText = last.body;
    else if (last.type) lastText = '[' + last.type + ']';
  }
  return {
    chatId: c.id._serialized,
    name: c.name || c.formattedTitle || c.id._serialized,
    isGroup: !!c.isGroup,
    archived: !!c.archived,
    unreadCount: c.unreadCount ?? 0,
    lastTimestamp: c.timestamp ?? last?.timestamp ?? null,
    lastMessage: lastText,
    lastFromMe: !!last?.fromMe,
    lastStatus: last?.fromMe ? mapAckToGreen(last.ack) : null,
  };
}

module.exports = (ctx) => async (_req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  let chats;
  try {
    chats = await ctx.client.getChats();
  } catch (err) {
    ctx.logger.warn({ err: err.message, stack: err.stack }, 'getChats: client.getChats failed, per-chat fallback');
    try {
      const r = await chatsPerChatFallback(ctx);
      if (r.failed.length) {
        ctx.logger.warn(
          { failedCount: r.failed.length, failed: r.failed.slice(0, 5) },
          'getChats fallback: skipped poisoned chats',
        );
      }
      chats = r.chats;
    } catch (err2) {
      ctx.logger.error({ err: err2.message, stack: err2.stack }, 'getChats failed');
      return res.status(500).json({ error: 'fetch_failed', message: err2.message });
    }
  }
  try {
    const out = chats
      .filter((c) => c.id?._serialized && !c.id._serialized.endsWith('@broadcast'))
      .map(toItem);
    out.sort((a, b) => (b.lastTimestamp || 0) - (a.lastTimestamp || 0));
    res.json(out);
  } catch (err) {
    ctx.logger.error({ err: err.message, stack: err.stack }, 'getChats failed');
    res.status(500).json({ error: 'fetch_failed', message: err.message });
  }
};
