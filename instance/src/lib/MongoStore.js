'use strict';

const fs = require('fs');
const path = require('path');
const { pipeline } = require('stream/promises');
const { GridFSBucket } = require('mongodb');

class MongoStore {
  constructor({ db, bucketName = 'wa_sessions', revisionsToKeep = 3, dailyKeepDays = 3, dataPath = './.wwebjs_auth/', idInstance = null, minSaveBytes = 65536, logger = null }) {
    if (!db) throw new Error('MongoStore: db is required');
    this.db = db;
    this.bucket = new GridFSBucket(db, { bucketName });
    this.revisionsToKeep = revisionsToKeep;
    this.dailyKeepDays = dailyKeepDays;
    this.dataPath = path.resolve(dataPath);
    this.idInstance = idInstance;
    // A real paired profile zips to tens of megabytes. Anything near-empty is a
    // backup taken against a profile that holds no session at all (2026-08:
    // 1428-byte blobs written every minute by a leaked backupSync). Storing one
    // is worse than storing nothing — sessionExists() then reports a session we
    // cannot restore, which is what onQR's dead-session watchdog acts on.
    this.minSaveBytes = Number(minSaveBytes) || 0;
    this.logger = logger;
  }

  _filename(session) {
    return `${session}.zip`;
  }

  async sessionExists({ session }) {
    const cursor = this.bucket.find({ filename: this._filename(session) }, { limit: 1 });
    return await cursor.hasNext();
  }

  async save({ session }) {
    const filename = this._filename(session);
    const zipPath = path.join(this.dataPath, filename);

    if (!fs.existsSync(zipPath)) {
      throw new Error(`MongoStore.save: zip file not found at ${zipPath}`);
    }

    const stat = await fs.promises.stat(zipPath);

    if (this.minSaveBytes && stat.size < this.minSaveBytes) {
      this.logger?.warn?.(
        { session, size: stat.size, minSaveBytes: this.minSaveBytes },
        'MongoStore.save: refusing to store an empty-looking session',
      );
      return;
    }

    await pipeline(
      fs.createReadStream(zipPath),
      this.bucket.openUploadStream(filename, {
        metadata: {
          session,
          idInstance: this.idInstance,
          size: stat.size,
          savedAt: new Date(),
        },
      }),
    );

    await this._pruneRevisions(filename);
  }

  async _pruneRevisions(filename) {
    const all = await this.bucket
      .find({ filename })
      .sort({ uploadDate: -1 })
      .toArray();

    const keep = new Set(all.slice(0, this.revisionsToKeep).map((f) => String(f._id)));

    // Tiered retention: besides the N rolling revisions, keep the newest
    // revision of each of the last dailyKeepDays calendar days. Backups run
    // every 60s and keep running while the page is sick, so the rolling
    // revisions are all post-corruption within minutes — the daily tier is
    // what preserves a restorable pre-incident session (2026-08 incident:
    // every stored revision was from the sick period, forcing a QR re-link).
    const byDay = new Map();
    for (const f of all) {
      const day = f.uploadDate.toISOString().slice(0, 10);
      if (!byDay.has(day)) byDay.set(day, f); // list is newest-first
    }
    [...byDay.keys()].sort().reverse().slice(0, this.dailyKeepDays)
      .forEach((day) => keep.add(String(byDay.get(day)._id)));

    for (const file of all) {
      if (keep.has(String(file._id))) continue;
      try {
        await this.bucket.delete(file._id);
      } catch {
        // ignore — concurrent prune
      }
    }
  }

  async extract({ session, path: outPath }) {
    const filename = this._filename(session);
    const [file] = await this.bucket
      .find({ filename })
      .sort({ uploadDate: -1 })
      .limit(1)
      .toArray();

    if (!file) {
      throw new Error(`MongoStore.extract: session ${session} not found`);
    }

    await fs.promises.mkdir(path.dirname(outPath), { recursive: true });

    await pipeline(
      this.bucket.openDownloadStream(file._id),
      fs.createWriteStream(outPath),
    );
  }

  async delete({ session }) {
    const filename = this._filename(session);
    const files = await this.bucket.find({ filename }).toArray();
    for (const file of files) {
      try {
        await this.bucket.delete(file._id);
      } catch {
        // ignore — concurrent
      }
    }
  }
}

module.exports = MongoStore;
