'use strict';

module.exports = (ctx) => async (req, res) => {
  const { messageId } = req.params;
  if (!messageId) {
    return res.status(400).json({ error: 'messageId required' });
  }

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
