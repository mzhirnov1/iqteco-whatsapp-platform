'use strict';

const { mapWAStateToGreen } = require('../lib/StateMap');

module.exports = (ctx) => async (_req, res) => {
  const state = await ctx.client.getState().catch(() => null);
  res.json({ stateInstance: mapWAStateToGreen(state) });
};
