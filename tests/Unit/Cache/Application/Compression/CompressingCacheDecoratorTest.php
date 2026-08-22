<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Compression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Compression\CompressingCacheDecorator;
use Pulsar\Cache\Application\Driver\ArrayDriver;

use function gzcompress;
use function str_repeat;
use function str_starts_with;
use function strlen;

#[CoversClass(CompressingCacheDecorator::class)]
final class CompressingCacheDecoratorTest extends TestCase
{
    private const string MAGIC = "\xF0PC1";

    private ArrayDriver $inner;
    private CompressingCacheDecorator $decorator;

    protected function setUp(): void
    {
        $this->inner = new ArrayDriver();
        // zlib ships with effectively every PHP build, so the zlib algorithm
        // keeps this test runnable everywhere.
        $this->decorator = new CompressingCacheDecorator(
            inner: $this->inner,
            algorithm: 'zlib',
            thresholdBytes: 64,
        );
    }

    #[Test]
    public function largeValuesAreCompressedAtRestAndRoundTrip(): void
    {
        $value = str_repeat('pulsar cache layer ', 200);

        self::assertTrue($this->decorator->set('big', $value, null));

        $stored = $this->inner->get('big');
        self::assertIsString($stored);
        self::assertTrue(str_starts_with($stored, self::MAGIC), 'Stored form must carry the envelope magic');
        self::assertLessThan(strlen($value), strlen($stored), 'Stored form must actually be smaller');

        self::assertSame($value, $this->decorator->get('big'));
    }

    #[Test]
    public function theConfiguredLevelReachesTheCodec(): void
    {
        // The defect this pins is an INVISIBLE default: compress() used to call
        // the codec with no level, silently inheriting whatever the extension
        // chose. Prove the level is both honoured and observable by checking
        // that two levels produce different stored bytes, and that each matches
        // the codec called directly at that level.
        $value = str_repeat('pulsar cache layer ', 200);

        $fast = new CompressingCacheDecorator($cheap = new ArrayDriver(), 'zlib', 64, level: 1);
        $best = new CompressingCacheDecorator($dear = new ArrayDriver(), 'zlib', 64, level: 9);

        $fast->set('k', $value, null);
        $best->set('k', $value, null);

        $fastStored = (string) $cheap->get('k');
        $bestStored = (string) $dear->get('k');

        self::assertNotSame($fastStored, $bestStored, 'Different levels must produce different bytes');
        self::assertSame(self::MAGIC . "\x01" . gzcompress($value, 1), $fastStored);
        self::assertSame(self::MAGIC . "\x01" . gzcompress($value, 9), $bestStored);

        // Both must still decode: the level is a write-side choice only.
        self::assertSame($value, $fast->get('k'));
        self::assertSame($value, $best->get('k'));
    }

    #[Test]
    public function anAbsentLevelUsesTheCodecDocumentedDefaultNotAnInheritedOne(): void
    {
        $value = str_repeat('pulsar cache layer ', 200);

        $this->decorator->set('k', $value, null);

        // zlib's documented default is 6; assert it explicitly rather than
        // trusting gzcompress()'s -1 sentinel to keep resolving there.
        self::assertSame(self::MAGIC . "\x01" . gzcompress($value, 6), (string) $this->inner->get('k'));
    }

    #[Test]
    public function smallValuesAreStoredRawAndRoundTrip(): void
    {
        $this->decorator->set('small', 'tiny', null);

        self::assertSame('tiny', $this->inner->get('small'), 'Below-threshold values must be stored untouched');
        self::assertSame('tiny', $this->decorator->get('small'));
    }

    #[Test]
    public function legacyEntriesWrittenWithoutCompressionAreReadAsIs(): void
    {
        // An entry written before compression was enabled has no envelope;
        // enabling compression must not invalidate it.
        $this->inner->set('legacy', '{"json":"payload"}', null);

        self::assertSame('{"json":"payload"}', $this->decorator->get('legacy'));
    }

    #[Test]
    public function aRawValueStartingWithTheMagicIsEscapedInjectively(): void
    {
        // A (pathological) value that begins with the envelope magic must not
        // be misparsed as an envelope on read.
        $value = self::MAGIC . 'not actually an envelope';

        $this->decorator->set('tricky', $value, null);

        self::assertSame($value, $this->decorator->get('tricky'));
    }

    #[Test]
    public function incompressibleLargeValuesAreStoredRaw(): void
    {
        // High-entropy payload longer than the threshold: compressing it would
        // grow it, so the decorator must fall back to raw storage.
        $value = random_bytes(256);

        $this->decorator->set('entropy', $value, null);

        self::assertSame($value, $this->inner->get('entropy'));
        self::assertSame($value, $this->decorator->get('entropy'));
    }

    #[Test]
    public function countersBypassCompressionEntirely(): void
    {
        self::assertSame(3, $this->decorator->increment('counter', 3));
        self::assertSame(5, $this->decorator->increment('counter', 2));
        self::assertSame('5', $this->decorator->get('counter'), 'Counter values pass through untransformed');
    }

    #[Test]
    public function addCompressesAndPreservesFirstWriterWins(): void
    {
        $value = str_repeat('winner ', 100);

        self::assertTrue($this->decorator->add('claim', $value, null));
        self::assertFalse($this->decorator->add('claim', 'second', null));
        self::assertSame($value, $this->decorator->get('claim'));
    }

    #[Test]
    public function getMultipleDecodesMixedEntries(): void
    {
        $big = str_repeat('compressed entry ', 100);
        $this->decorator->set('big', $big, null);
        $this->decorator->set('small', 'raw', null);
        $this->inner->set('legacy', 'pre-existing', null);

        $result = $this->decorator->getMultiple(['big', 'small', 'legacy', 'missing']);

        self::assertSame($big, $result['big']);
        self::assertSame('raw', $result['small']);
        self::assertSame('pre-existing', $result['legacy']);
        self::assertNull($result['missing']);
    }

    #[Test]
    public function capabilitiesAndNamePassThrough(): void
    {
        self::assertSame($this->inner->capabilities()->supportsAtomicIncrement, $this->decorator->capabilities()->supportsAtomicIncrement);
        self::assertSame('compressed:array', $this->decorator->name());
    }
}
