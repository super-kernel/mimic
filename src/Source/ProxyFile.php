<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Source;

use Throwable;

use function clearstatcache;
use function fclose;
use function fflush;
use function fgets;
use function file_exists;
use function fopen;
use function function_exists;
use function fwrite;
use function hash;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function mkdir;
use function opcache_invalidate;
use function preg_match;
use function preg_quote;
use function rename;
use function rtrim;
use function str_replace;
use function strlen;
use function substr;
use function uniqid;
use function unlink;

/** Owns a proxy's address, cache fingerprint, and atomic publication. */
final readonly class ProxyFile
{
    private const string HEADER_PREFIX = '<?php // mimic-cache-sha256: ';

    /** @var non-empty-string */
    public string $path;

    /** @var non-empty-string Fingerprint of the source path, content, and transformation */
    public string $hash;

    private string $directory;

    /** @param class-string $class */
    public function __construct(
        string     $vendorDir,
        string     $class,
        SourceFile $source,
        string     $cacheKey,
    )
    {
        $this->directory = rtrim($vendorDir, '/\\') . '/.cache/mimic';
        $this->path = $this->directory . '/' . str_replace('\\', '.', $class) . '.tpl';
        $this->hash = hash('sha256', $source->path . "\0" . $cacheKey . "\0" . $source->hash);
    }

    /** @return non-empty-string|false|null The recorded hash, false for an unusable proxy, or null if absent */
    public function cachedHash(): string|false|null
    {
        clearstatcache(true, $this->path);

        if (!file_exists($this->path) && !is_link($this->path)) {
            return null;
        }

        $stream = is_file($this->path) ? @fopen($this->path, 'rb') : false;

        if (false === $stream) {
            return false;
        }

        try {
            $header = @fgets($stream);
        } finally {
            fclose($stream);
        }

        if (!is_string($header)
            || 1 !== preg_match(
                '/\A' . preg_quote(self::HEADER_PREFIX, '/') . '([a-f0-9]{64})\n\z/',
                $header,
                $matches,
            )) {
            return false;
        }

        return $matches[1];
    }

    /**
     * @param callable(): string $generate Produces complete PHP source only after the cache directory is available
     *
     * @return non-empty-string|null
     *
     * @throws Throwable When generating transformed source fails
     */
    public function publish(callable $generate): ?string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            return null;
        }

        $code = self::HEADER_PREFIX . $this->hash . "\n" . substr($generate(), 5);
        $temporary = uniqid($this->directory . '/.mimic-', true);
        $stream = @fopen($temporary, 'xb');

        if (false === $stream) {
            return null;
        }

        try {
            $offset = 0;
            $length = strlen($code);

            while ($offset < $length) {
                $written = @fwrite($stream, substr($code, $offset));

                if (false === $written || 0 === $written) {
                    return null;
                }

                $offset += $written;
            }

            if (!@fflush($stream)) {
                return null;
            }

            fclose($stream);
            $stream = null;

            if (!@rename($temporary, $this->path)) {
                return null;
            }

            clearstatcache(true, $this->path);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->path, true);
            }

            return $this->path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
