<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Mimic

`super-kernel/mimic` prepares a transformed copy of a PHP source file, through a transformation you supply, as a cached proxy file or a read-only `mimic://` stream that your code then loads.

## Installation

```bash
composer require super-kernel/mimic
```

The package has no requirement beyond PHP and does not depend on PHP-Parser or Swoole. It needs a `SuperKernel\Mimic\Contract\MutatorInterface`: `super-kernel/aop` provides one, otherwise the application declares its own. The vendor directory and the package locations come from the metadata of `skernel build`, so a project needs SKERNEL to build it.

## What it provides

- `MimicFactory::prepare()`, which returns the address of the transformed source of one file.
- Proxy files in `vendor/.cache/mimic/`, named after the class and reused while the source and the transformation stay the same.
- Proxies that stay valid when the project moves or is packaged into a Phar archive.
- A `mimic://` stream in memory when the cache cannot be used or the file is outside the project's packages.
- Atomic publication, so concurrent processes never read a partial proxy.

It does not:

- choose which classes to transform, or register an autoloader;
- execute the source it prepares;
- replace a class that a process has already loaded;
- parse PHP itself; the transformation does.

## Quick start

In a project without `super-kernel/aop`, declare a class with a placeholder. Save it as `src/Billing/Edition.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Billing;

final class Edition
{
    public const string NAME = '%EDITION%';
}
```

Declare the transformation. Save it as `src/Mimic/FillEdition.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Mimic;

use SuperKernel\Attribute\Provider;
use SuperKernel\Mimic\Contract\MutatorInterface;

use function str_replace;

#[Provider(MutatorInterface::class)]
final readonly class FillEdition implements MutatorInterface
{
    public function getCacheKey(): string
    {
        return 'fill-edition-1';
    }

    public function mutate(string $code, ?string $sourcePath = null): string
    {
        return str_replace('%EDITION%', 'Community', $code);
    }
}
```

Prepare and load the class. Save the script as `bin/edition.php`:

```php
<?php

declare(strict_types=1);

use Src\Billing\Edition;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\BasePathInterface;
use SuperKernel\Mimic\MimicFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = ApplicationContext::getContainer();
$source = $container->get(BasePathInterface::class) . '/src/Billing/Edition.php';

$address = $container->get(MimicFactory::class)->prepare($source);

require $address;

echo basename($address), PHP_EOL;
echo Edition::NAME, PHP_EOL;
```

Build the project and run the script:

```bash
skernel build
php bin/edition.php
```

It prints:

```text
Src.Billing.Edition.tpl
Community
```

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Mimic\Contract\MutatorInterface` | Interface | Implemented by the transformation: `mutate()` returns the new source and `getCacheKey()` identifies the transformation. |
| `SuperKernel\Mimic\MimicFactory` | Class | Provided by the container; `prepare()` returns a proxy file path or a `mimic://` address. |
| `SuperKernel\Mimic\Contract\MimicDriverInterface` | Interface | Implemented by the two drivers; `prepare()` takes a source snapshot. |
| `SuperKernel\Mimic\Driver\FileDriver` | Class | Writes and reuses proxy files in the vendor directory's cache. |
| `SuperKernel\Mimic\Driver\StreamDriver` | Class | Holds transformed source in memory behind `mimic://` addresses. |
| `SuperKernel\Mimic\Source\SourceFile` | Class | A snapshot of one source file: its path, content, and content hash. |
| `SuperKernel\Mimic\Diagnostics\ExceptionPatcher` | Class | `patch()` gives a throwable from generated code its original file and line. |

## Behavior to know

- A proxy is reused while the source content, its package-relative location, and the cache key stay the same; modification times are never used. Change `getCacheKey()` whenever the same source must produce different code.
- When the cache cannot be created, read, or written, or the source is outside the project's packages, `prepare()` returns a `mimic://` address instead, and an existing proxy is left untouched.
- A source file that cannot be read raises `InvalidArgumentException` with `The source file is not readable: <path>`; an exception from the transformation propagates unchanged.
- Another library that owns the `mimic` stream protocol makes the fallback fail with `The mimic stream protocol is already registered by another library.`
- A class that a process has loaded cannot be replaced: restart long-running workers after a change, and run `skernel build` again after changing classes or packages.

## Documentation

The developer documentation describes the drivers, the cache and its fingerprint, the stream fallback, writing a transformation, and the diagnostics utility, with complete examples: [SuperKernel Mimic](https://github.com/super-kernel/.github/blob/main/docs/components/mimic.md).

## License

Released under the MIT License. See [LICENSE](LICENSE). The upstream source and its authorization are recorded in [NOTICE](NOTICE).
