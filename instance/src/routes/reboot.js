'use strict';

module.exports = (ctx) => async (_req, res) => {
  setImmediate(async () => {
    try {
      await ctx.rebootClient('api_request');
    } catch (err) {
      ctx.logger.error({ err: err.message }, 'reboot: rebootClient failed');
    }
  });
  res.json({ isReboot: true });
};
