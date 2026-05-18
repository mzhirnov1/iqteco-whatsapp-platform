'use strict';

const { mapWAStateToGreen } = require('../lib/StateMap');

module.exports = (ctx) => async (state) => {
  const green = mapWAStateToGreen(state);
  if (green === ctx.state.lastState) return;
  ctx.logger.info({ wa: state, green }, 'onChangeState');
  try {
    await ctx.adminClient.stateChange({ from: ctx.state.lastState, to: green, reason: `wa:${state}` });
  } catch (err) {
    ctx.logger.warn({ err: err.message }, 'onChangeState: admin notify failed');
  }
  ctx.state.lastState = green;
  await ctx.webhookSender.enqueue('stateInstanceChanged', ctx.mapper.toStateInstanceChanged(state));
};
