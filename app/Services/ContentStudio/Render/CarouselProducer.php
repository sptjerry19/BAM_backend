<?php

namespace App\Services\ContentStudio\Render;

use App\Models\ContentStudio\ContentAsset;
use App\Models\ContentStudio\ContentItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a content item into stored slides:
 *
 *   {prefix}/items/{id}/v{n}/01.png ... NN.png   then   manifest.json (written LAST)
 *
 * The manifest is the completion marker: if it exists and every slide it lists
 * is there, the render is done (this is what a recovered step relies on). A
 * version is immutable; changed text produces v{n+1} instead of overwriting.
 */
final class CarouselProducer
{
    public function __construct(
        private readonly CarouselSpecBuilder $specs,
        private readonly RendererFactory $renderers,
    ) {}

    public function disk(): string
    {
        return (string) config('content-studio.storage.disk');
    }

    public function directory(ContentItem $item, int $version): string
    {
        return config('content-studio.storage.prefix')."/items/{$item->id}/v{$version}";
    }

    public function manifestPath(ContentItem $item, int $version): string
    {
        return $this->directory($item, $version).'/manifest.json';
    }

    /** Version a render of this item will write to (bumped when the text changed since the last one). */
    public function currentVersion(ContentItem $item): int
    {
        return max(1, (int) ($item->brief_json['render_version'] ?? 1));
    }

    /** @return array<string, mixed>|null the manifest if it is complete and still valid */
    public function readManifest(ContentItem $item, int $version): ?array
    {
        $storage = Storage::disk($this->disk());
        $path = $this->manifestPath($item, $version);
        if (! $storage->exists($path)) {
            return null;
        }
        $manifest = json_decode((string) $storage->get($path), true);
        if (! is_array($manifest) || empty($manifest['slides'])) {
            return null;
        }
        foreach ($manifest['slides'] as $slide) {
            if (! $storage->exists($slide['path'])) {
                return null; // half-deleted render: do not trust it
            }
        }

        return $manifest;
    }

    /**
     * @return array{manifest: array<string, mixed>, version: int, reused: bool}
     *
     * @throws RenderException
     */
    public function produce(ContentItem $item, bool $force = false): array
    {
        $spec = $this->specs->fromItem($item);
        $version = $this->currentVersion($item);

        $existing = $this->readManifest($item, $version);
        if ($existing !== null && ! $force && ($existing['spec_hash'] ?? null) === $spec->hash()) {
            return ['manifest' => $existing, 'version' => $version, 'reused' => true];
        }
        if ($existing !== null) {
            // The text changed (or a re-render was asked for): never overwrite a published version.
            $version++;
            $item->forceFill(['brief_json' => array_merge($item->brief_json ?? [], ['render_version' => $version])])->save();
        }

        $renderer = $this->renderers->make();
        $tmp = sys_get_temp_dir().'/content-studio-'.Str::uuid();
        try {
            $files = $renderer->render($spec, $tmp);
            $manifest = $this->store($item, $spec, $renderer->name(), $version, $files);
        } finally {
            $this->deleteDirectory($tmp);
        }

        return ['manifest' => $manifest, 'version' => $version, 'reused' => false];
    }

    /** @param list<string> $files @return array<string, mixed> */
    private function store(ContentItem $item, CarouselSpec $spec, string $driver, int $version, array $files): array
    {
        $storage = Storage::disk($this->disk());
        $directory = $this->directory($item, $version);
        $slides = [];

        foreach ($files as $i => $file) {
            $size = @getimagesize($file);
            if ($size === false || $size[0] !== $spec->width || $size[1] !== $spec->height || $size[2] !== IMAGETYPE_PNG) {
                throw new RenderException(sprintf('Slide %d is not a %dx%d PNG.', $i + 1, $spec->width, $spec->height));
            }

            $contents = (string) file_get_contents($file);
            $path = sprintf('%s/%02d.png', $directory, $i + 1);
            $storage->put($path, $contents);

            $sha = hash('sha256', $contents);
            ContentAsset::updateOrCreate(
                ['content_item_id' => $item->id, 'kind' => 'carousel_slide', 'path' => $path],
                [
                    'storage_disk' => $this->disk(), 'checksum_sha256' => $sha, 'mime' => 'image/png', 'bytes' => strlen($contents),
                    'width' => $size[0], 'height' => $size[1],
                    // we drew these ourselves from text: no third-party media is involved
                    'license_state' => 'original',
                ],
            );
            $slides[] = ['index' => $i + 1, 'path' => $path, 'sha256' => $sha, 'bytes' => strlen($contents), 'width' => $size[0], 'height' => $size[1]];
        }

        $manifest = [
            'version' => $version,
            'item_id' => $item->id,
            'driver' => $driver,
            'spec_hash' => $spec->hash(),
            'prompt_version' => $item->brief_json['prompt_version'] ?? null,
            'generated_at' => now()->toIso8601String(),
            'slides' => $slides,
        ];

        // Last, on purpose: a manifest on disk means every slide above is complete.
        $storage->put($this->manifestPath($item, $version), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $manifest;
    }

    private function deleteDirectory(string $dir): void
    {
        try {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        } catch (Throwable) {
            // temp clean-up must never fail a render
        }
    }
}
