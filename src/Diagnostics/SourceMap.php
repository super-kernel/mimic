<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Diagnostics;

/** Optional line mappings supplied explicitly by callers. */
final class SourceMap
{
    /** @var array<string, array<int, int>> */
    private static array $map = [];

    /**
     * Registers a line mapping for a specific file path.
     *
     * @param string          $path    the mimic:// protocol path of the transformed file
     * @param array<int, int> $lineMap an associative array mapping mutated lines to original lines
     */
    public static function register(string $path, array $lineMap): void
    {
        self::$map[$path] = $lineMap;
    }

    /**
     * Retrieves the original line number from a mutated line number.
     *
     * @param string $path the mimic:// protocol path to look up
     * @param int    $line the line number in the mutated (shadow) code
     *
     * @return int the corresponding line number in the original source file
     */
    public static function getOriginalLine(string $path, int $line): int
    {
        return self::$map[$path][$line] ?? $line;
    }
}
