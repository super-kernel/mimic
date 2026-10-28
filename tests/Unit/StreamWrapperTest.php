<?php

declare(strict_types=1);

namespace SuperKernel\Mimic\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperKernel\Mimic\StreamWrapper;

use function strlen;

use const SEEK_CUR;
use const SEEK_END;

final class StreamWrapperTest extends TestCase
{
    public function testReadSeekStatAndMissingPathsFollowTheReadOnlyStreamContract(): void
    {
        StreamWrapper::publish('mimic://StreamProbe', '<?php class StreamProbe {}');
        $stream = new StreamWrapper();
        $openedPath = null;
        $this->assertFalse($stream->stream_open('mimic://UnregisteredProbe', 'rb', 0, $openedPath));
        $this->assertFalse($stream->stream_open('mimic://StreamProbe', 'wb', 0, $openedPath));
        $this->assertTrue($stream->stream_open('mimic://StreamProbe', 'rb', 0, $openedPath));
        $this->assertSame('mimic://StreamProbe', $openedPath);
        $this->assertSame('<?php', $stream->stream_read(5));
        $this->assertSame(5, $stream->stream_tell());
        $this->assertTrue($stream->stream_seek(-5, SEEK_CUR));
        $this->assertSame('<?php', $stream->stream_read(5));
        $remaining = $stream->stream_read($stream->stream_stat()['size']);
        $this->assertSame($stream->stream_stat()['size'], 5 + strlen($remaining));
        $this->assertTrue($stream->stream_eof());
        $this->assertFalse($stream->stream_seek(-1));
        $this->assertTrue($stream->stream_seek(0, SEEK_END));
        $this->assertFalse($stream->stream_set_option(0, 0, null));
        $this->assertFalse($stream->url_stat('mimic://UnregisteredProbe', 0));
        $this->assertIsArray($stream->url_stat('mimic://StreamProbe', 0));
        $stream->stream_close();
        $this->assertSame(0, $stream->stream_tell());
    }
}
