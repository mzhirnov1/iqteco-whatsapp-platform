'use strict';

// Resolve WhatsApp LID privacy identifiers to real phone numbers (and back).
// WhatsApp surfaces some chats as `{lid}@lid` with no phone in any payload;
// the underlying WA Web session CAN map lid → pn (the phone app shows the
// number). Exposes wweb.js Client.getContactLidAndPhone() so the CRM can
// backfill phones on lid-keyed leads.
//
// POST { userIds: ["263470608056392@lid", ...] }  (or comma-separated string)
// → [{ lid: "...@lid", pn: "79138592154@c.us" }, ...]
module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  let userIds = req.body?.userIds ?? req.query?.userIds ?? req.body?.userId;
  if (typeof userIds === 'string') {
    userIds = userIds.split(',').map((s) => s.trim()).filter(Boolean);
  }
  if (!Array.isArray(userIds) || userIds.length === 0) {
    return res.status(400).json({ error: 'userIds required' });
  }
  if (userIds.length > 50) {
    return res.status(400).json({ error: 'too many userIds (max 50)' });
  }

  try {
    const result = await ctx.client.getContactLidAndPhone(userIds);
    res.json(result);
  } catch (err) {
    ctx.logger.error({ err: err.message }, 'getContactLidAndPhone failed');
    res.status(500).json({ error: 'resolve_failed', message: err.message });
  }
};
