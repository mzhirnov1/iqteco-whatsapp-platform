'use strict';

module.exports = (ctx) => async (vote) => {
  try {
    // In wwebjs fork, Vote.parentMessage is a field (Message object), not an awaitable function.
    const msg = vote && typeof vote === 'object' ? (vote.parentMessage || null) : null;
    const payload = ctx.mapper.toPollMessage(vote, msg);
    await ctx.webhookSender.enqueue('pollUpdate', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onVoteUpdate: failed');
  }
};
