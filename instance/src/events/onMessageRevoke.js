'use strict';

module.exports = (ctx) => async (msg, revokedMsg) => {
  try {
    const payload = ctx.mapper.toDeletedMessageReceived(msg, revokedMsg);
    await ctx.webhookSender.enqueue('deletedMessageReceived', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onMessageRevoke: failed');
  }
};
