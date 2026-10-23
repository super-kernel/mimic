<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Tests\Fixture;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SuperKernel\Mimic\Contract\MutatorInterface;

use function str_replace;

final readonly class RenamingMutator implements MutatorInterface
{
    public function __construct(private string $className = 'PreparedDriverProbe')
    {
    }

    public function getCacheKey(): string
    {
        return 'rename:' . $this->className;
    }

    public function mutate(string $code, ?string $sourcePath = null): string
    {
        $ast = new ParserFactory()->createForHostVersion()->parse($code);

        return str_replace('MimicDriverProbe', $this->className, new Standard()->prettyPrintFile($ast ?? []));
    }
}
