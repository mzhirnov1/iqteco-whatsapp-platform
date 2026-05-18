'use strict';

module.exports = (ctx) => async (msg) => {
  if (!msg.fromMe) return;
  try {
    const fromApi = ctx.outgoingApiIds.has(msg.id?._serialized);
    const payload = fromApi
      ? ctx.mapper.toOutgoingAPIMessageReceived(msg)
      : ctx.mapper.toOutgoingMessageReceived(msg);

    if (ctx.messageStore) {
      ctx.messageStore.put({
        idMessage: payload.idMessage,
        chatId: msg.to,
        direction: 'outgoing',
        type: payload.messageData?.typeMessage || msg.type,
        payload,
        timestamp: payload.timestamp,
        sendByApi: fromApi,
      }).catch(() => {});
    }

    await ctx.webhookSender.enqueue(payload.typeWebhook, payload);
    if (fromApi) ctx.outgoingApiIds.delete(msg.id._serialized);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onMessageCreate: mapper failed');
  }
};
