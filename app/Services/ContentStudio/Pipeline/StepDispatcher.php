<?php

namespace App\Services\ContentStudio\Pipeline;

use App\Jobs\ContentStudio\ContentStepJob;
use App\Models\ContentStudio\ContentPipelineRun;
use InvalidArgumentException;

/**
 * Dispatches the next job of a run AND remembers it on the run, so that
 * content:recover can dispatch it again if the queue message is lost.
 *
 * Only classes under App\Jobs\ContentStudio that extend ContentStepJob, with
 * scalar constructor arguments, are accepted. The descriptor is read back from
 * the database, so it is treated as untrusted.
 */
final class StepDispatcher
{
    public const NAMESPACE_PREFIX = 'App\\Jobs\\ContentStudio\\';

    /** @param list<scalar|null> $args */
    public function dispatch(ContentPipelineRun $run, string $jobClass, array $args = []): void
    {
        $this->assertAllowed($jobClass, $args);

        $run->forceFill(['resume_job_json' => ['class' => $jobClass, 'args' => array_values($args)]])->save();
        $this->send($jobClass, $args);
    }

    /** Re-send the job saved on the run. Returns false when there is nothing valid to send. */
    public function redispatch(ContentPipelineRun $run): bool
    {
        $descriptor = $run->resume_job_json;
        if (! is_array($descriptor) || ! isset($descriptor['class'])) {
            return false;
        }
        $args = is_array($descriptor['args'] ?? null) ? $descriptor['args'] : [];

        try {
            $this->assertAllowed((string) $descriptor['class'], $args);
        } catch (InvalidArgumentException) {
            return false;
        }

        $this->send($descriptor['class'], $args);
        $run->touch(); // throttles the next recovery pass

        return true;
    }

    /** @param array<array-key, mixed> $args */
    private function assertAllowed(string $class, array $args): void
    {
        if (! str_starts_with($class, self::NAMESPACE_PREFIX)
            || ! class_exists($class)
            || ! is_subclass_of($class, ContentStepJob::class)) {
            throw new InvalidArgumentException("Job class {$class} is not an allowed content step job.");
        }
        foreach ($args as $arg) {
            if (! is_scalar($arg) && $arg !== null) {
                throw new InvalidArgumentException('Content step job arguments must be scalars.');
            }
        }
    }

    /** @param array<array-key, mixed> $args */
    private function send(string $class, array $args): void
    {
        dispatch(new $class(...array_values($args)));
    }
}
