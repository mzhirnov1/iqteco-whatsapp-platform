'use strict';

module.exports = (ctx) => async (msg) => {
  try {
    const payload = ctx.mapper.toEditedMessageReceived(msg);
    if (ctx.messageStore) {
      ctx.messageStore.put({
        idMessage: payload.idMessage,
        chatId: msg.fromMe ? msg.to : msg.from,
        direction: msg.fromMe ? 'outgoing' : 'incoming',
        type: payload.messageData?.typeMessage || msg.type,
        payload,
        timestamp: payload.timestamp,
      }).catch(() => {});
    }
    await ctx.webhookSender.enqueue('editedMessageReceived', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'onMessageEdit: failed');
  }
};
