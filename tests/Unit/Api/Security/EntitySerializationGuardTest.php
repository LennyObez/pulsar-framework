<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Security\EntitySerializationGuard;
use stdClass;
use Stringable;

#[CoversClass(EntitySerializationGuard::class)]
final class EntitySerializationGuardTest extends TestCase
{
    private const array FIXTURE_PATTERNS = ['Pulsar\\Tests\\Unit\\Api\\Security\\Fixtures\\'];

    #[Test]
    public function nonObjectValuesReturnFalse(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: true,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
        );

        self::assertFalse($guard->check('string', 'corr-001'));
        self::assertFalse($guard->check(42, 'corr-001'));
        self::assertFalse($guard->check(null, 'corr-001'));
        self::assertFalse($guard->check(['array'], 'corr-001'));
        self::assertFalse($guard->check(true, 'corr-001'));
    }

    #[Test]
    public function nonEntityObjectReturnsFalse(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: true,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
        );

        self::assertFalse($guard->check(new stdClass(), 'corr-001'));
    }

    #[Test]
    public function entityInDebugModeThrows(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: true,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
        );

        $entity = new Fixtures\FakeEntity();

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(500);
        $this->expectExceptionMessageMatches('/returned directly from controller/');

        $guard->check($entity, 'corr-001');
    }

    #[Test]
    public function entityInProdModeReturnsTrueWithoutThrow(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: false,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
        );

        $entity = new Fixtures\FakeEntity();

        self::assertTrue($guard->check($entity, 'corr-001'));
    }

    #[Test]
    public function entityDetectionMatchesNamespacePrefix(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: false,
            entityNamespacePatterns: [
                'App\\Domain\\',
                'Pulsar\\Tests\\Unit\\Api\\Security\\Fixtures\\',
            ],
        );

        $entity = new Fixtures\FakeEntity();

        self::assertTrue($guard->check($entity, 'corr-001'));
    }

    #[Test]
    public function unrelatedObjectDoesNotMatchPattern(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: true,
            entityNamespacePatterns: ['App\\Domain\\'],
        );

        // FakeEntity is not in App\Domain\ namespace
        self::assertFalse($guard->check(new Fixtures\FakeEntity(), 'corr-001'));
    }

    #[Test]
    public function correlationIdIncludedInExceptionMessage(): void
    {
        $guard = new EntitySerializationGuard(
            debugMode: true,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
        );

        try {
            $guard->check(new Fixtures\FakeEntity(), 'request-correlation-xyz');
            self::fail('Expected ApiException to be thrown');
        } catch (ApiException $e) {
            self::assertStringContainsString('request-correlation-xyz', $e->getMessage());
            self::assertStringContainsString('FakeEntity', $e->getMessage());
        }
    }

    #[Test]
    public function loggerCalledOnEntityDetection(): void
    {
        /** @var list<string> $logMessages */
        $logMessages = [];
        $logger = new class ($logMessages) implements LoggerInterface {
            /** @param list<string> $messages */
            public function __construct(public array &$messages) {}

            public function emergency(Stringable|string $message, array $context = []): void {}
            public function alert(Stringable|string $message, array $context = []): void {}
            public function critical(Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
            public function error(Stringable|string $message, array $context = []): void {}
            public function warning(Stringable|string $message, array $context = []): void {}
            public function notice(Stringable|string $message, array $context = []): void {}
            public function info(Stringable|string $message, array $context = []): void {}
            public function debug(Stringable|string $message, array $context = []): void {}
            public function log(mixed $level, Stringable|string $message, array $context = []): void {}
        };

        $guard = new EntitySerializationGuard(
            debugMode: false,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
            logger: $logger,
        );

        $guard->check(new Fixtures\FakeEntity(), 'corr-001');

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('FakeEntity', $logMessages[0]);
        self::assertStringContainsString('corr-001', $logMessages[0]);
    }

    #[Test]
    public function noAuditOrLogWhenNotEntity(): void
    {
        /** @var list<string> $logMessages */
        $logMessages = [];
        $logger = new class ($logMessages) implements LoggerInterface {
            /** @param list<string> $messages */
            public function __construct(public array &$messages) {}

            public function emergency(Stringable|string $message, array $context = []): void {}
            public function alert(Stringable|string $message, array $context = []): void {}
            public function critical(Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
            public function error(Stringable|string $message, array $context = []): void {}
            public function warning(Stringable|string $message, array $context = []): void {}
            public function notice(Stringable|string $message, array $context = []): void {}
            public function info(Stringable|string $message, array $context = []): void {}
            public function debug(Stringable|string $message, array $context = []): void {}
            public function log(mixed $level, Stringable|string $message, array $context = []): void {}
        };

        $guard = new EntitySerializationGuard(
            debugMode: false,
            entityNamespacePatterns: self::FIXTURE_PATTERNS,
            logger: $logger,
        );

        $guard->check(new stdClass(), 'corr-001');

        self::assertCount(0, $logMessages);
    }
}
