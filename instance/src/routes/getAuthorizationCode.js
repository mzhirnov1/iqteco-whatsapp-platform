'use strict';

module.exports = (ctx) => async (req, res) => {
  const phoneNumber = (req.body?.phoneNumber || req.query?.phoneNumber || '').toString().replace(/[^\d]/g, '');
  if (!phoneNumber) {
    return res.status(400).json({ error: 'phoneNumber required' });
  }
  if (ctx.state.authorized) {
    return res.status(400).json({ error: 'instance_already_authorized' });
  }

  try {
    const code = await ctx.client.requestPairingCode(phoneNumber, true);
    return res.json({ status: true, code });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'getAuthorizationCode failed');
    res.status(500).json({ status: false, error: err.message });
  }
};
