<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Driver;

use RuntimeException;
use SuperKernel\Mimic\Contract\MimicDriverInterface;
use SuperKernel\Mimic\Contract\MutatorInterface;
use SuperKernel\Mimic\Source\SourceFile;
use SuperKernel\Mimic\StreamWrapper;

use function hash;
use function in_array;
use function stream_get_wrappers;
use function stream_wrapper_register;

/** Publishes in-memory transformed source through a lazily registered read-only protocol. */
final class StreamDriver implements MimicDriverInterface
{
    private static bool $ownsProtocol = false;

    public function __construct(private readonly MutatorInterface $mutator)
    {
    }

    public function prepare(SourceFile $source): string
    {
        $code = $this->mutator->mutate($source->code, $source->path);

        if (!in_array('mimic', stream_get_wrappers(), true)) {
            if (!stream_wrapper_register('mimic', StreamWrapper::class)) {
                throw new RuntimeException('Unable to register the mimic stream protocol.');
            }

            self::$ownsProtocol = true;
        } elseif (!self::$ownsProtocol) {
            throw new RuntimeException('The mimic stream protocol is already registered by another library.');
        }

        $path = 'mimic://' . hash(
            'sha256',
            $source->path . "\0" . $source->hash . "\0" . $this->mutator->getCacheKey(),
        );
        StreamWrapper::publish($path, $code);

        return $path;
    }
}
