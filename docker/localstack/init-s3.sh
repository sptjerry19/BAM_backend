#!/bin/sh
# Create the local S3 bucket + public-read policy once LocalStack is up.
set -e

BUCKET="${AWS_BUCKET:-jbam-local}"
ENDPOINT="${AWS_ENDPOINT:-http://localstack:4566}"

export AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID:-test}"
export AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY:-test}"
export AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-ap-southeast-2}"

echo "[localstack-init] waiting for LocalStack at ${ENDPOINT}..."
i=0
until aws --endpoint-url="$ENDPOINT" s3 ls >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "[localstack-init] LocalStack not ready after 60s" >&2
    exit 1
  fi
  sleep 1
done

echo "[localstack-init] ensuring bucket s3://${BUCKET}"
aws --endpoint-url="$ENDPOINT" s3 mb "s3://${BUCKET}" 2>/dev/null || true

aws --endpoint-url="$ENDPOINT" s3api put-bucket-cors --bucket "$BUCKET" --cors-configuration '{
  "CORSRules": [{
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["GET", "PUT", "POST", "HEAD", "DELETE"],
    "AllowedOrigins": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }]
}'

aws --endpoint-url="$ENDPOINT" s3api put-bucket-policy --bucket "$BUCKET" --policy "{
  \"Version\": \"2012-10-17\",
  \"Statement\": [{
    \"Sid\": \"PublicReadGetObject\",
    \"Effect\": \"Allow\",
    \"Principal\": \"*\",
    \"Action\": \"s3:GetObject\",
    \"Resource\": \"arn:aws:s3:::${BUCKET}/*\"
  }]
}"

echo "[localstack-init] bucket ${BUCKET} ready"
