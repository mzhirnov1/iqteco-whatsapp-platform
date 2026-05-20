'use strict';

const multer = require('multer');
const mime = require('mime-types');
const { MessageMedia } = require('whatsapp-web.js');
const { resolveJid } = require('../lib/jid');

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 100 * 1024 * 1024 }, // 100 MB
});

function handler(ctx) {
  return async (req, res) => {
    if (!ctx.state.authorized) {
      return res.status(466).json({ error: 'instanceNotAuthorized' });
    }
    const file = req.file;
    if (!file) return res.status(400).json({ error: 'file required (multipart field "file")' });

    const chatId = (req.body?.chatId || '').toString();
    const caption = (req.body?.caption || '').toString();
    const fileName = file.originalname || (req.body?.fileName || '').toString();
    if (!chatId) return res.status(400).json({ error: 'chatId required' });

    const resolved = await resolveJid(ctx.client, chatId);
    if (!resolved.ok) {
      ctx.logger.warn({ chatId, reason: resolved.reason }, 'sendFileByUpload skipped (jid)');
      return res.json({ idMessage: null, error: resolved.reason });
    }

    try {
      const mimeType = file.mimetype || mime.lookup(fileName) || 'application/octet-stream';
      const media = new MessageMedia(mimeType, file.buffer.toString('base64'), fileName);
      const opts = caption ? { caption } : {};
      if (/\.(pdf|doc|docx|xls|xlsx|zip|rar|7z|txt|csv|pptx)$/i.test(fileName)) {
        opts.sendMediaAsDocument = true;
      }
      const sent = await ctx.client.sendMessage(resolved.jid, media, opts);
      if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
      res.json({ idMessage: sent?.id?._serialized || null });
    } catch (err) {
      ctx.logger.error({ err: err.message, chatId, jid: resolved.jid, fileName }, 'sendFileByUpload failed');
      res.status(500).json({ error: 'send_failed', message: err.message });
    }
  };
}

module.exports = { handler, middleware: upload.single('file') };
