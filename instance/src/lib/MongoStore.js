'use strict';

const fs = require('fs');
const path = require('path');
const { pipeline } = require('stream/promises');
const { GridFSBucket } = require('mongodb');

class MongoStore {
  constructor({ db, bucketName = 'wa_sessions', revisionsToKeep = 3, dataPath = './.wwebjs_auth/', idInstance = null }) {
    if (!db) throw new Error('MongoStore: db is required');
    this.db = db;
    this.bucket = new GridFSBucket(db, { bucketName });
    this.revisionsToKeep = revisionsToKeep;
    this.dataPath = path.resolve(dataPath);
    this.idInstance = idInstance;
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

    const toDelete = all.slice(this.revisionsToKeep);
    for (const file of toDelete) {
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
