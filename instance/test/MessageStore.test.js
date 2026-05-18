'use strict';

const MessageStore = require('../src/lib/MessageStore');

function fakeDb() {
  const docs = new Map();
  return {
    collection() {
      return {
        async createIndex() {},
        async updateOne(filter, update, opts) {
          const key = filter.idMessage;
          const cur = docs.get(key) || {};
          const next = { ...cur, ...filter, ...(update.$set || {}) };
          docs.set(key, next);
          return { upsertedCount: opts?.upsert ? 1 : 0 };
        },
        async findOne(filter) {
          return docs.get(filter.idMessage) || null;
        },
        find(filter, opts = {}) {
          let arr = [...docs.values()].filter((d) => {
            if (filter.idInstance && d.idInstance !== filter.idInstance) return false;
            if (filter.direction && d.direction !== filter.direction) return false;
            if (filter.chatId && d.chatId !== filter.chatId) return false;
            if (filter.timestamp?.$gte && (d.timestamp ?? 0) < filter.timestamp.$gte) return false;
            return true;
          });
          if (opts.sort) {
            const [[k, dir]] = Object.entries(opts.sort);
            arr.sort((a, b) => dir > 0 ? (a[k] - b[k]) : (b[k] - a[k]));
          }
          if (opts.limit) arr = arr.slice(0, opts.limit);
          return { toArray: async () => arr };
        },
      };
    },
  };
}

describe('MessageStore (in-memory + fake mongo)', () => {
  it('put + query by direction', async () => {
    const store = new MessageStore({ db: fakeDb(), idInstance: '1101', lruSize: 5 });

    const baseTs = Math.floor(Date.now() / 1000) - 60;
    for (let i = 0; i < 3; i++) {
      await store.put({
        idMessage: 'in_' + i, chatId: '7@c.us', direction: 'incoming',
        type: 'textMessage', payload: { msg: i }, timestamp: baseTs + i,
      });
    }
    await store.put({
      idMessage: 'out_0', chatId: '7@c.us', direction: 'outgoing',
      type: 'textMessage', payload: {}, timestamp: baseTs + 5,
    });

    const incoming = await store.query({ direction: 'incoming', minutes: 60 });
    expect(incoming.length).toBe(3);
    expect(incoming[0].idMessage).toBe('in_2'); // latest first

    const outgoing = await store.query({ direction: 'outgoing', minutes: 60 });
    expect(outgoing.length).toBe(1);
  });

  it('filters by chatId', async () => {
    const store = new MessageStore({ db: fakeDb(), idInstance: '1101' });
    const ts = Math.floor(Date.now() / 1000);
    await store.put({ idMessage: 'a', chatId: '1@c.us', direction: 'incoming', type: 't', payload: {}, timestamp: ts });
    await store.put({ idMessage: 'b', chatId: '2@c.us', direction: 'incoming', type: 't', payload: {}, timestamp: ts });

    const r = await store.query({ direction: 'incoming', minutes: 60, chatId: '1@c.us' });
    expect(r.length).toBe(1);
    expect(r[0].idMessage).toBe('a');
  });

  it('LRU evicts oldest', async () => {
    const store = new MessageStore({ db: fakeDb(), idInstance: '1101', lruSize: 2 });
    await store.put({ idMessage: 'a', chatId: '1', direction: 'incoming', type: 't', payload: {}, timestamp: 1 });
    await store.put({ idMessage: 'b', chatId: '1', direction: 'incoming', type: 't', payload: {}, timestamp: 2 });
    await store.put({ idMessage: 'c', chatId: '1', direction: 'incoming', type: 't', payload: {}, timestamp: 3 });
    expect(store._cache.has('a')).toBe(false);
    expect(store._cache.has('b')).toBe(true);
    expect(store._cache.has('c')).toBe(true);
  });
});
