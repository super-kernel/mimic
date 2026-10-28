<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Contract;

use RuntimeException;
use SuperKernel\Mimic\Source\SourceFile;
use Throwable;

interface MimicDriverInterface
{
    /**
     * Prepares transformed source without executing it or registering an autoloader.
     *
     * @return non-empty-string|null A loadable path, or null when cache publication is unavailable
     *
     * @throws Throwable        When source transformation fails; the original failure propagates unchanged
     * @throws RuntimeException When the stream protocol cannot be registered
     */
    public function prepare(SourceFile $source): ?string;
}
