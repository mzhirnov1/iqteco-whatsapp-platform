'use strict';

const { downloadMessageMedia } = require('../lib/mediaDownload');
const { mimeToExt } = require('../lib/mime');

// POST /waInstance{id}/downloadFile/{token}  body: { chatId, idMessage }
//   → { downloadUrl, mimeType, fileName, size, cached }  (Green API compatible)
//
// On-demand media: history import on the CRM side gets messages without files
// (getChatHistory only lists them), and any consumer whose earlier download
// 404-ed can ask again. The file is fetched from the page with the tolerant
// downloader, stored in the MediaStore under the bare message id — the same
// key the webhook's downloadUrl points at — and served by the /media route.
// `?debug=1` adds the per-step trace.
module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const idMessage = String(req.body?.idMessage || req.query?.idMessage || '').trim();
  const chatId = String(req.body?.chatId || req.query?.chatId || '').trim();
  if (!idMessage) return res.status(400).json({ error: 'idMessage required' });
  if (!ctx.mediaStore) return res.status(503).json({ error: 'media_store_unavailable' });

  const bare = idMessage.includes('_') ? idMessage.split('_').slice(2).join('_') : idMessage;
  const debug = String(req.query?.debug || req.body?.debug || '') === '1';

  try {
    const existing = await ctx.mediaStore.openByMessageId(bare);
    if (existing) {
      try { existing.stream.destroy(); } catch { /* ignore */ }
      const ext = mimeToExt(existing.contentType) || '';
      return res.json({
        downloadUrl: ctx.mapper._downloadUrl(bare, ext),
        mimeType: existing.contentType,
        fileName: existing.filename || '',
        size: existing.length || null,
        cached: true,
      });
    }
  } catch (err) {
    ctx.logger.warn({ err: err.message, idMessage }, 'downloadFile: store lookup failed');
  }

  let r;
  try {
    r = await downloadMessageMedia(ctx, { msgId: idMessage, chatId });
  } catch (err) {
    ctx.logger.warn({ err: err.message, idMessage, chatId }, 'downloadFile: evaluate failed');
    return res.status(500).json({ error: 'download_failed', message: err.message });
  }
  if (!r || r.error || !r.data) {
    ctx.logger.warn({ idMessage, chatId, error: r && r.error, steps: r && r.steps }, 'downloadFile: no media');
    const status = r && r.error === 'message_not_found' ? 404 : 422;
    return res.status(status).json(Object.assign({ error: (r && r.error) || 'no_media' }, debug ? { steps: r && r.steps } : {}));
  }

  const buffer = Buffer.from(r.data, 'base64');
  if (buffer.length > ctx.config.mediaMaxBytes) {
    return res.status(413).json({ error: 'media_too_large', size: buffer.length, max: ctx.config.mediaMaxBytes });
  }
  const ext = mimeToExt(r.mimetype) || 'bin';
  const filename = r.filename || `${bare}.${ext}`;
  await ctx.mediaStore.save({ messageId: bare, buffer, mimeType: r.mimetype, filename, fromMe: false });
  ctx.logger.info({ idMessage: bare, bytes: buffer.length, mime: r.mimetype }, 'downloadFile: stored on demand');

  res.json(Object.assign({
    downloadUrl: ctx.mapper._downloadUrl(bare, ext),
    mimeType: r.mimetype,
    fileName: filename,
    size: buffer.length,
    cached: false,
  }, debug ? { steps: r.steps } : {}));
};
