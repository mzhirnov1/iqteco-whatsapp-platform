'use strict';

const { GridFSBucket, ObjectId } = require('mongodb');

class MediaStore {
  constructor({ db, idInstance, bucketName = 'wa_media', ttlDays = 7 }) {
    if (!db) throw new Error('MediaStore: db required');
    this.bucket = new GridFSBucket(db, { bucketName });
    this.filesColl = db.collection(`${bucketName}.files`);
    this.idInstance = String(idInstance);
    this.ttlDays = ttlDays;
  }

  async ensureTtl() {
    try {
      await this.filesColl.createIndex(
        { uploadDate: 1 },
        { expireAfterSeconds: this.ttlDays * 86400 }
      );
    } catch {
      // ignore on read-only or already exists
    }
  }

  /**
   * Saves buffer with metadata.
   * @returns {Promise<string>} stored file id (hex)
   */
  async save({ messageId, buffer, mimeType, filename, fromMe }) {
    const stream = this.bucket.openUploadStream(filename || messageId, {
      contentType: mimeType,
      metadata: {
        idInstance: this.idInstance,
        messageId,
        fromMe: !!fromMe,
      },
    });

    return await new Promise((resolve, reject) => {
      stream.once('error', reject);
      stream.once('finish', () => resolve(String(stream.id)));
      stream.end(buffer);
    });
  }

  /**
   * Opens read stream by messageId (latest match) or by file _id.
   */
  async openByMessageId(messageId) {
    const file = await this.filesColl.findOne(
      { 'metadata.messageId': messageId, 'metadata.idInstance': this.idInstance },
      { sort: { uploadDate: -1 } }
    );
    if (!file) return null;
    return {
      stream: this.bucket.openDownloadStream(file._id),
      contentType: file.contentType || 'application/octet-stream',
      filename: file.filename,
      length: file.length,
    };
  }

  async openById(id) {
    try {
      const _id = new ObjectId(id);
      const file = await this.filesColl.findOne({ _id });
      if (!file) return null;
      return {
        stream: this.bucket.openDownloadStream(_id),
        contentType: file.contentType || 'application/octet-stream',
        filename: file.filename,
        length: file.length,
      };
    } catch {
      return null;
    }
  }
}

module.exports = MediaStore;
