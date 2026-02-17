<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\BcBreak;
use Pulsar\Api\BcBreakDetector;
use Pulsar\Api\BcBreakSeverity;
use Pulsar\Api\BcBreakType;

#[CoversClass(BcBreakDetector::class)]
#[CoversClass(BcBreak::class)]
#[CoversClass(BcBreakType::class)]
#[CoversClass(BcBreakSeverity::class)]
final class BcBreakDetectorTest extends TestCase
{
    private BcBreakDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new BcBreakDetector();
    }

    public function testNoBreaksWhenSnapshotsIdentical(): void
    {
        $snapshot = [
            'App\\MyClass' => [
                'stability' => 'stable',
                'methods' => [
                    'doThing' => ['signature' => 'string $name', 'stability' => 'stable'],
                ],
            ],
        ];

        $breaks = $this->detector->detect($snapshot, $snapshot);
        self::assertSame([], $breaks);
    }

    public function testDetectsRemovedClass(): void
    {
        $previous = [
            'App\\RemovedClass' => ['stability' => 'stable', 'methods' => []],
        ];

        $breaks = $this->detector->detect($previous, []);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::ClassRemoved, $breaks[0]->type);
        self::assertSame('App\\RemovedClass', $breaks[0]->symbol);
        self::assertSame(BcBreakSeverity::Error, $breaks[0]->severity);
    }

    public function testDetectsRemovedMethod(): void
    {
        $previous = [
            'App\\MyClass' => [
                'stability' => 'stable',
                'methods' => [
                    'oldMethod' => ['signature' => '', 'stability' => 'stable'],
                    'keepMethod' => ['signature' => '', 'stability' => 'stable'],
                ],
            ],
        ];

        $current = [
            'App\\MyClass' => [
                'stability' => 'stable',
                'methods' => [
                    'keepMethod' => ['signature' => '', 'stability' => 'stable'],
                ],
            ],
        ];

        $breaks = $this->detector->detect($previous, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::MethodRemoved, $breaks[0]->type);
        self::assertStringContainsString('oldMethod', $breaks[0]->symbol);
    }

    public function testDetectsChangedSignature(): void
    {
        $previous = [
            'App\\MyClass' => [
                'methods' => [
                    'doThing' => ['signature' => 'string $name', 'stability' => 'stable'],
                ],
            ],
        ];

        $current = [
            'App\\MyClass' => [
                'methods' => [
                    'doThing' => ['signature' => 'string $name, int $count', 'stability' => 'stable'],
                ],
            ],
        ];

        $breaks = $this->detector->detect($previous, $current);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakType::SignatureChanged, $breaks[0]->type);
    }

    public function testExperimentalBreaksAreWarnings(): void
    {
        $previous = [
            'App\\ExperimentalClass' => ['stability' => 'experimental', 'methods' => []],
        ];

        $breaks = $this->detector->detect($previous, []);

        self::assertCount(1, $breaks);
        self::assertSame(BcBreakSeverity::Warning, $breaks[0]->severity);
        self::assertSame('experimental', $breaks[0]->stability);
    }

    public function testHasBlockingBreaks(): void
    {
        self::assertFalse($this->detector->hasBlockingBreaks([]));

        $warningOnly = [
            new BcBreak(
                type: BcBreakType::ClassRemoved,
                symbol: 'X',
                message: 'X removed',
                severity: BcBreakSeverity::Warning,
                stability: 'experimental',
            ),
        ];

        self::assertFalse($this->detector->hasBlockingBreaks($warningOnly));

        $withError = [
            new BcBreak(
                type: BcBreakType::MethodRemoved,
                symbol: 'Y::z',
                message: 'Y::z removed',
                severity: BcBreakSeverity::Error,
                stability: 'stable',
            ),
        ];

        self::assertTrue($this->detector->hasBlockingBreaks($withError));
    }

    public function testNewClassesAreNotBreaks(): void
    {
        $previous = [];
        $current = [
            'App\\NewClass' => ['stability' => 'stable', 'methods' => []],
        ];

        $breaks = $this->detector->detect($previous, $current);
        self::assertSame([], $breaks);
    }

    public function testNewMethodsAreNotBreaks(): void
    {
        $previous = [
            'App\\MyClass' => ['methods' => []],
        ];

        $current = [
            'App\\MyClass' => [
                'methods' => [
                    'newMethod' => ['signature' => '', 'stability' => 'stable'],
                ],
            ],
        ];

        $breaks = $this->detector->detect($previous, $current);
        self::assertSame([], $breaks);
    }
}
