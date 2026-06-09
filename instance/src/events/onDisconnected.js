'use strict';

module.exports = (ctx) => async (reason) => {
  ctx.logger.warn({ reason }, 'onDisconnected');
  ctx.state.authorized = false;
  try {
    await ctx.adminClient.stateChange({ from: ctx.state.lastState, to: 'notAuthorized', reason: String(reason) });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onDisconnected: admin notify failed');
  }
  ctx.state.lastState = 'notAuthorized';
  await ctx.webhookSender.enqueue('stateInstanceChanged', ctx.mapper.toStateInstanceChanged('UNPAIRED'));

  // Self-heal ONLY on a definitive unpair/logout (not transient navigation/network,
  // which whatsapp-web.js reconnects on its own).
  const r = String(reason || '').toUpperCase();
  if ((r.includes('LOGOUT') || r.includes('UNPAIRED')) && typeof ctx.resetSession === 'function') {
    await ctx.resetSession('disconnected:' + reason);
  }
};
