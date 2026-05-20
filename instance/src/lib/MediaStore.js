'use strict';

const {
  PutObjectCommand,
  GetObjectCommand,
  HeadBucketCommand,
} = require('@aws-sdk/client-s3');

class MediaStore {
  constructor({ s3, bucket, keyPrefix = 'media/', idInstance, logger }) {
    if (!s3) throw new Error('MediaStore: s3 client required');
    if (!bucket) throw new Error('MediaStore: bucket required');
    this.s3 = s3;
    this.bucket = bucket;
    this.keyPrefix = String(keyPrefix || '').replace(/\/?$/, '/');
    this.idInstance = String(idInstance);
    this.logger = logger || console;
  }

  _key(messageId) {
    return `${this.keyPrefix}${this.idInstance}/${messageId}`;
  }

  async checkReachable() {
    await this.s3.send(new HeadBucketCommand({ Bucket: this.bucket }));
  }

  async save({ messageId, buffer, mimeType, filename, fromMe }) {
    const cleanFilename = (filename || '').replace(/[\r\n"]/g, '');
    const metadata = {
      instance: this.idInstance,
      message: messageId,
      'from-me': fromMe ? '1' : '0',
    };
    if (cleanFilename) metadata.filename = cleanFilename;

    await this.s3.send(new PutObjectCommand({
      Bucket: this.bucket,
      Key: this._key(messageId),
      Body: buffer,
      ContentType: mimeType || 'application/octet-stream',
      ContentDisposition: cleanFilename ? `inline; filename="${cleanFilename}"` : undefined,
      Metadata: metadata,
    }));
    return this._key(messageId);
  }

  async openByMessageId(messageId) {
    try {
      const res = await this.s3.send(new GetObjectCommand({
        Bucket: this.bucket,
        Key: this._key(messageId),
      }));
      return {
        stream: res.Body,
        contentType: res.ContentType || 'application/octet-stream',
        filename: res.Metadata?.filename || '',
        length: res.ContentLength,
      };
    } catch (err) {
      if (err.name === 'NoSuchKey' || err.$metadata?.httpStatusCode === 404) return null;
      throw err;
    }
  }
}

module.exports = MediaStore;
