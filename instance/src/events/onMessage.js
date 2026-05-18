'use strict';

module.exports = (ctx) => async (msg) => {
  if (msg.fromMe) return;
  try {
    const payload = ctx.mapper.toIncomingMessageReceived(msg);
    await ctx.webhookSender.enqueue('incomingMessageReceived', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message, id: msg.id?._serialized }, 'onMessage: mapper failed');
  }
};
