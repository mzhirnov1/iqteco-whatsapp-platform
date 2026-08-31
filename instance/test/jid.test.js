'use strict';

const { resolveJid } = require('../src/lib/jid');

// Сессия, у которой Store сломан: любой запрос бросает. Это ровно то, что
// случилось 29.08.2026 — getChats падал, и ответ клиенту потерялся с
// вердиктом not_on_whatsapp, хотя чат был жив.
const brokenStore = {
  getNumberId: async () => { throw new Error('r'); },
  getChatById: async () => { throw new Error('r'); },
};

const healthy = {
  getNumberId: async (digits) => (digits === '79001234567' ? { _serialized: '79001234567@c.us' } : null),
  getChatById: async () => null,
};

describe('resolveJid', () => {
  it('passes group jids through', async () => {
    expect(await resolveJid(healthy, '120363@g.us')).toEqual({ ok: true, jid: '120363@g.us' });
  });

  it('passes lid jids through', async () => {
    expect(await resolveJid(healthy, '72594040590401@lid')).toEqual({ ok: true, jid: '72594040590401@lid' });
  });

  it('resolves a real phone number', async () => {
    expect(await resolveJid(healthy, '79001234567@c.us')).toEqual({ ok: true, jid: '79001234567@c.us' });
  });

  it('reports not_on_whatsapp when lookups answer and find nothing', async () => {
    expect(await resolveJid(healthy, '79009999999')).toEqual({ ok: false, reason: 'not_on_whatsapp' });
  });

  it('tries the lid jid instead of giving up when the session is broken', async () => {
    expect(await resolveJid(brokenStore, '72594040590401@c.us'))
      .toEqual({ ok: true, jid: '72594040590401@lid', uncertain: true });
  });

  it('rejects an empty chatId', async () => {
    expect(await resolveJid(healthy, '')).toEqual({ ok: false, reason: 'invalid_chatId' });
  });
});
