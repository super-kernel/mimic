<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Diagnostics;

use Throwable;

use function error_log;
use function set_exception_handler;
use function sprintf;
use function str_starts_with;
use function substr;

/** Explicit exception logging utility; it does not rewrite stack frames. */
final class TraceRestorer
{
    /** Replaces the global exception handler only when explicitly requested by the caller. */
    public static function setup(): void
    {
        set_exception_handler([
            self::class,
            'restoreException',
        ]);
    }

    public static function restoreException(Throwable $exception): void
    {
        $file = $exception->getFile();
        $line = $exception->getLine();

        if (str_starts_with($file, 'mimic://')) {
            $line = SourceMap::getOriginalLine($file, $line);
            $file = substr($file, 8);
        }

        error_log(sprintf('Exception in %s on line %d: %s', $file, $line, $exception->getMessage()));
    }
}
