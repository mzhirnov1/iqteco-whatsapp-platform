'use strict';

const mimeTypes = require('mime-types');

function extFromMime(mime) {
  if (!mime) return null;
  const base = String(mime).split(';')[0].trim().toLowerCase();
  // whatsapp-web.js voice notes arrive as `audio/ogg; codecs=opus`; mime.extension('audio/ogg') → 'ogga'
  // which is technically valid but unfamiliar. Prefer common extensions for the few types we care about.
  if (base === 'audio/ogg') return 'ogg';
  if (base === 'audio/mp4' || base === 'audio/x-m4a') return 'm4a';
  if (base === 'audio/mpeg') return 'mp3';
  if (base === 'image/jpeg') return 'jpg';
  return mimeTypes.extension(base) || null;
}

module.exports = (ctx) => async (msg) => {
  if (msg.fromMe) return;
  try {
    if (msg.hasMedia && ctx.mediaStore) {
      try {
        const media = await msg.downloadMedia();
        if (media?.data) {
          const buffer = Buffer.from(media.data, 'base64');
          if (buffer.length <= ctx.config.mediaMaxBytes) {
            const ext = extFromMime(media.mimetype) || 'bin';
            const filename = media.filename || `${msg.id?.id || 'media'}.${ext}`;
            // Stash on the message so the mapper picks up the synthesized name + mime
            // for the webhook payload (handler.php uses fileName for the file URL extension).
            try {
              msg._data = msg._data || {};
              if (!msg._data.filename) msg._data.filename = filename;
              if (!msg._data.mimetype) msg._data.mimetype = media.mimetype;
            } catch { /* ignore */ }
            await ctx.mediaStore.save({
              messageId: msg.id?._serialized,
              buffer,
              mimeType: media.mimetype,
              filename,
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
