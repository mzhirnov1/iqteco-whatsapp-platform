'use strict';

/**
 * In-memory LRU + persistent storage of incoming/outgoing messages.
 * Used by Green API methods lastIncomingMessages, lastOutgoingMessages,
 * getChatHistory (fallback when fetchMessages not available).
 */
class MessageStore {
  constructor({ db, idInstance, lruSize = 1000, ttlDays = 7 }) {
    if (!db) throw new Error('MessageStore: db required');
    this.coll = db.collection('messages');
    this.idInstance = String(idInstance);
    this.lruSize = lruSize;
    this.ttlDays = ttlDays;
    this._cache = new Map(); // id → record
  }

  async ensureIndexes() {
    try {
      await this.coll.createIndex({ idInstance: 1, direction: 1, timestamp: -1 });
      await this.coll.createIndex({ idInstance: 1, chatId: 1, timestamp: -1 });
      await this.coll.createIndex({ idInstance: 1, idMessage: 1 }, { unique: true });
      await this.coll.createIndex(
        { savedAt: 1 },
        { expireAfterSeconds: this.ttlDays * 86400 }
      );
    } catch {
      // ignore
    }
  }

  /**
   * @param {object} record { idMessage, chatId, direction, type, payload, timestamp }
   */
  async put(record) {
    const doc = {
      idInstance: this.idInstance,
      idMessage: record.idMessage,
      chatId: record.chatId,
      direction: record.direction,
      type: record.type,
      payload: record.payload,
      timestamp: record.timestamp,
      savedAt: new Date(),
    };

    this._cache.delete(record.idMessage);
    this._cache.set(record.idMessage, doc);
    if (this._cache.size > this.lruSize) {
      const oldestKey = this._cache.keys().next().value;
      this._cache.delete(oldestKey);
    }

    try {
      await this.coll.updateOne(
        { idInstance: this.idInstance, idMessage: record.idMessage },
        { $set: doc },
        { upsert: true }
      );
    } catch (err) {
      // duplicate or write conflict — ignore, cache still works
    }
  }

  /**
   * @param {object} opts { direction, minutes?, chatId?, limit? }
   */
  async query({ direction, minutes = 1440, chatId = null, limit = 100 }) {
    const filter = { idInstance: this.idInstance };
    if (direction) filter.direction = direction;
    if (chatId) filter.chatId = chatId;
    if (minutes && minutes > 0) {
      const since = Math.floor(Date.now() / 1000) - minutes * 60;
      filter.timestamp = { $gte: since };
    }
    return await this.coll
      .find(filter, { sort: { timestamp: -1 }, limit })
      .toArray();
  }

  async byId(idMessage) {
    if (this._cache.has(idMessage)) return this._cache.get(idMessage);
    return await this.coll.findOne({ idInstance: this.idInstance, idMessage });
  }
}

module.exports = MessageStore;
