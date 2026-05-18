'use strict';

module.exports = (ctx) => async (req, res) => {
  const body = req.body || {};
  // Update in-memory adminConfig; persist via admin (it owns the data).
  if (body.webhookUrl !== undefined) ctx.adminConfig.webhookUrl = String(body.webhookUrl);

  ctx.adminConfig.settings = { ...(ctx.adminConfig.settings || {}), ...body };

  // Note: admin should be the source of truth — we POST back so it picks up changes.
  try {
    await ctx.adminClient._req('POST', `/instances/${ctx.config.idInstance}/settings`, body)
      .catch(() => null);
  } catch {
    // best-effort
  }

  res.json({ saveSettings: true });
};
