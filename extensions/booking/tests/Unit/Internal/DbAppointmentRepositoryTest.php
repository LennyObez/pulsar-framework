<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Booking\Contracts\AppointmentRepositoryInterface;
use Pulsar\Extension\Booking\Internal\DbAppointmentRepository;

/**
 * Tests for DbAppointmentRepository.
 *
 * Note: The find/save methods iterate over Result objects as raw arrays,
 * which limits unit-level stubbing. The delete method and constructor
 * wiring are tested here. Full CRUD coverage requires an integration test
 * with a real SQLite connection.
 */
#[CoversClass(DbAppointmentRepository::class)]
final class DbAppointmentRepositoryTest extends TestCase
{
    #[Test]
    public function implementsAppointmentRepositoryInterface(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $repo = new DbAppointmentRepository($connection);

        self::assertInstanceOf(AppointmentRepositoryInterface::class, $repo);
    }

    #[Test]
    public function deleteExecutesDeleteQuery(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('DELETE FROM booking_appointments'),
                self::equalTo(['id' => 'apt-to-delete']),
            );

        $repo = new DbAppointmentRepository($connection);
        $repo->delete('apt-to-delete');
    }

    #[Test]
    public function deleteCallsConnectionWithCorrectId(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $params): bool => $params['id'] === 'apt-99'),
            );

        $repo = new DbAppointmentRepository($connection);
        $repo->delete('apt-99');
    }
}
