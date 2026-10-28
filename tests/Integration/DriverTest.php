<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Tests\Integration;

use InvalidArgumentException;
use PhpParser\Error;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Mimic\Contract\MutatorInterface;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use SuperKernel\Mimic\Source\SourceFile;
use SuperKernel\Mimic\StreamWrapper;
use SuperKernel\Mimic\Tests\Fixture\RenamingMutator;
use SuperKernel\Mimic\Tests\Fixture\VendorDirectory;
use Throwable;

use function basename;
use function chmod;
use function class_exists;
use function clearstatcache;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function filemtime;
use function glob;
use function hash;
use function is_dir;
use function is_writable;
use function mkdir;
use function rename;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_replace;
use function str_starts_with;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function touch;
use function var_export;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DriverTest extends TestCase
{
    private VendorDirectory $vendor;

    private MimicFactory $mimic;

    /** @var array<class-string, non-empty-string> */
    private array $classes = [];

    /** @throws RuntimeException When the temporary workspace cannot be created */
    protected function setUp(): void
    {
        $this->vendor = new VendorDirectory();
        $mutator = new RenamingMutator();
        $this->mimic = new MimicFactory($this->fileDriver($mutator), new StreamDriver($mutator));
    }

    protected function tearDown(): void
    {
        $this->vendor->remove();
    }

    /** @throws Throwable When source preparation fails */
    public function testDefaultDriverGeneratesAProxyWithoutLoadingOrRegisteringSpl(): void
    {
        $source = $this->source();
        $stack = spl_autoload_functions();
        $wrappers = stream_get_wrappers();

        $path = $this->mimic->prepare($source);

        $this->assertSame($this->vendor->path . '/.cache/mimic', dirname($path));
        $this->assertSame('MimicDriverProbe.tpl', basename($path));
        $this->assertFileExists($path);
        $this->assertSame([], glob(dirname($path) . '/*.lock'));
        $this->assertStringContainsString('class PreparedDriverProbe', (string) file_get_contents($path));
        $this->assertFalse(class_exists('MimicDriverProbe', false));
        $this->assertFalse(class_exists('PreparedDriverProbe', false));
        $this->assertSame($stack, spl_autoload_functions());
        $this->assertSame($wrappers, stream_get_wrappers());
    }

    /** @throws Throwable When source preparation fails */
    public function testUnchangedSourceReusesTheProxyAcrossDriverInstancesWithoutWriting(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $this->assertTrue(touch($path, 100));
        $this->assertTrue(touch($source, 200));
        $inode = fileinode($path);
        $code = file_get_contents($path);
        $driver = $this->fileDriver(new RenamingMutator());

        $this->assertSame($path, $driver->prepare(new SourceFile($source)));
        clearstatcache(true, $path);
        $this->assertSame(100, filemtime($path));
        $this->assertSame($inode, fileinode($path));
        $this->assertSame($code, file_get_contents($path));
    }

    /** @throws Throwable When source preparation fails */
    public function testOnlyChangedSourceContentReplacesTheHistoricalProxy(): void
    {
        $source = $this->source();
        $this->assertTrue(touch($source, 200));
        $inode = fileinode($source);
        $path = $this->mimic->prepare($source);
        $before = file_get_contents($path);
        $this->source(2);
        $this->assertTrue(touch($source, 200));
        clearstatcache(true, $source);
        $this->assertSame($inode, fileinode($source));
        $this->assertSame(200, filemtime($source));

        $this->assertSame($path, $this->mimic->prepare($source));
        $this->assertNotSame($before, file_get_contents($path));
        $this->assertStringContainsString('return 2;', (string) file_get_contents($path));
        $this->assertSame([], glob(dirname($path) . '/.mimic-*'));
    }

    /** @throws Throwable When a namespaced source cannot be prepared */
    public function testNamespaceSeparatorsBecomeDotsWithoutCollidingWithUnderscores(): void
    {
        $first = $this->vendor->path . '/namespaced.php';
        $second = $this->vendor->path . '/underscored.php';
        file_put_contents($first, '<?php namespace Acme\\Service; class Test {}');
        file_put_contents($second, '<?php namespace Acme; class Service_Test {}');
        $this->classes = [
            'Acme\\Service\\Test' => $first,
            'Acme\\Service_Test'  => $second,
        ];

        $this->assertSame('Acme.Service.Test.tpl', basename($this->mimic->prepare($first)));
        $this->assertSame('Acme.Service_Test.tpl', basename($this->mimic->prepare($second)));
    }

    /** @throws Throwable When an unmanaged source cannot be transformed */
    public function testSourcesOutsideThePackageClassGraphUseTheStreamDriver(): void
    {
        $source = $this->source();
        $this->classes = [];

        $path = $this->mimic->prepare($source);

        $this->assertStringStartsWith('mimic://', $path);
        $this->assertStringContainsString('class PreparedDriverProbe', (string) file_get_contents($path));
        $this->assertDirectoryDoesNotExist($this->vendor->path . '/.cache');
    }

    /** @throws Throwable When a driver tries to load a parser or fails to prepare source */
    public function testBothDriversWorkWithAParserFreeTransformation(): void
    {
        $blockParser = static function (string $class): void {
            if (str_starts_with($class, 'PhpParser\\')) {
                throw new RuntimeException('A source driver must not require PHP-Parser.');
            }
        };
        spl_autoload_register($blockParser, true, true);

        try {
            $source = new SourceFile($this->source());
            $mutator = new class() implements MutatorInterface {
                public function getCacheKey(): string
                {
                    return 'parser-free-test';
                }

                public function mutate(string $code, ?string $sourcePath = null): string
                {
                    return $code;
                }
            };
            $file = $this->fileDriver($mutator)->prepare($source);
            $stream = new StreamDriver($mutator)->prepare($source);

            $this->assertNotNull($file);
            $this->assertStringContainsString('class MimicDriverProbe', (string) file_get_contents($file));
            $this->assertSame($source->code, file_get_contents($stream));
        } finally {
            spl_autoload_unregister($blockParser);
        }
    }

    /** @throws Throwable When fallback preparation fails */
    public function testDirectoryCreationFailureFallsBackToPreparedStreamSource(): void
    {
        $source = $this->source();
        file_put_contents($this->vendor->path . '/.cache', 'blocked');
        $stack = spl_autoload_functions();

        $path = $this->mimic->prepare($source);

        $this->assertStringStartsWith('mimic://', $path);
        $this->assertStringContainsString('return 1;', (string) file_get_contents($path));
        $this->assertFalse(class_exists('MimicDriverProbe', false));
        $this->assertFalse(class_exists('PreparedDriverProbe', false));
        $this->assertSame($stack, spl_autoload_functions());
    }

    /** @throws Throwable When fallback preparation fails */
    public function testAnUnwritableCacheDirectoryFallsBackWhenCreatingTheFirstProxy(): void
    {
        $source = $this->source();
        $directory = $this->vendor->path . '/.cache/mimic';
        mkdir($directory, 0o700, true);
        $this->makeReadOnly($directory);

        $path = $this->mimic->prepare($source);

        $this->assertStringStartsWith('mimic://', $path);
        $this->assertSame([], glob($directory . '/*.tpl'));
    }

    /** @throws Throwable When proxy or fallback preparation fails */
    public function testFailedHistoricalReplacementPreservesTheOldProxyAndUsesCurrentSource(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $historical = file_get_contents($path);
        $directory = dirname($path);
        $this->makeReadOnly($directory);
        $this->assertSame($path, $this->mimic->prepare($source));
        $this->source(2);

        $fallback = $this->mimic->prepare($source);

        $this->assertStringStartsWith('mimic://', $fallback);
        $this->assertSame($historical, file_get_contents($path));
        $this->assertStringContainsString('return 2;', (string) file_get_contents($fallback));
        chmod($directory, 0o700);
        $this->assertSame($path, $this->mimic->prepare($source));
        $this->assertStringContainsString('return 2;', (string) file_get_contents($path));
        $this->assertSame([], glob($directory . '/.mimic-*'));
    }

    /** @throws Throwable When proxy or fallback preparation fails */
    public function testHistoricalLockSidecarsDoNotPreventPublication(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $historical = file_get_contents($path);
        mkdir($path . '.lock');
        $this->source(2);

        $this->assertSame($path, $this->mimic->prepare($source));
        $this->assertNotSame($historical, file_get_contents($path));
        $this->assertStringContainsString('return 2;', (string) file_get_contents($path));
    }

    /** @throws Throwable When proxy or fallback preparation fails */
    public function testAnUnverifiableCacheIsPreservedInsteadOfOverwritten(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $unrecognized = '<?php /* unrecognized proxy header */';
        file_put_contents($path, $unrecognized);

        $fallback = $this->mimic->prepare($source);

        $this->assertStringStartsWith('mimic://', $fallback);
        $this->assertSame($unrecognized, file_get_contents($path));
    }

    /** @throws Throwable When proxy preparation fails */
    public function testSameBasenameInDifferentDirectoriesDoesNotShareAProxy(): void
    {
        $first = $this->source();
        mkdir($this->vendor->path . '/other');
        $second = $this->vendor->path . '/other/source.php';
        file_put_contents($second, '<?php class OtherProbe {}');
        $this->classes['OtherProbe'] = $second;

        $this->assertNotSame($this->mimic->prepare($first), $this->mimic->prepare($second));
    }

    /** @throws Throwable When a proxy cannot be prepared */
    public function testChangedMutatorKeysReplaceTheSameProxyForUnchangedSource(): void
    {
        $source = new SourceFile($this->source());
        $first = $this->fileDriver(new RenamingMutator('FirstDriverProbe'));
        $second = $this->fileDriver(new RenamingMutator('SecondDriverProbe'));
        $path = $first->prepare($source);
        $this->assertNotNull($path);
        $firstCode = file_get_contents($path);
        $this->assertStringContainsString('class FirstDriverProbe', (string) $firstCode);

        $this->assertSame($path, $second->prepare($source));
        $this->assertStringContainsString('class SecondDriverProbe', (string) file_get_contents($path));
        $this->assertSame($path, $first->prepare($source));
        $this->assertSame($firstCode, file_get_contents($path));
        $this->assertSame([
            $path,
        ], glob(dirname($path) . '/*'));
    }

    /** @throws Throwable When a source-path-dependent proxy cannot be prepared */
    public function testRelocatingUnchangedSourceRegeneratesTheSameClassProxy(): void
    {
        $first = $this->vendor->path . '/original.php';
        $second = $this->vendor->path . '/relocated.php';
        file_put_contents($first, '<?php class RelocatedProbe { public const string PATH = __FILE__; }');
        $this->classes['RelocatedProbe'] = $first;
        $mutator = new class() implements MutatorInterface {
            public function getCacheKey(): string
            {
                return 'original-file-constant';
            }

            public function mutate(string $code, ?string $sourcePath = null): string
            {
                return str_replace('__FILE__', var_export($sourcePath, true), $code);
            }
        };
        $driver = $this->fileDriver($mutator);
        $source = new SourceFile($first);
        $path = $driver->prepare($source);
        $this->assertNotNull($path);
        $this->assertStringContainsString(var_export($first, true), (string) file_get_contents($path));
        $this->assertTrue(rename($first, $second));
        $this->classes['RelocatedProbe'] = $second;
        $relocated = new SourceFile($second);
        $this->assertSame($source->hash, $relocated->hash);

        $this->assertSame($path, $driver->prepare($relocated));
        $this->assertStringContainsString(var_export($second, true), (string) file_get_contents($path));
        $this->assertStringNotContainsString(var_export($first, true), (string) file_get_contents($path));
    }

    /** @throws Throwable When a stream cannot be prepared */
    public function testDifferentMutatorsKeepIndependentStreamSnapshots(): void
    {
        $source = new SourceFile($this->source());
        $first = new StreamDriver(new RenamingMutator('FirstDriverProbe'))->prepare($source);
        $second = new StreamDriver(new RenamingMutator('SecondDriverProbe'))->prepare($source);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('class FirstDriverProbe', (string) file_get_contents($first));
        $this->assertStringContainsString('class SecondDriverProbe', (string) file_get_contents($second));
    }

    /** @throws Throwable When the replacement proxy cannot be prepared */
    public function testLegacySourceOnlyCachesAreNeverReusedOrOverwritten(): void
    {
        $source = new SourceFile($this->source());
        $directory = $this->vendor->path . '/.cache/mimic';
        mkdir($directory, 0o700, true);
        $legacyPath = $directory . '/' . hash('sha256', $source->path) . '.php';
        $legacy = '<?php // mimic-source-sha256: ' . $source->hash . "\n"
            . '/* historical interception runtime */';
        file_put_contents($legacyPath, $legacy);

        $path = $this->mimic->prepare($source->path);

        $this->assertNotSame($legacyPath, $path);
        $this->assertSame($legacy, file_get_contents($legacyPath));
        $this->assertStringContainsString('class PreparedDriverProbe', (string) file_get_contents($path));
    }

    /** @throws Throwable When the flat proxy cannot be prepared */
    public function testLegacyIdentityDirectoriesAreIgnored(): void
    {
        $source = new SourceFile($this->source());
        $directory = $this->vendor->path . '/.cache/mimic';
        $legacyDirectory = $directory . '/' . hash('sha256', $source->path . "\0" . 'rename:PreparedDriverProbe');
        mkdir($legacyDirectory, 0o700, true);
        $legacyPath = $legacyDirectory . '/MimicDriverProbe.tpl';
        $legacy = '<?php // mimic-source-sha256: ' . $source->hash . "\n"
            . '/* historical nested proxy */';
        file_put_contents($legacyPath, $legacy);

        $path = $this->mimic->prepare($source->path);

        $this->assertSame($directory . '/MimicDriverProbe.tpl', $path);
        $this->assertSame($legacy, file_get_contents($legacyPath));
        $this->assertStringContainsString('class PreparedDriverProbe', (string) file_get_contents($path));
    }

    /** @throws Throwable When proxy preparation or loading fails */
    public function testLegacyDoubleSuffixIsIgnoredAndTheSingleSuffixProxyIsLoadedAndReused(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $legacyPath = dirname($path) . '/MimicDriverProbe.php.tpl';
        $this->assertTrue(rename($path, $legacyPath));
        $legacy = file_get_contents($legacyPath);

        $prepared = $this->fileDriver(new RenamingMutator())->prepare(new SourceFile($source));

        $this->assertSame($path, $prepared);
        $this->assertSame('MimicDriverProbe.tpl', basename($prepared));
        $this->assertFileExists($prepared);
        $this->assertSame($legacy, file_get_contents($legacyPath));
        $this->assertTrue(touch($prepared, 100));
        $this->assertSame($prepared, $this->mimic->prepare($source));
        clearstatcache(true, $prepared);
        $this->assertSame(100, filemtime($prepared));

        require $prepared;

        $class = 'PreparedDriverProbe';
        $this->assertSame(1, new $class()->value());
    }

    /** @throws Throwable When stream preparation fails */
    public function testStreamsKeepThePreparedSourceSnapshotWhenTheOriginalChanges(): void
    {
        $source = $this->source();
        $driver = new StreamDriver(new RenamingMutator());
        $first = $driver->prepare(new SourceFile($source));
        $this->source(2);
        $second = $driver->prepare(new SourceFile($source));

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('return 1;', (string) file_get_contents($first));
        $this->assertStringContainsString('return 2;', (string) file_get_contents($second));
        $this->assertFalse(is_dir($this->vendor->path . '/.cache'));
    }

    /** @throws Throwable When source preparation fails */
    public function testFileDriverDoesNotNeedAnAvailableStreamProtocol(): void
    {
        $source = $this->source();
        $this->assertTrue(stream_wrapper_register('mimic', StreamWrapper::class));
        $this->assertFileExists($this->mimic->prepare($source));
        $this->expectException(RuntimeException::class);

        new StreamDriver(new RenamingMutator())->prepare(new SourceFile($source));
    }

    /** @throws Throwable When the missing source is rejected */
    public function testMissingSourceFailsInsteadOfReturningUntransformedSource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->mimic->prepare($this->vendor->path . '/missing.php');
    }

    /** @throws Throwable When the invalid source is rejected */
    public function testParseFailureLeavesTheHistoricalProxyIntact(): void
    {
        $source = $this->source();
        $path = $this->mimic->prepare($source);
        $historical = file_get_contents($path);
        file_put_contents($source, '<?php class {');

        try {
            $this->mimic->prepare($source);
            $this->fail('Invalid source must not be loaded from an older proxy.');
        } catch (Error) {
            $this->assertSame($historical, file_get_contents($path));
            $this->assertSame([], glob(dirname($path) . '/.mimic-*'));
        }
    }

    /** @return non-empty-string */
    private function source(int $value = 1): string
    {
        $path = $this->vendor->path . '/source.php';
        file_put_contents($path, "<?php class MimicDriverProbe { public function value(): int { return $value; } }");
        $this->classes['MimicDriverProbe'] = $path;

        return $path;
    }

    private function fileDriver(MutatorInterface $mutator): FileDriver
    {
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('allClasses')->willReturnCallback(fn(): array => $this->classes);

        return new FileDriver($this->vendor, $mutator, $packages);
    }

    private function makeReadOnly(string $directory): void
    {
        chmod($directory, 0o555);
        clearstatcache(true, $directory);

        if (is_writable($directory)) {
            $this->markTestSkipped('This process bypasses filesystem permission restrictions.');
        }
    }
}
