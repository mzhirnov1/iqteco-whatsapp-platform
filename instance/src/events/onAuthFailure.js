'use strict';

module.exports = (ctx) => async (msg) => {
  ctx.logger.error({ reason: msg }, 'onAuthFailure');
  ctx.state.authorized = false;
  try {
    await ctx.adminClient.stateChange({ from: ctx.state.lastState, to: 'notAuthorized', reason: 'auth_failure' });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onAuthFailure: admin notify failed');
  }
  ctx.state.lastState = 'notAuthorized';
  await ctx.webhookSender.enqueue('stateInstanceChanged', ctx.mapper.toStateInstanceChanged('UNPAIRED'));

  // auth_failure means the stored session is invalid -> clear it and bring up a fresh QR.
  if (typeof ctx.resetSession === 'function') {
    await ctx.resetSession('auth_failure');
  }
};
