#!/usr/bin/env bash
# wasabi-init.sh — one-off bucket setup for media storage.
# Sets a 90-day expiration lifecycle rule on the media/ prefix.
# Usage:  S3_ACCESS_KEY=... S3_SECRET_KEY=... bash scripts/wasabi-init.sh
#
# Requires: awscli v2 (apt install awscli or pipx install awscli)

set -euo pipefail

: "${S3_ENDPOINT:=https://s3.eu-west-2.wasabisys.com}"
: "${S3_REGION:=eu-west-2}"
: "${S3_BUCKET:=wa.iqteco.com}"
: "${S3_KEY_PREFIX:=media/}"
: "${S3_ACCESS_KEY:?Set S3_ACCESS_KEY}"
: "${S3_SECRET_KEY:?Set S3_SECRET_KEY}"

export AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY"
export AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY"
export AWS_DEFAULT_REGION="$S3_REGION"

LIFECYCLE_JSON=$(cat <<EOF
{
  "Rules": [
    {
      "ID": "expire-media-90d",
      "Status": "Enabled",
      "Filter": { "Prefix": "${S3_KEY_PREFIX}" },
      "Expiration": { "Days": 90 },
      "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 1 }
    }
  ]
}
EOF
)

echo "Setting lifecycle rule on s3://${S3_BUCKET}/${S3_KEY_PREFIX} → expire after 90d"
aws s3api put-bucket-lifecycle-configuration \
  --endpoint-url "$S3_ENDPOINT" \
  --bucket "$S3_BUCKET" \
  --lifecycle-configuration "$LIFECYCLE_JSON"

echo "Verifying..."
aws s3api get-bucket-lifecycle-configuration \
  --endpoint-url "$S3_ENDPOINT" \
  --bucket "$S3_BUCKET"

echo "Done."
