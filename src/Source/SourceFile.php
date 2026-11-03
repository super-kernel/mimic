<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Source;

use InvalidArgumentException;

use function file_get_contents;
use function hash;
use function is_file;
use function realpath;
use function str_contains;
use function str_starts_with;

/** A single source snapshot shared by both drivers during preparation. */
final readonly class SourceFile
{
    /** @var non-empty-string */
    public string $path;

    public string $code;

    /** @var non-empty-string */
    public string $hash;

    /**
     * Canonicalizes local paths; realpath() cannot resolve Phar archive paths, so those are kept as given.
     *
     * @throws InvalidArgumentException When the local or archived source cannot be resolved or read
     */
    public function __construct(string $path)
    {
        if ('' === $path || str_contains($path, "\0")) {
            $resolved = false;
        } elseif (str_starts_with($path, 'phar://')) {
            $resolved = $path;
        } else {
            $resolved = realpath($path);
        }

        if (false === $resolved || !is_file($resolved)) {
            throw new InvalidArgumentException("The source file is not readable: $path");
        }

        $code = @file_get_contents($resolved);

        if (false === $code) {
            throw new InvalidArgumentException("The source file is not readable: $path");
        }

        $this->path = $resolved;
        $this->code = $code;
        $this->hash = hash('sha256', $code);
    }
}
