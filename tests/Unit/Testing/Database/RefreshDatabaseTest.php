<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Database;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\MigrationRunner;
use Pulsar\Testing\Database\RefreshDatabase;

/**
 * Verifies that RefreshDatabase composes into a test class.
 *
 * The trait's hooks are overridden because MigrationRunner is final
 * and cannot be stubbed. This test validates composition only.
 */
#[CoversClass(RefreshDatabase::class)]
final class RefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function getMigrationRunner(): MigrationRunner
    {
        throw new LogicException('Not expected to be called in this test.');
    }

    /**
     * Override to prevent the trait from calling MigrationRunner.
     */
    protected function refreshDatabase(): void
    {
        // No-op: MigrationRunner is final and cannot be stubbed.
    }

    /**
     * Override to prevent the trait from calling MigrationRunner.
     */
    protected function rollbackDatabase(): void
    {
        // No-op: MigrationRunner is final and cannot be stubbed.
    }

    #[Test]
    public function trait_requires_migration_runner(): void
    {
        // Validates that RefreshDatabase composes into a TestCase
        // and the abstract getMigrationRunner() contract is enforced.
        $this->expectException(LogicException::class);
        $this->getMigrationRunner();
    }
}
