'use strict';

module.exports = (ctx) => async (msg) => {
  if (!msg.fromMe) return;
  try {
    const fromApi = ctx.outgoingApiIds.has(msg.id?._serialized);
    const payload = fromApi
      ? ctx.mapper.toOutgoingAPIMessageReceived(msg)
      : ctx.mapper.toOutgoingMessageReceived(msg);
    await ctx.webhookSender.enqueue(payload.typeWebhook, payload);
    if (fromApi) ctx.outgoingApiIds.delete(msg.id._serialized);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onMessageCreate: mapper failed');
  }
};
