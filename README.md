<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Mimic

`super-kernel/mimic` prepares transformed PHP source as cached proxy files or read-only `mimic://` streams. Both drivers apply an injected `Contract\MutatorInterface`. Mimic owns source snapshots and cache publication; it does not select classes, register autoloaders, or execute the generated code. `super-kernel/aop` builds method weaving and interceptor dispatch on top of it.

## Requirements

- An injected `SuperKernel\Contract\VendorDirInterface` for the cache location.
- An injected `SuperKernel\Mimic\Contract\MutatorInterface` in both drivers.
- An injected `SuperKernel\Contract\PackageManagerInterface` in the file driver for source class names and package locations.
- Readable, trusted PHP source files, either local or inside a Phar archive.

Mimic has no runtime dependency on PHP-Parser or Swoole. A supplied transformation may declare its own parser dependency. Stream buffers belong to the current process.

## Drivers and cache lifecycle

`MimicFactory` receives `Driver\FileDriver` and `Driver\StreamDriver` through its constructor. `prepare($sourcePath)` reads one source snapshot and returns a proxy file path or `mimic://` URI. The default policy tries File first and uses Stream when the cache cannot be created, read, written, or atomically replaced, or when the source is outside the package manager's class graph.

| Driver         | Result                   | Storage                              |
|----------------|--------------------------|--------------------------------------|
| `FileDriver`   | Absolute proxy file path | `<VendorDirInterface>/.cache/mimic/` |
| `StreamDriver` | `mimic://` URI           | Current-process memory               |

Proxies live directly at `<VendorDirInterface>/.cache/mimic/<class-name>.tpl`. The file name is the original fully qualified class name with namespace separators replaced by dots, for example `Src.Service.TestService.tpl`, so `Acme\Service\Test` and `Acme\Service_Test` stay distinct. The package manager supplies the class name without reflecting or loading the source. When one file declares several mapped symbols, the first name in its sorted class map names the proxy. Newly published and cached proxies return the same address.

The first line is `<?php // mimic-cache-sha256: <digest>`. The digest is SHA-256 over the source location, the mutator cache key, and the source content hash, separated by null bytes. The location is the owning package's name and the source path relative to that package's directory; a source outside its located package keeps its canonical path. The original path is not written into the proxy. Matching fingerprints reuse readable proxies without writing, creating directories, or requiring the Stream protocol. Changed source content, a source moved within its package, or a changed mutator key replaces the same class-named proxy. Moving the project or a package keeps its proxies valid, so proxies prepared before packaging are reused when the application runs from a Phar archive. Modification time, inode, and size are never used to detect changes. Each class has one file slot in a vendor cache, so concurrent integrations sharing that cache must use the same source location and transformation. Use Stream for independent snapshots of different transformations.

Complete temporary files are atomically renamed in the cache directory, without lock files. Concurrent publishers may repeat transformation work, but readers never observe a partially written proxy. A prepared file path follows later replacements; use the Stream driver's immutable addresses when retaining different snapshots. Successful replacement invalidates OPcache when available. Failed publication preserves existing proxies and prepares the current snapshot through Stream. Unreadable or unrecognized headers also select Stream without overwriting the unverifiable proxy. Parsing and transformation failures propagate with their original Throwable. Preparation never reads, renames, or deletes other cache files, such as hash subdirectories, `.php` or `.php.tpl` proxies, and `.lock` files.

The Stream driver registers its protocol lazily and rejects a conflicting owner. Its URI includes the source path, content hash, and mutator cache key, so preparing another source revision or transformation does not alter existing snapshots.

Both drivers implement `Contract\MimicDriverInterface::prepare(SourceFile)`. `Source\SourceFile` captures the canonical local path, or a Phar archive path as given, together with the source bytes and content hash. File returns `null` when its cache is unavailable; Stream returns a URI or throws.

Classes already loaded into a running process cannot be replaced: restart workers to load new generated code. Rebuild annotation caches and restore-only or Phar artifacts when persisted class declarations change.

## Transformation contract

`Contract\MutatorInterface` defines `mutate($code, $sourcePath)` and `getCacheKey()`. The mutation returns complete PHP source beginning with `<?php`. The key must identify the transformation revision and all configuration affecting its output, remain stable across equivalent instances, and change when generated code must change for identical source. The output may depend on the source location only through its package-relative location: bind absolute paths at runtime instead of embedding them, so proxies stay valid when the project moves or is packaged into a Phar archive. Both drivers must receive the same transformation.

With `super-kernel/aop` installed, its `SuperKernel\Aop\Mutation\Mutator` is bound to `MutatorInterface`, and AOP's loader prepares and loads the collected Aspect classes through Mimic. See the [AOP README](https://github.com/super-kernel/aop/blob/main/README.md) for supported semantics.

`Diagnostics\ExceptionPatcher` is a generic utility. Its `patch($throwable, $originalFile, $originalLine)` sets an original location on a throwable raised from generated code. Mimic never replaces the global exception handler.

## Upstream

This component ports [wheakerd/mimic](https://github.com/wheakerd/mimic) at [443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d](https://github.com/wheakerd/mimic/tree/443ad3e4a2c8dd9ce7b6028b0b7aea163be4e47d). The repository belongs to wheakerd, who has explicitly authorized importing and adapting its source into `super-kernel/mimic`. wheakerd has designated the [MIT License](LICENSE) for this component. This source and authorization declaration is retained in [`NOTICE`](NOTICE), including in distribution archives.

| Identity         | Upstream             | This component       |
|------------------|----------------------|----------------------|
| Composer package | `wheakerd/php-mimic` | `super-kernel/mimic` |
| PHP namespace    | `PhpMimic\`          | `SuperKernel\Mimic\` |
