<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use SuperKernel\Mimic\Tests\Fixture\RenamingMutator;

use function spl_autoload_functions;
use function stream_get_wrappers;
use function sys_get_temp_dir;

final class MimicFactoryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructionDoesNotRegisterAnAutoloaderOrStreamProtocol(): void
    {
        $before = spl_autoload_functions();

        $wrappers = stream_get_wrappers();
        $vendor = $this->createStub(VendorDirInterface::class);
        $vendor->method('__toString')->willReturn(sys_get_temp_dir());
        $mutator = new RenamingMutator();
        $packages = $this->createMock(PackageManagerInterface::class);
        $packages->expects($this->never())->method('allClasses');
        $this->assertInstanceOf(
            MimicFactory::class,
            new MimicFactory(new FileDriver($vendor, $mutator, $packages), new StreamDriver($mutator)),
        );

        $this->assertSame($wrappers, stream_get_wrappers());

        $this->assertSame($before, spl_autoload_functions());
    }
}
