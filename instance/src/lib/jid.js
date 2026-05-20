'use strict';

/**
 * Normalize whatever chatId Green-API clients send into a valid wweb.js jid.
 *
 * Bitrix24 / Green-API consumers pass:
 *   - 7999...@c.us           (already a personal jid)
 *   - 120363...@g.us         (group jid)
 *   - 216359631876165        (bare digits, no suffix)
 *   - +90 (216) 359-18-76    (formatted phone)
 *
 * wweb.js fails with "No LID for user" if you pass a raw digits jid for a
 * number that hasn't been seen with a Linked Identity yet — we have to ask
 * the client for the actual serialized id via getNumberId().
 *
 * Returns { ok: true, jid }  on success
 *         { ok: false, reason }  if number is not on WhatsApp or invalid
 */
async function resolveJid(client, chatId) {
  if (!chatId || typeof chatId !== 'string') {
    return { ok: false, reason: 'invalid_chatId' };
  }
  const trimmed = chatId.trim();

  // Group jid — pass through
  if (trimmed.endsWith('@g.us')) {
    return { ok: true, jid: trimmed };
  }
  // Already personal jid — still verify via getNumberId so we get the
  // resolved LID-aware serialized id wweb.js requires.
  const digits = trimmed.replace(/@c\.us$/, '').replace(/[^\d]/g, '');
  if (!digits) {
    return { ok: false, reason: 'invalid_chatId' };
  }

  try {
    const wid = await client.getNumberId(digits);
    if (!wid) return { ok: false, reason: 'not_on_whatsapp' };
    return { ok: true, jid: wid._serialized };
  } catch (err) {
    return { ok: false, reason: 'lookup_failed', message: err.message };
  }
}

module.exports = { resolveJid };
