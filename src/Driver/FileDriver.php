<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Driver;

use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\Mimic\Contract\MimicDriverInterface;
use SuperKernel\Mimic\Contract\MutatorInterface;
use SuperKernel\Mimic\Source\ProxyFile;
use SuperKernel\Mimic\Source\SourceFile;

use function array_search;

/** Publishes complete proxies atomically and reuses them while the cache fingerprint matches. */
final readonly class FileDriver implements MimicDriverInterface
{
    public function __construct(
        private VendorDirInterface      $vendorDir,
        private MutatorInterface        $mutator,
        private PackageManagerInterface $packages,
    )
    {
    }

    public function prepare(SourceFile $source): ?string
    {
        $class = array_search($source->path, $this->packages->allClasses(), true);

        if (false === $class) {
            return null;
        }

        $proxy = new ProxyFile((string) $this->vendorDir, $class, $source, $this->mutator->getCacheKey());
        $hash = $proxy->cachedHash();

        if (false === $hash) {
            return null;
        }

        if ($proxy->hash === $hash) {
            return $proxy->path;
        }

        return $proxy->publish(fn(): string => $this->mutator->mutate($source->code, $source->path));
    }
}
