'use strict';

const { resolveJid } = require('../lib/jid');

module.exports = (ctx) => async (req, res) => {
  if (!ctx.state.authorized) {
    return res.status(466).json({ error: 'instanceNotAuthorized' });
  }
  const { chatId, contactsArray, contact, quotedMessageId } = req.body || {};
  if (!chatId) return res.status(400).json({ error: 'chatId required' });

  const contacts = Array.isArray(contactsArray) ? contactsArray : (contact ? [contact] : []);
  if (contacts.length === 0) return res.status(400).json({ error: 'contactsArray or contact required' });

  const resolved = await resolveJid(ctx.client, chatId);
  if (!resolved.ok) {
    ctx.logger.warn({ chatId, reason: resolved.reason }, 'sendContact skipped (jid)');
    return res.json({ idMessage: null, error: resolved.reason });
  }

  try {
    const wwebContacts = [];
    for (const c of contacts) {
      const phone = (c.phoneContact || c.phone || '').toString().replace(/[^\d]/g, '');
      if (!phone) continue;
      const wid = `${phone}@c.us`;
      const wwebContact = await ctx.client.getContactById(wid).catch(() => null);
      if (wwebContact) wwebContacts.push(wwebContact);
    }
    if (wwebContacts.length === 0) {
      return res.status(400).json({ error: 'no valid contacts' });
    }
    const opts = quotedMessageId ? { quotedMessageId } : {};
    const sent = await ctx.client.sendMessage(resolved.jid, wwebContacts.length === 1 ? wwebContacts[0] : wwebContacts, opts);
    if (sent?.id?._serialized) ctx.outgoingApiIds.add(sent.id._serialized);
    res.json({ idMessage: sent?.id?._serialized || null });
  } catch (err) {
    ctx.logger.error({ err: err.message, chatId, jid: resolved.jid }, 'sendContact failed');
    res.status(500).json({ error: 'send_failed', message: err.message });
  }
};
