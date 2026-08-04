<?php

namespace App\Services\Storage;

use Illuminate\Http\Request;

class UploadService
{
    public function __construct(private readonly FileUploadService $uploads)
    {
    }

    /**
     * @return array{files: array<int, array>, urls: array<int, string>}
     */
    public function store(array $data, Request $request): array
    {
        $folder = $data['folder'] ?? 'uploads';
        $results = $this->uploads->uploadMany($request->file('files', []), $folder);

        return [
            'files' => $results,
            'urls' => array_column($results, 'url'),
        ];
    }
}
