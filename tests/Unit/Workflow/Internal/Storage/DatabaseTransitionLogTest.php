<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Internal\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Workflow\Internal\Storage\DatabaseTransitionLog;
use Pulsar\Workflow\Storage\TransitionRecord;
use RuntimeException;

use function assert;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Unit tests for DatabaseTransitionLog covering:
 * - record() — SQL parameter mapping with metadata JSON serialization
 * - getHistory() — ordered query and multi-row hydration
 * - reconstructState() — returns last to_state, throws on missing transitions
 * - Hydration of all fields including nullable reason and metadata JSON
 */
#[CoversClass(DatabaseTransitionLog::class)]
final class DatabaseTransitionLogTest extends TestCase
{
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    private function log(): DatabaseTransitionLog
    {
        return new DatabaseTransitionLog($this->connection);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function makeRecord(
        string $id = 'tr-1',
        string $instanceId = 'inst-1',
        string $fromState = 'draft',
        string $toState = 'review',
        string $transitionName = 'submit',
        string $actor = 'user-1',
        ?string $reason = null,
        array $metadata = [],
        int $instanceVersion = 2,
    ): TransitionRecord {
        return new TransitionRecord(
            id: $id,
            instanceId: $instanceId,
            fromState: $fromState,
            toState: $toState,
            transitionName: $transitionName,
            actor: $actor,
            reason: $reason,
            metadata: $metadata,
            instanceVersion: $instanceVersion,
            createdAt: new DateTimeImmutable('2026-01-01 12:00:00'),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRow(array $overrides = []): Row
    {
        /** @var array<string, mixed> $defaults */
        $defaults = [
            'id' => 'tr-1',
            'instance_id' => 'inst-1',
            'from_state' => 'draft',
            'to_state' => 'review',
            'transition_name' => 'submit',
            'actor' => 'user-1',
            'reason' => null,
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'instance_version' => 2,
            'created_at' => '2026-01-01 12:00:00',
        ];

        return new Row([...$defaults, ...$overrides]);
    }

    // =========================================================================
    // record()
    // =========================================================================

    #[Test]
    public function record_executes_insert_with_correct_parameters(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO workflow_transitions'),
                self::callback(static function (array $p): bool {
                    return $p['id'] === 'tr-1'
                        && $p['instance_id'] === 'inst-1'
                        && $p['from_state'] === 'draft'
                        && $p['to_state'] === 'review'
                        && $p['transition_name'] === 'submit'
                        && $p['actor'] === 'user-1'
                        && $p['reason'] === null
                        && $p['instance_version'] === 2
                        && $p['created_at'] === '2026-01-01 12:00:00';
                }),
            );

        $log = new DatabaseTransitionLog($connection);
        $log->record($this->makeRecord());
    }

    #[Test]
    public function record_serializes_metadata_as_json(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static function (array $p): bool {
                    assert(is_string($p['metadata']));
                    $data = json_decode($p['metadata'], true, 512, JSON_THROW_ON_ERROR);

                    return $data === ['priority' => 'high', 'sla' => 24];
                }),
            );

        $log = new DatabaseTransitionLog($connection);
        $log->record($this->makeRecord(metadata: ['priority' => 'high', 'sla' => 24]));
    }

    #[Test]
    public function record_passes_reason_when_present(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['reason'] === 'Escalated by manager'),
            );

        $log = new DatabaseTransitionLog($connection);
        $log->record($this->makeRecord(reason: 'Escalated by manager'));
    }

    #[Test]
    public function record_serializes_empty_metadata_as_empty_json_array(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(static fn(array $p): bool => $p['metadata'] === '[]'),
            );

        $log = new DatabaseTransitionLog($connection);
        $log->record($this->makeRecord(metadata: []));
    }

    // =========================================================================
    // getHistory()
    // =========================================================================

    #[Test]
    public function getHistory_returns_hydrated_records_in_chronological_order(): void
    {
        $rows = [
            $this->makeRow([
                'id' => 'tr-1',
                'from_state' => 'draft',
                'to_state' => 'review',
                'transition_name' => 'submit',
                'created_at' => '2026-01-01 10:00:00',
            ]),
            $this->makeRow([
                'id' => 'tr-2',
                'from_state' => 'review',
                'to_state' => 'done',
                'transition_name' => 'complete',
                'instance_version' => 3,
                'created_at' => '2026-01-01 11:00:00',
            ]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $history = $this->log()->getHistory('inst-1');

        self::assertCount(2, $history);

        self::assertSame('tr-1', $history[0]->id);
        self::assertSame('draft', $history[0]->fromState);
        self::assertSame('review', $history[0]->toState);
        self::assertSame('submit', $history[0]->transitionName);
        self::assertSame('user-1', $history[0]->actor);
        self::assertSame(2, $history[0]->instanceVersion);

        self::assertSame('tr-2', $history[1]->id);
        self::assertSame('review', $history[1]->fromState);
        self::assertSame('done', $history[1]->toState);
        self::assertSame('complete', $history[1]->transitionName);
        self::assertSame(3, $history[1]->instanceVersion);
    }

    #[Test]
    public function getHistory_returns_empty_when_no_transitions(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        self::assertSame([], $this->log()->getHistory('inst-1'));
    }

    #[Test]
    public function getHistory_hydrates_nullable_reason(): void
    {
        $rows = [
            $this->makeRow(['reason' => null]),
            $this->makeRow(['id' => 'tr-2', 'reason' => 'Approved by supervisor']),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $history = $this->log()->getHistory('inst-1');

        self::assertNull($history[0]->reason);
        self::assertSame('Approved by supervisor', $history[1]->reason);
    }

    #[Test]
    public function getHistory_hydrates_metadata_from_json(): void
    {
        $metadataJson = json_encode(['priority' => 'critical', 'escalated' => true], JSON_THROW_ON_ERROR);
        $row = $this->makeRow(['metadata' => $metadataJson]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $history = $this->log()->getHistory('inst-1');

        self::assertSame('critical', $history[0]->metadata['priority']);
        self::assertTrue($history[0]->metadata['escalated']);
    }

    #[Test]
    public function getHistory_hydrates_created_at_as_datetime(): void
    {
        $row = $this->makeRow(['created_at' => '2026-03-08 15:45:30']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $history = $this->log()->getHistory('inst-1');

        self::assertSame('2026-03-08 15:45:30', $history[0]->createdAt->format('Y-m-d H:i:s'));
    }

    // =========================================================================
    // reconstructState()
    // =========================================================================

    #[Test]
    public function reconstructState_returns_last_to_state(): void
    {
        $row = new Row(['to_state' => 'approved']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $state = $this->log()->reconstructState('inst-1');

        self::assertSame('approved', $state);
    }

    #[Test]
    public function reconstructState_throws_when_no_transitions_exist(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('no transitions found');
        $this->expectExceptionMessageIsOrContains('inst-missing');

        $this->log()->reconstructState('inst-missing');
    }

    #[Test]
    public function reconstructState_returns_initial_state_for_single_transition(): void
    {
        $row = new Row(['to_state' => 'review']);
        $this->connection->method('query')->willReturn(new Result([$row]));

        self::assertSame('review', $this->log()->reconstructState('inst-1'));
    }

    // =========================================================================
    // Full round-trip scenarios
    // =========================================================================

    #[Test]
    public function record_with_complex_metadata_round_trips_correctly(): void
    {
        $complexMetadata = [
            'sla_hours' => 24,
            'tags' => ['urgent', 'compliance'],
            'nested' => ['level' => 2, 'active' => true],
        ];

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::MySQL);

        $capturedMetadata = null;
        $connection->expects(self::once())
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $p) use (&$capturedMetadata): int {
                $capturedMetadata = $p['metadata'];

                return 1;
            });

        $log = new DatabaseTransitionLog($connection);
        $log->record($this->makeRecord(metadata: $complexMetadata));

        assert(is_string($capturedMetadata));
        $decoded = json_decode($capturedMetadata, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        self::assertSame(24, $decoded['sla_hours']);
        self::assertSame(['urgent', 'compliance'], $decoded['tags']);
        $nested = $decoded['nested'];
        assert(is_array($nested));
        self::assertSame(2, $nested['level']);
        self::assertTrue($nested['active']);
    }

    #[Test]
    public function getHistory_single_record_returns_all_fields(): void
    {
        $row = $this->makeRow([
            'id' => 'tr-full',
            'instance_id' => 'inst-99',
            'from_state' => 'submitted',
            'to_state' => 'approved',
            'transition_name' => 'approve',
            'actor' => 'admin-1',
            'reason' => 'All checks passed',
            'metadata' => json_encode(['category' => 'finance'], JSON_THROW_ON_ERROR),
            'instance_version' => 5,
            'created_at' => '2026-12-25 18:00:00',
        ]);
        $this->connection->method('query')->willReturn(new Result([$row]));

        $history = $this->log()->getHistory('inst-99');

        self::assertCount(1, $history);
        $record = $history[0];
        self::assertSame('tr-full', $record->id);
        self::assertSame('inst-99', $record->instanceId);
        self::assertSame('submitted', $record->fromState);
        self::assertSame('approved', $record->toState);
        self::assertSame('approve', $record->transitionName);
        self::assertSame('admin-1', $record->actor);
        self::assertSame('All checks passed', $record->reason);
        self::assertSame(['category' => 'finance'], $record->metadata);
        self::assertSame(5, $record->instanceVersion);
        self::assertSame('2026-12-25 18:00:00', $record->createdAt->format('Y-m-d H:i:s'));
    }
}
