<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Broadcasting\ExcludesConnectionInterface;

/**
 * Tests the ExcludesConnectionInterface contract via anonymous implementation.
 */
final class ExcludesConnectionInterfaceTest extends TestCase
{
    #[Test]
    public function implementationReturnsConnectionIdToExclude(): void
    {
        // Arrange
        $impl = $this->createImplementation('conn-abc-123');

        // Act & Assert
        self::assertSame('conn-abc-123', $impl->excludeConnectionId());
    }

    #[Test]
    public function implementationReturnsNullWhenNoExclusion(): void
    {
        // Arrange
        $impl = $this->createImplementation(null);

        // Act & Assert
        self::assertNull($impl->excludeConnectionId());
    }

    #[Test]
    public function stubSatisfiesInterfaceContract(): void
    {
        // Arrange
        $stub = $this->createStub(ExcludesConnectionInterface::class);
        $stub->method('excludeConnectionId')->willReturn('ws-99');

        // Act & Assert
        self::assertSame('ws-99', $stub->excludeConnectionId());
    }

    #[Test]
    public function stubReturnsNullByDefault(): void
    {
        // Arrange
        $stub = $this->createStub(ExcludesConnectionInterface::class);
        $stub->method('excludeConnectionId')->willReturn(null);

        // Act & Assert
        self::assertNull($stub->excludeConnectionId());
    }

    #[Test]
    public function implementationCanReturnEmptyStringConnectionId(): void
    {
        // Arrange
        $impl = $this->createImplementation('');

        // Act & Assert
        self::assertSame('', $impl->excludeConnectionId());
    }

    private function createImplementation(?string $connectionId): ExcludesConnectionInterface
    {
        return new class ($connectionId) implements ExcludesConnectionInterface {
            public function __construct(
                private readonly ?string $connectionId,
            ) {}

            public function excludeConnectionId(): ?string
            {
                return $this->connectionId;
            }
        };
    }
}
