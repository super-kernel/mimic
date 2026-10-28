<?php

declare(strict_types=1);

namespace SuperKernel\Mimic;

use InvalidArgumentException;
use RuntimeException;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\Source\SourceFile;
use Throwable;

/** Prepares transformed source; the consuming loader owns class selection and execution. */
final readonly class MimicFactory
{
    public function __construct(private FileDriver $files, private StreamDriver $streams)
    {
    }

    /**
     * Reuses a source-hash-matched proxy, falling back to a stream if cache publication fails.
     *
     * @return non-empty-string A proxy file path or mimic:// URI; no source is executed
     *
     * @throws InvalidArgumentException When the local source cannot be resolved or read
     * @throws Throwable                When source transformation fails; the original failure propagates unchanged
     * @throws RuntimeException         When the fallback stream protocol cannot be registered
     */
    public function prepare(string $sourcePath): string
    {
        $source = new SourceFile($sourcePath);

        return $this->files->prepare($source) ?? $this->streams->prepare($source);
    }

}
