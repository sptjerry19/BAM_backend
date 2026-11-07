<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Enums\ContentStudio\StepStatus;
use App\Models\ContentStudio\ContentPipelineStep;
use App\Services\ContentStudio\Ops\ContentEventLogger;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Decides what to do with a step whose lease ran out.
 *
 * A step that writes a file declares it up front in its input:
 *
 *   input_json.expected_output = { disk, path, bytes?, sha256? }
 *
 * If that file exists (and matches the optional size / checksum) the worker
 * died AFTER finishing the work, so the attempt is closed as succeeded instead
 * of rendering the same file again. Otherwise it is closed as lease_expired.
 *
 * The declaration comes from the database, so it is validated: only allowed
 * disks, only plain relative paths.
 */
final class StepOutputProbe
{
    public function __construct(private readonly ContentEventLogger $events) {}

    /**
     * Close an expired attempt. Returns true when the output was found and the
     * attempt was recovered as succeeded.
     */
    public function close(ContentPipelineStep $step): bool
    {
        $found = $this->probe($step);

        if ($found !== null) {
            $step->forceFill([
                'status' => StepStatus::Succeeded,
                'finished_at' => now(),
                'lease_until' => null,
                'output_json' => $found + ['recovered' => true],
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $this->events->log('step.recovered_output', 'pipeline_run', $step->run_id, [
                'step' => $step->step_name, 'attempt' => $step->attempt, 'path' => $found['path'],
            ]);

            return true;
        }

        $step->forceFill([
            'status' => StepStatus::Failed,
            'finished_at' => now(),
            'lease_until' => null,
            'error_code' => 'lease_expired',
            'error_message' => 'Worker stopped before finishing; lease expired.',
        ])->save();
        $this->events->log('step.lease_expired', 'pipeline_run', $step->run_id, [
            'step' => $step->step_name, 'attempt' => $step->attempt,
        ]);

        return false;
    }

    /** @return array{disk: string, path: string, bytes?: int, sha256?: string}|null */
    public function probe(ContentPipelineStep $step): ?array
    {
        $expected = $step->input_json['expected_output'] ?? null;
        if (! is_array($expected)) {
            return null;
        }

        $disk = $expected['disk'] ?? null;
        $path = $expected['path'] ?? null;

        if (! $this->isAcceptable($disk, $path)) {
            $this->events->log('step.probe_rejected', 'pipeline_run', $step->run_id, ['step' => $step->step_name, 'disk' => $disk]);

            return null;
        }

        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($path)) {
                return null;
            }

            $bytes = isset($expected['bytes']) ? (int) $expected['bytes'] : null;
            if ($bytes !== null && $storage->size($path) !== $bytes) {
                return null;
            }

            $sha256 = isset($expected['sha256']) ? strtolower((string) $expected['sha256']) : null;
            if ($sha256 !== null && $this->hash($disk, $path) !== $sha256) {
                return null;
            }
        } catch (Throwable $e) {
            // Storage unreachable: we cannot prove the file is there, so re-run the step.
            $this->events->log('step.probe_failed', 'pipeline_run', $step->run_id, [
                'step' => $step->step_name, 'error' => class_basename($e),
            ]);

            return null;
        }

        return array_filter(
            ['disk' => $disk, 'path' => $path, 'bytes' => $bytes, 'sha256' => $sha256],
            fn ($v) => $v !== null,
        );
    }

    private function isAcceptable(mixed $disk, mixed $path): bool
    {
        if (! is_string($disk) || ! in_array($disk, (array) config('content-studio.recover.probe_disks', []), true)) {
            return false;
        }
        if (! is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    private function hash(string $disk, string $path): ?string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $stream);
        fclose($stream);

        return hash_final($ctx);
    }
}
