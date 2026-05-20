'use strict';

module.exports = (ctx) => async (req, res) => {
  let { messageId } = req.params;
  if (!messageId) {
    return res.status(400).json({ error: 'messageId required' });
  }
  // URL may carry an extension hint (e.g. ".mp4", ".ogg") so Bitrix24 and
  // similar consumers can detect media type from URL path. Strip it before
  // resolving the actual S3 object key.
  messageId = messageId.replace(/\.[a-z0-9]{1,5}$/i, '');

  const entry = await ctx.mediaStore.openByMessageId(messageId);
  if (!entry) {
    return res.status(404).json({ error: 'media_not_found' });
  }

  res.setHeader('Content-Type', entry.contentType);
  if (entry.filename) {
    res.setHeader('Content-Disposition', `inline; filename="${encodeURIComponent(entry.filename)}"`);
  }
  if (entry.length) {
    res.setHeader('Content-Length', entry.length);
  }
  res.setHeader('Cache-Control', 'private, max-age=3600');

  entry.stream.on('error', (err) => {
    ctx.logger.error({ err: err.message, messageId }, 'media stream error');
    if (!res.headersSent) res.status(500).end();
    else res.end();
  });
  entry.stream.pipe(res);
};
