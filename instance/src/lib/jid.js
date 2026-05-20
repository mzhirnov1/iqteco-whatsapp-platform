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
  // LID jid — pass through (newer WhatsApp Linked Identity). wweb.js
  // sendMessage accepts @lid directly; getNumberId can't resolve these.
  if (trimmed.endsWith('@lid')) {
    return { ok: true, jid: trimmed };
  }

  const digits = trimmed.replace(/@c\.us$/, '').replace(/[^\d]/g, '');
  if (!digits) {
    return { ok: false, reason: 'invalid_chatId' };
  }

  // Try phone-number resolution first (returns proper c.us / lid serialized).
  try {
    const wid = await client.getNumberId(digits);
    if (wid) return { ok: true, jid: wid._serialized };
  } catch (err) {
    // fall through to LID fallback
  }

  // Fallback: if Bitrix24 stored a LID as the connector user.id (because
  // our incoming webhook surfaced it as @c.us), retry as @lid. wweb.js
  // can route messages straight to LID jids when the chat exists.
  const lidJid = digits + '@lid';
  try {
    const chat = await client.getChatById(lidJid).catch(() => null);
    if (chat) return { ok: true, jid: lidJid };
  } catch (err) { /* ignore */ }

  return { ok: false, reason: 'not_on_whatsapp' };
}

module.exports = { resolveJid };
