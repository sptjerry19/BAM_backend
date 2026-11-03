<?php

/**
 * Upload storage/app/public objects into the configured S3 bucket.
 * Keys stay relative (news/a.jpg), matching AWS_URL=/s3/{bucket}/<key>.
 */

use Aws\S3\S3Client;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$disk = config('filesystems.disks.s3');
$bucket = (string) ($disk['bucket'] ?? '');
if ($bucket === '' || ($disk['key'] ?? '') === '' || ($disk['secret'] ?? '') === '') {
    fwrite(STDERR, "S3 disk is not configured.\n");
    exit(1);
}

$client = new S3Client([
    'version' => 'latest',
    'region' => $disk['region'] ?: 'us-east-1',
    'endpoint' => $disk['endpoint'] ?: null,
    'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
    'credentials' => [
        'key' => $disk['key'],
        'secret' => $disk['secret'],
    ],
]);

if (! $client->doesBucketExist($bucket)) {
    $client->createBucket(['Bucket' => $bucket]);
    echo "created bucket {$bucket}\n";
}

$client->putBucketPolicy([
    'Bucket' => $bucket,
    'Policy' => json_encode([
        'Version' => '2012-10-17',
        'Statement' => [[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => ['*']],
            'Action' => ['s3:GetObject'],
            'Resource' => ["arn:aws:s3:::{$bucket}/*"],
        ]],
    ], JSON_UNESCAPED_SLASHES),
]);

$root = storage_path('app/public');
if (! is_dir($root)) {
    fwrite(STDERR, "Missing {$root}\n");
    exit(1);
}

$uploaded = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getFilename() === '.gitignore') {
        continue;
    }

    $key = ltrim(substr($file->getPathname(), strlen($root)), '/');
    $body = fopen($file->getPathname(), 'rb');
    if ($body === false) {
        fwrite(STDERR, "cannot read {$key}\n");
        exit(1);
    }

    $client->putObject([
        'Bucket' => $bucket,
        'Key' => $key,
        'Body' => $body,
        'ContentType' => mime_content_type($file->getPathname()) ?: 'application/octet-stream',
    ]);

    echo "uploaded {$key}\n";
    $uploaded++;
}

echo "done {$uploaded} object(s) -> {$bucket}\n";
