'use strict';

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const phone = (req.body?.phoneNumber || req.query?.phoneNumber || '').toString().replace(/[^\d]/g, '');
  if (!phone) return res.status(400).json({ error: 'phoneNumber required' });
  try {
    const exists = await ctx.client.isRegisteredUser(`${phone}@c.us`);
    res.json({ existsWhatsapp: !!exists });
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'checkWhatsapp failed');
    res.status(500).json({ existsWhatsapp: false, error: err.message });
  }
};
