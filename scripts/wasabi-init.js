#!/usr/bin/env node
'use strict';

/**
 * wasabi-init.js — one-off bucket setup for media storage.
 * Sets a 90-day expiration lifecycle rule on the media/ prefix.
 *
 * Usage (run inside the container or where @aws-sdk/client-s3 is available):
 *   S3_ENDPOINT=https://s3.eu-west-2.wasabisys.com \
 *   S3_REGION=eu-west-2 S3_BUCKET=wa.iqteco.com \
 *   S3_ACCESS_KEY=... S3_SECRET_KEY=... \
 *   node scripts/wasabi-init.js
 */

const {
  S3Client,
  PutBucketLifecycleConfigurationCommand,
  GetBucketLifecycleConfigurationCommand,
  HeadBucketCommand,
} = require('@aws-sdk/client-s3');

function req(name) {
  const v = process.env[name];
  if (!v) { console.error(`Missing env: ${name}`); process.exit(1); }
  return v;
}

const endpoint = process.env.S3_ENDPOINT || 'https://s3.eu-west-2.wasabisys.com';
const region   = process.env.S3_REGION   || 'eu-west-2';
const bucket   = process.env.S3_BUCKET   || 'wa.iqteco.com';
const prefix   = process.env.S3_KEY_PREFIX || 'media/';
const accessKeyId     = req('S3_ACCESS_KEY');
const secretAccessKey = req('S3_SECRET_KEY');

(async () => {
  const s3 = new S3Client({
    endpoint, region,
    credentials: { accessKeyId, secretAccessKey },
    forcePathStyle: true,
  });

  console.log(`HeadBucket s3://${bucket}`);
  await s3.send(new HeadBucketCommand({ Bucket: bucket }));
  console.log('  → reachable');

  console.log(`PutBucketLifecycleConfiguration: ${prefix} expire after 90d`);
  await s3.send(new PutBucketLifecycleConfigurationCommand({
    Bucket: bucket,
    LifecycleConfiguration: {
      Rules: [
        {
          ID: 'expire-media-90d',
          Status: 'Enabled',
          Filter: { Prefix: prefix },
          Expiration: { Days: 90 },
          AbortIncompleteMultipartUpload: { DaysAfterInitiation: 1 },
        },
      ],
    },
  }));
  console.log('  → applied');

  console.log('Verifying...');
  const cur = await s3.send(new GetBucketLifecycleConfigurationCommand({ Bucket: bucket }));
  console.log(JSON.stringify(cur.Rules, null, 2));
  console.log('Done.');
})().catch((err) => {
  console.error('FATAL:', err.message);
  process.exit(2);
});
