'use strict';

// Универсальный обработчик группы. Принимает тип события и notification объект.
module.exports = (ctx, eventType) => async (notification) => {
  try {
    const payload = ctx.mapper.toGroupChange(eventType, {
      chatId: notification.chatId || notification.id?.remote || '',
      participants: notification.recipientIds || notification.author ? [notification.author] : [],
      author: notification.author || '',
      type: notification.type || '',
      body: notification.body || '',
    });
    await ctx.webhookSender.enqueue(eventType, payload);
  } catch (err) {
    ctx.logger.error({ err: err.message, eventType }, 'onGroupEvent: failed');
  }
};
