'use strict';

module.exports = (ctx) => async () => {
  ctx.state.authorized = true;
  if (ctx.qrWatch) ctx.qrWatch.streak = 0; // successful pairing -> reset QR-loop watchdog
  ctx.qrCache.qr = null;
  ctx.qrCache.pngBase64 = null;
  ctx.qrCache.expiresAt = 0;
  ctx.codeCache.code = null;
  ctx.codeCache.expiresAt = 0;

  // client.info often becomes available a moment after 'ready' — try a few times
  const captureWid = async () => {
    for (let i = 0; i < 5; i++) {
      const wid = ctx.client.info?.wid?._serialized;
      if (wid) {
        ctx.state.wid = wid;
        try {
          await ctx.adminClient._req('POST', `/instances/${ctx.config.idInstance}/heartbeat`, { state: 'authorized', wid });
        } catch { /* admin endpoint may ignore unknown fields */ }
        ctx.logger.info({ wid }, 'onReady: wid captured');
        return;
      }
      await new Promise(r => setTimeout(r, 800));
    }
    ctx.logger.warn('onReady: wid not available after 5 attempts');
  };
  captureWid().catch(() => {});

  try {
    await ctx.adminClient.stateChange({ from: ctx.state.lastState, to: 'authorized', reason: 'ready' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onReady: admin notify failed');
  }
  ctx.state.lastState = 'authorized';

  await ctx.webhookSender.enqueue('stateInstanceChanged',
    ctx.mapper.toStateInstanceChanged('CONNECTED'));

  ctx.logger.info('onReady: client authorized');
};
