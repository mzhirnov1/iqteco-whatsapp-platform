'use strict';

module.exports = (ctx) => async (_req, res) => {
  try {
    await ctx.client.logout();
    ctx.state.authorized = false;
    ctx.state.lastState = 'notAuthorized';
    res.json({ isLogout: true });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'logout failed');
    res.status(500).json({ isLogout: false, error: err.message });
  }
};
