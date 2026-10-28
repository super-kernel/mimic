<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Diagnostics;

use ReflectionClass;
use Throwable;

final class ExceptionPatcher
{
    public static function patch(Throwable $throwable, string $originalFile, int $originalLine): Throwable
    {
        $reflection = new ReflectionClass($throwable);

        // Restore the location while preserving the original throwable instance.
        if ($reflection->hasProperty('file')) {
            $propFile = $reflection->getProperty('file');
            $propFile->setValue($throwable, $originalFile);
        }

        if ($reflection->hasProperty('line')) {
            $propLine = $reflection->getProperty('line');
            $propLine->setValue($throwable, $originalLine);
        }

        return $throwable;
    }
}
