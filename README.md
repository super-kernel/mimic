# SuperKernel Mimic

A PHP 8.4 source preparation library with cached proxy files and read-only
`mimic://` streams. Both drivers use an injected `Contract\MutatorInterface`.
Mimic owns source snapshots, cache publication, and generic diagnostic utilities.
The AOP component owns method weaving, join points, interceptor dispatch, class
selection, and replacement loading.

## Requirements

- PHP 8.4.27 or later in the 8.4.x series with Reflection.
- `super-kernel/contract`.
- An injected `SuperKernel\Contract\VendorDirInterface` for the cache location.
- An injected `SuperKernel\Mimic\Contract\MutatorInterface` in both drivers.
- An injected `SuperKernel\Contract\PackageManagerInterface` in the file driver for source class names.
- Local, readable, trusted PHP source files.

Mimic has no runtime dependency on PHP-Parser. A supplied transformation may
declare its own parser dependency. Swoole is not required. Stream buffers belong
to the current process.

## Drivers and cache lifecycle

`MimicFactory` receives `Driver\FileDriver` and `Driver\StreamDriver` through its
constructor. `prepare($sourceFile)` reads one source snapshot and returns a path.
The default policy tries File first and uses Stream when the cache cannot be
created, read, written, or atomically replaced, or when the source is outside the
package manager's class graph.

| Driver | Result | Storage |
| --- | --- | --- |
| `FileDriver` | Absolute proxy file path | `<VendorDirInterface>/.cache/mimic/` |
| `StreamDriver` | `mimic://` URI | Current-process memory |

Proxy filenames use the original fully qualified class name, replace namespace
separators with dots, and end in the single suffix `.tpl`, for example
`Src.Service.TestService.tpl`. Dots preserve distinctions such as
`Acme\Service\Test` versus `Acme\Service_Test`. The package manager supplies the
class name without reflecting or loading the source. When one file declares
several mapped symbols, the first name in its sorted class map names the proxy.

Proxies live directly at
`<VendorDirInterface>/.cache/mimic/<class-name>.tpl`, without an identity subdirectory.
`Source\ProxyFile` owns this address, the shared header format used for writing
and reading, and atomic publication. `FileDriver` resolves the class name and
delegates proxy operations to that per-source object. It returns the same address
for newly published and cached proxies, which the consuming loader uses directly.

The first line is `<?php // mimic-cache-sha256: <digest>`. The digest is SHA-256 over
the canonical source path, the mutator cache key, and the source content hash,
separated by null bytes. The original path is not written into the proxy header.
Matching fingerprints reuse readable proxies without writing, creating directories,
or requiring the Stream protocol. Changed source content, a relocated source, or
a changed mutator key replaces the same class-named proxy.
Each class has one file slot in a vendor cache, so concurrent integrations sharing
that cache must use the same source location and transformation. Use Stream for
independent snapshots of different transformations.

Publication creates no `.lock` file and ignores historical lock sidecars.
Complete temporary files are atomically renamed in the cache directory.
Concurrent publishers may repeat transformation work, but readers never observe
a partially written proxy. A prepared file path follows later replacements;
use the Stream driver's immutable addresses when retaining different snapshots.
Successful replacement invalidates OPcache when available. Failed publication
preserves historical proxies and prepares the current snapshot through Stream.
Unreadable or unrecognized headers also select Stream without overwriting the
unverifiable proxy. Parsing and transformation failures propagate.

Modification time, inode, and size are not used as content identifiers: an in-place
same-length edit can preserve all three. PHP's
[`declare` directives](https://www.php.net/manual/en/control-structures.declare.php)
configure execution and do not provide a custom checksum directive.
[`__halt_compiler()`](https://www.php.net/manual/en/function.halt-compiler.php)
stops compilation before a data tail; it is not a first-line checksum facility.
A SHA-256 comment provides the cache check after this evaluation.

The Stream driver registers its protocol lazily and rejects a conflicting owner.
Its URI includes the source path, content hash, and mutator cache key. Preparing
another source revision or transformation does not alter existing snapshots.
Neither driver selects classes, registers SPL callbacks, or executes generated PHP.

Both drivers implement `Contract\MimicDriverInterface::prepare(SourceFile)`.
`Source\SourceFile` captures the canonical path, source bytes, and content hash.
File returns `null` when its cache is unavailable; Stream returns a URI or throws.

## Transformation contract and AOP integration

`Contract\MutatorInterface` defines `mutate($code, $sourcePath)` and
`getCacheKey()`. The mutation returns complete PHP source beginning with `<?php`.
The key must identify the transformation revision and all configuration affecting
its output, remain stable across equivalent instances, and change when generated
code must change for identical source. Both drivers must receive the same
transformation. Mimic has no dependency on AOP or an embedded aspect runtime.

AOP's `SuperKernel\Aop\Mutation\Mutator` declares `#[Provider(MutatorInterface::class)]`
to bind this interface directly to its shared instance. The container constructs `MimicFactory`
from injected drivers. AOP's loader selects collected Aspect classes,
prepares them through Mimic, and executes the result. See the
[AOP README](https://github.com/super-kernel/aop/blob/main/README.md) for callback registration and supported semantics.

`Diagnostics\ExceptionPatcher`, `Diagnostics\SourceMap`, and
`Diagnostics\TraceRestorer` remain generic utilities. Calling
`TraceRestorer::setup()` deliberately replaces the global exception handler;
normal source preparation never calls it.

`FileDriver` now requires the package manager as its third constructor argument.
It uses the shared contract, with the Composer component supplying the application's
implementation; Mimic does not construct a package manager or require AOP.
The transformation owns generated source contents. AOP binds source-location
constants from the package graph before loading a proxy, so its generated code
can preserve diagnostics and magic constants without embedding original paths.

The flat `.tpl` format bypasses historical hash subdirectories, `.php` and `.php.tpl`
proxies, and `.lock` files. Preparation does not read, rename, or delete those artifacts.
Restart workers to load the new generated code; classes already loaded into a
running process cannot be replaced.

## Upstream

This component ports [wheakerd/mimic](https://github.com/wheakerd/mimic) at
[443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d](https://github.com/wheakerd/mimic/tree/443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d).
The repository belongs to wheakerd, who has explicitly authorized importing and
adapting its source into `super-kernel/mimic`. wheakerd has designated the
[MIT License](LICENSE) for this component. This source and authorization
declaration is retained in [`NOTICE`](NOTICE), including in distribution archives.

| Identity | Upstream | This component |
| --- | --- | --- |
| Composer package | `wheakerd/php-mimic` | `super-kernel/mimic` |
| Source root | upstream `src/` | `.vendor/mimic/src/` |
| PHP namespace | `PhpMimic\` | `SuperKernel\Mimic\` |
| Test namespace | `PhpMimicTest\` | `SuperKernel\Mimic\Tests\` |
| Test entry | framework command demo | PHPUnit suite |

## Driver and transformation ownership

`MimicFactory` receives `Driver\FileDriver` and `Driver\StreamDriver`, which implement
`Contract\MimicDriverInterface`, and exposes `prepare()`. Both drivers require `MutatorInterface`.
An owning loader consumes the prepared address. AOP supplies this integration and owns weaving, runtime dispatch,
join points, tracker, and the optional visibility visitor `SuperKernel\Aop\Mutation\Modifier`.
Mimic has no production dependency on AOP or PHP-Parser. Transformation failures retain their original Throwable.

Cache identity includes transformation semantics. Rebuild annotation caches and restore-only/Phar artifacts when
persisted class declarations change. Historical files remain untouched; restart workers after rebuilding because
already loaded classes cannot be rewritten.

## Development

In the SuperKernel workspace, install dependencies only at the repository root.
Run the suite and formatting check there:

```shell
test_bootstrap=$(mktemp)
cat > "$test_bootstrap" <<'PHP'
<?php
require getcwd() . '/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('SuperKernel\\Mimic\\Tests\\', getcwd() . '/.vendor/mimic/tests');
$loader->register(true);
PHP
php84 vendor/bin/phpunit --configuration .vendor/mimic/phpunit.xml.dist --bootstrap "$test_bootstrap"
rm "$test_bootstrap"
php84 .php-cs-fixer.dist.php check --config=.vendor/mimic/.php-cs-fixer.dist.php --diff
```

The root Composer manifest supplies the path package mapping. The disposable
bootstrap registers this package's test namespace because the root manifest
maps no component tests.
The parser-backed test mutator and parser-error cases use `nikic/php-parser`
`^5.9.0`, declared in this component's `require-dev`.
The component's Composer scripts and PHPUnit bootstrap support a separately
requested standalone installation. Its lock and vendor directory are ignored.
`.gitattributes` excludes repository/tool configuration and this README from
distribution archives while retaining source, tests, the manifest, `NOTICE`, and `LICENSE`.
