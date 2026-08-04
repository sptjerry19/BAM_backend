<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FileUploadService
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/jpg',
    ];

    private const MAX_SIZE_KB = 5120; // 5MB

    public function disk(?string $disk = null): string
    {
        $diskName = $disk ?: (string) config('filesystems.default', 's3');

        if ($diskName === 's3' && ! $this->hasS3Credentials()) {
            if (app()->environment('local', 'testing')) {
                // Local/dev without AWS keys → store on public disk so uploads still work.
                return 'public';
            }

            throw new RuntimeException(__('messages.upload_s3_credentials_missing'));
        }

        return $diskName;
    }

    public function hasS3Credentials(): bool
    {
        $key = (string) config('filesystems.disks.s3.key');
        $secret = (string) config('filesystems.disks.s3.secret');
        $bucket = (string) config('filesystems.disks.s3.bucket');

        return $key !== '' && $secret !== '' && $bucket !== '';
    }

    /**
     * Upload a single file to storage (default: S3).
     *
     * @return array{path: string, url: string, disk: string, original_name: string, mime: string|null, size: int}
     */
    public function upload(
        UploadedFile $file,
        string $folder = 'uploads',
        ?string $disk = null,
        bool $public = false
    ): array {
        $this->assertValidImage($file);

        $diskName = $this->disk($disk);
        $folder = trim($folder, '/');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;

        // public disk needs public visibility; S3 skips ACL (bucket policy instead).
        $options = [];
        if ($diskName === 'public' || ($public && $diskName !== 's3')) {
            $options['visibility'] = 'public';
        }

        try {
            $stored = Storage::disk($diskName)->putFileAs(
                $folder !== '' ? $folder : '',
                $file,
                $filename,
                $options
            );
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
            $prev = $e->getPrevious();
            while ($prev) {
                $detail = $prev->getMessage();
                // Prefer the most specific AWS/S3 client error if present.
                if (str_contains($detail, 'NoSuchBucket')
                    || str_contains($detail, 'AccessDenied')
                    || str_contains($detail, 'InvalidAccessKeyId')
                    || str_contains($detail, 'SignatureDoesNotMatch')) {
                    break;
                }
                $prev = $prev->getPrevious();
            }

            throw new RuntimeException(
                __('messages.upload_failed').' '.$detail,
                0,
                $e
            );
        }

        if (! $stored) {
            throw new RuntimeException(__('messages.upload_failed'));
        }

        return [
            'path' => $stored,
            'url' => $this->url($stored, $diskName),
            'disk' => $diskName,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    /**
     * Upload many files. Returns list of upload result arrays.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, url: string, disk: string, original_name: string, mime: string|null, size: int}>
     */
    public function uploadMany(
        array $files,
        string $folder = 'uploads',
        ?string $disk = null,
        bool $public = true
    ): array {
        $results = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! $file->isValid()) {
                throw new InvalidArgumentException(__('messages.upload_invalid'));
            }

            $results[] = $this->upload($file, $folder, $disk, $public);
        }

        return $results;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string> Absolute URLs
     */
    public function uploadManyUrls(
        array $files,
        string $folder = 'uploads',
        ?string $disk = null
    ): array {
        return array_values(array_map(
            static fn (array $item) => $item['url'],
            $this->uploadMany($files, $folder, $disk)
        ));
    }

    public function delete(?string $pathOrUrl, ?string $disk = null): bool
    {
        if (! $pathOrUrl) {
            return false;
        }

        $diskName = $this->disk($disk);
        $path = $this->toPath($pathOrUrl, $diskName);

        if ($path === '' || ! Storage::disk($diskName)->exists($path)) {
            return false;
        }

        return Storage::disk($diskName)->delete($path);
    }

    /**
     * @param  array<int, string|null>  $pathsOrUrls
     */
    public function deleteMany(array $pathsOrUrls, ?string $disk = null): void
    {
        foreach ($pathsOrUrls as $item) {
            $this->delete($item, $disk);
        }
    }

    public function url(string $path, ?string $disk = null): string
    {
        $diskName = $this->disk($disk);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk($diskName)->url($path);
    }

    public function toPath(string $pathOrUrl, ?string $disk = null): string
    {
        if (! str_starts_with($pathOrUrl, 'http://') && ! str_starts_with($pathOrUrl, 'https://')) {
            return ltrim($pathOrUrl, '/');
        }

        $diskName = $this->disk($disk);
        $base = rtrim((string) Storage::disk($diskName)->url(''), '/').'/';

        if (str_starts_with($pathOrUrl, $base)) {
            return ltrim(substr($pathOrUrl, strlen($base)), '/');
        }

        $path = parse_url($pathOrUrl, PHP_URL_PATH);

        return ltrim((string) $path, '/');
    }

    private function assertValidImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException(__('messages.upload_invalid'));
        }

        if ($file->getSize() > self::MAX_SIZE_KB * 1024) {
            throw new InvalidArgumentException(__('messages.upload_too_large'));
        }

        $mime = $file->getMimeType();
        if ($mime && ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException(__('messages.upload_mime_invalid'));
        }
    }
}
