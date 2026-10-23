<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Contract;

use Throwable;

/** Transforms source without selecting classes, executing code, or registering an autoloader. */
interface MutatorInterface
{
    /**
     * Identifies the transformation and its revision, including output-affecting configuration.
     * Change this key whenever unchanged source could require different generated code.
     *
     * @return non-empty-string A stable identity shared by equivalent mutator instances
     */
    public function getCacheKey(): string;

    /**
     * @return string Complete PHP source beginning with the opening PHP tag
     *
     * @throws Throwable When source transformation fails; the original failure propagates unchanged
     */
    public function mutate(string $code, ?string $sourcePath = null): string;
}
