<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Deprecated;
use Pulsar\Api\DeprecationReporter;

#[CoversClass(DeprecationReporter::class)]
final class DeprecationReporterTest extends TestCase
{
    protected function setUp(): void
    {
        DeprecationReporter::reset();
    }

    protected function tearDown(): void
    {
        DeprecationReporter::reset();
    }

    // ── fromSnapshot ────────────────────────────────────────────────────

    #[Test]
    public function fromSnapshotExtractsDeprecations(): void
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = [
            'App\\OldClass' => [
                'name' => 'App\\OldClass',
                'deprecated' => [
                    'since' => '1.1',
                    'removeIn' => '2.0',
                    'replacement' => 'App\\NewClass',
                ],
            ],
            'App\\ActiveClass' => [
                'name' => 'App\\ActiveClass',
            ],
        ];

        $reports = DeprecationReporter::fromSnapshot($snapshot);

        self::assertCount(1, $reports);
        self::assertSame('App\\OldClass', $reports[0]['symbol']);
        self::assertSame('1.1', $reports[0]['since']);
        self::assertSame('2.0', $reports[0]['removeIn']);
        self::assertSame('App\\NewClass', $reports[0]['replacement']);
    }

    #[Test]
    public function fromSnapshotHandlesEmptySnapshot(): void
    {
        $reports = DeprecationReporter::fromSnapshot([]);
        self::assertSame([], $reports);
    }

    #[Test]
    public function fromSnapshotSkipsNonDeprecated(): void
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = [
            'App\\Normal' => ['name' => 'App\\Normal'],
            'invalid' => 'not-an-array',
        ];

        $reports = DeprecationReporter::fromSnapshot($snapshot);
        self::assertSame([], $reports);
    }

    #[Test]
    public function fromSnapshotDefaultsForMissingFields(): void
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = [
            'x' => [
                'deprecated' => [
                    // All fields missing except 'deprecated' key
                ],
            ],
        ];

        $reports = DeprecationReporter::fromSnapshot($snapshot);

        self::assertCount(1, $reports);
        self::assertSame('unknown', $reports[0]['symbol']);
        self::assertSame('', $reports[0]['since']);
        self::assertSame('', $reports[0]['removeIn']);
        self::assertSame('', $reports[0]['replacement']);
    }

    // ── checkClass ──────────────────────────────────────────────────────

    #[Test]
    public function checkClassTriggersDeprecationForAnnotatedClass(): void
    {
        $triggered = false;
        set_error_handler(static function (int $errno, string $errstr) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }

            return true;
        });

        try {
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($triggered, 'Expected E_USER_DEPRECATED to be triggered');
    }

    #[Test]
    public function checkClassDoesNotTriggerForNonAnnotatedClass(): void
    {
        $triggered = false;
        set_error_handler(static function (int $errno) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }

            return true;
        });

        try {
            DeprecationReporter::checkClass(NonDeprecatedFixtureClass::class);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($triggered, 'Should not trigger E_USER_DEPRECATED for non-annotated class');
    }

    #[Test]
    public function checkClassOnlyTriggersOnce(): void
    {
        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_DEPRECATED) {
                $count++;
            }

            return true;
        });

        try {
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $count, 'Deprecation should only trigger once per class');
    }

    // ── checkMethod ─────────────────────────────────────────────────────

    #[Test]
    public function checkMethodTriggersDeprecationForAnnotatedMethod(): void
    {
        $triggered = false;
        set_error_handler(static function (int $errno) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }

            return true;
        });

        try {
            DeprecationReporter::checkMethod(MethodDeprecationFixture::class, 'oldMethod');
        } finally {
            restore_error_handler();
        }

        self::assertTrue($triggered, 'Expected E_USER_DEPRECATED for annotated method');
    }

    #[Test]
    public function checkMethodDoesNotTriggerForNonAnnotatedMethod(): void
    {
        $triggered = false;
        set_error_handler(static function (int $errno) use (&$triggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $triggered = true;
            }

            return true;
        });

        try {
            DeprecationReporter::checkMethod(MethodDeprecationFixture::class, 'currentMethod');
        } finally {
            restore_error_handler();
        }

        self::assertFalse($triggered, 'Should not trigger for non-annotated method');
    }

    #[Test]
    public function checkMethodOnlyTriggersOncePerMethodKey(): void
    {
        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_DEPRECATED) {
                $count++;
            }

            return true;
        });

        try {
            DeprecationReporter::checkMethod(MethodDeprecationFixture::class, 'oldMethod');
            DeprecationReporter::checkMethod(MethodDeprecationFixture::class, 'oldMethod');
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $count, 'Method deprecation should only trigger once');
    }

    // ── reset ───────────────────────────────────────────────────────────

    #[Test]
    public function resetAllowsReReporting(): void
    {
        $count = 0;
        set_error_handler(static function (int $errno) use (&$count): bool {
            if ($errno === E_USER_DEPRECATED) {
                $count++;
            }

            return true;
        });

        try {
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
            DeprecationReporter::reset();
            DeprecationReporter::checkClass(DeprecatedFixtureClass::class);
        } finally {
            restore_error_handler();
        }

        self::assertSame(2, $count, 'Reset should allow re-reporting');
    }
}

// ── Test fixtures ───────────────────────────────────────────────────────

/**
 * @internal Test fixture only
 */
#[Deprecated(since: '1.0', removeIn: '2.0', replacement: 'NewFixtureClass')]
final class DeprecatedFixtureClass {}

/**
 * @internal Test fixture only
 */
final class NonDeprecatedFixtureClass {}

/**
 * @internal Test fixture only
 */
final class MethodDeprecationFixture
{
    #[Deprecated(since: '1.0', replacement: 'newMethod()')]
    public function oldMethod(): void {}

    public function currentMethod(): void {}
}
