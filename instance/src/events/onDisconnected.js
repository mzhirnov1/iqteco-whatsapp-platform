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
};
