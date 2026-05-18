'use strict';

module.exports = (ctx) => async (msg) => {
  if (msg.fromMe) return;
  try {
    if (msg.hasMedia && ctx.mediaStore) {
      try {
        const media = await msg.downloadMedia();
        if (media?.data) {
          const buffer = Buffer.from(media.data, 'base64');
          if (buffer.length <= ctx.config.mediaMaxBytes) {
            await ctx.mediaStore.save({
              messageId: msg.id?._serialized,
              buffer,
              mimeType: media.mimetype,
              filename: media.filename || `${msg.id?.id || 'media'}.bin`,
              fromMe: false,
            });
          } else {
            ctx.logger.warn({ size: buffer.length, max: ctx.config.mediaMaxBytes }, 'onMessage: media too large, skip save');
          }
        }
      } catch (err) {
        ctx.logger.warn({ err: err.message, id: msg.id?._serialized }, 'onMessage: media download failed');
      }
    }

    const payload = ctx.mapper.toIncomingMessageReceived(msg);

    if (ctx.messageStore) {
      ctx.messageStore.put({
        idMessage: payload.idMessage,
        chatId: msg.from,
        direction: 'incoming',
        type: payload.messageData?.typeMessage || msg.type,
        payload,
        timestamp: payload.timestamp,
      }).catch(() => {});
    }

    await ctx.webhookSender.enqueue('incomingMessageReceived', payload);
  } catch (err) {
    ctx.logger.error({ err: err.message, id: msg.id?._serialized }, 'onMessage: mapper failed');
  }
};
