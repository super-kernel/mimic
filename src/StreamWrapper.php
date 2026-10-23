<?php

declare(strict_types=1);

namespace SuperKernel\Mimic;

use function strlen;
use function substr;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/** Read-only PHP stream callbacks. */
final class StreamWrapper
{
    /** @var array<non-empty-string, string> */
    private static array $sources = [];

    /** @var resource|null */
    public mixed $context = null;

    private string $code = '';

    private int $position = 0;

    /** @param non-empty-string $path The stream driver's content-addressed URI */
    public static function publish(string $path, string $code): void
    {
        self::$sources[$path] = $code;
    }

    /**
     * PHP requires the complete positional stream callback signature.
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (!isset(self::$sources[$path]) || ('r' !== $mode && 'rb' !== $mode)) {
            return false;
        }

        $this->code = self::$sources[$path];
        $this->position = 0;
        $opened_path = $path;

        return true;
    }

    /**
     * PHP supplies option arguments even though this read-only stream supports none.
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->code, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->code);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [
            'mode' => 0o100444,
            'size' => strlen($this->code),
        ];
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->code) + $offset,
            default  => -1,
        };

        if (0 > $position || $position > strlen($this->code)) {
            return false;
        }

        $this->position = $position;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_close(): void
    {
        $this->code = '';
        $this->position = 0;
    }

    /**
     * The virtual stream has no links or filesystem warnings affected by PHP's flags.
     *
     * @return array<string, int>|false
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!isset(self::$sources[$path])) {
            return false;
        }

        return [
            'mode' => 0o100444,
            'size' => strlen(self::$sources[$path]),
        ];
    }
}
