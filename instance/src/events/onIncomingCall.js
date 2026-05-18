'use strict';

module.exports = (ctx) => async (call) => {
  try {
    const payload = ctx.mapper.toIncomingCall(call);
    await ctx.webhookSender.enqueue('incomingCall', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onIncomingCall: failed');
  }
};
