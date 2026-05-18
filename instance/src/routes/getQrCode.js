'use strict';

module.exports = (ctx) => async (_req, res) => {
  if (ctx.state.authorized) {
    return res.json({ type: 'alreadyLogged' });
  }
  if (ctx.qrCache.pngBase64) {
    return res.json({ type: 'qrCode', message: ctx.qrCache.pngBase64 });
  }
  res.json({ type: 'starting' });
};
