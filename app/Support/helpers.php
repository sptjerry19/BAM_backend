<?php

use App\Services\Storage\FileUploadService;
use Illuminate\Http\UploadedFile;

if (! function_exists('upload_file')) {
    /**
     * Upload a single file to the default disk (S3).
     *
     * @return array{path: string, url: string, disk: string, original_name: string, mime: string|null, size: int}
     */
    function upload_file(UploadedFile $file, string $folder = 'uploads', ?string $disk = null): array
    {
        return app(FileUploadService::class)->upload($file, $folder, $disk);
    }
}

if (! function_exists('upload_files')) {
    /**
     * Upload multiple files and return absolute URLs.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, string>
     */
    function upload_files(array $files, string $folder = 'uploads', ?string $disk = null): array
    {
        return app(FileUploadService::class)->uploadManyUrls($files, $folder, $disk);
    }
}

if (! function_exists('delete_uploaded_file')) {
    function delete_uploaded_file(?string $pathOrUrl, ?string $disk = null): bool
    {
        return app(FileUploadService::class)->delete($pathOrUrl, $disk);
    }
}

if (! function_exists('delete_uploaded_files')) {
    /**
     * @param  array<int, string|null>  $pathsOrUrls
     */
    function delete_uploaded_files(array $pathsOrUrls, ?string $disk = null): void
    {
        app(FileUploadService::class)->deleteMany($pathsOrUrls, $disk);
    }
}
