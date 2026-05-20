'use strict';

const { S3Client } = require('@aws-sdk/client-s3');

function makeS3Client(s3Config) {
  if (!s3Config?.endpoint || !s3Config?.region || !s3Config?.accessKey || !s3Config?.secretKey) {
    throw new Error('S3 config incomplete: endpoint/region/accessKey/secretKey required');
  }
  return new S3Client({
    endpoint: s3Config.endpoint,
    region: s3Config.region,
    credentials: {
      accessKeyId: s3Config.accessKey,
      secretAccessKey: s3Config.secretKey,
    },
    forcePathStyle: true,
  });
}

module.exports = { makeS3Client };
