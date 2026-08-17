<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Incident;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\PlaybookOutcome;

#[CoversNothing]
final class PlaybookOutcomeTest extends TestCase
{
    #[Test]
    #[DataProvider('backedValueProvider')]
    public function backedValues(PlaybookOutcome $outcome, string $expected): void
    {
        self::assertSame($expected, $outcome->value);
    }

    /**
     * @return iterable<string, array{PlaybookOutcome, string}>
     */
    public static function backedValueProvider(): iterable
    {
        yield 'Completed' => [PlaybookOutcome::Completed, 'completed'];
        yield 'Halted' => [PlaybookOutcome::Halted, 'halted'];
        yield 'NoPlaybook' => [PlaybookOutcome::NoPlaybook, 'no_playbook'];
        yield 'Error' => [PlaybookOutcome::Error, 'error'];
    }

    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, PlaybookOutcome::cases());
    }

    #[Test]
    public function fromBackedValue(): void
    {
        self::assertSame(PlaybookOutcome::Completed, PlaybookOutcome::from('completed'));
        self::assertSame(PlaybookOutcome::NoPlaybook, PlaybookOutcome::from('no_playbook'));
    }
}
