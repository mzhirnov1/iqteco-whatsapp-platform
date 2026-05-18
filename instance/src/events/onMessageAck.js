'use strict';

module.exports = (ctx) => async (msg, ack) => {
  if (!msg.fromMe) return;
  msg.ack = ack;
  try {
    const payload = ctx.mapper.toOutgoingMessageStatus(msg);
    await ctx.webhookSender.enqueue('outgoingMessageStatus', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onMessageAck: mapper failed');
  }
};
