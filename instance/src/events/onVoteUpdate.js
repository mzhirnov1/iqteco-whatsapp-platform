'use strict';

module.exports = (ctx) => async (vote) => {
  try {
    const msg = await vote.parentMessage?.().catch(() => null);
    const payload = ctx.mapper.toPollMessage(vote, msg);
    await ctx.webhookSender.enqueue('pollUpdate', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onVoteUpdate: failed');
  }
};
