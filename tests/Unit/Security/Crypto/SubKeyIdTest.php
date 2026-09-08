<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Crypto;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\SubKeyId;

use function sprintf;

#[CoversNothing]
final class SubKeyIdTest extends TestCase
{
    /**
     * Two cases on the same int would name one id twice, which reads in review as
     * two allocations where there is one.
     *
     * This is a floor, not the invariant: the KDF mixes both the id and the
     * 8-byte context, so what must be unique per subsystem is the
     * `(id, context)` PAIR — ids are shared across contexts on purpose
     * throughout the framework, as {@see SubKeyId} records case by case. The
     * check that can actually catch a shared key is
     * {@see SubKeyIdRegistryTest}, which walks the call sites; this one holds the
     * line at the declaration.
     *
     * A previous revision of this comment claimed the engine had already made
     * that line redundant — "PHP already rejects a backed enum with a duplicated
     * value" — which would make the assertion below unfalsifiable and so, under
     * ADR-0060, worth nothing. Measured on the runtime this framework targets,
     * PHP 8.5.9, that claim is false, and the shape of the engine's check is the
     * reason:
     *
     * - Declaring `enum D: int { case A = 1; case B = 1; }` compiles and links
     *   with no diagnostic. `php -l` is silent and so is execution.
     * - `D::cases()` — what this test iterates — returns both cases and raises
     *   nothing, because it does not need the value-to-case lookup table.
     * - The engine raises `Error: Duplicate value in enum ... for cases A and B`
     *   only when that table is first built, which is any reference to a case
     *   constant (`D::A`), `from()` or `tryFrom()`.
     *
     * So a duplicate is caught, but as an uncaught `Error` at the first moment
     * some subsystem reaches for its sub-key id — at runtime, in whatever process
     * touches {@see \Pulsar\Idempotency\SignedIdempotencyEnvelope} or
     * {@see \Pulsar\Cache\Application\CacheManager} first — with a message
     * naming two enum cases and no indication that a key allocation is at stake.
     * This test converts that into a named CI failure at the declaration, before
     * the branch merges. Verified by adding `case Duplicate = 1;` to
     * {@see SubKeyId} and running this file: the engine stayed silent through
     * `cases()` and the assertion below failed with
     * "SubKeyId::Duplicate reuses int 1".
     */
    #[Test]
    public function everyCaseHasAUniqueIntegerValue(): void
    {
        $values = [];
        foreach (SubKeyId::cases() as $case) {
            self::assertNotContains(
                $case->value,
                $values,
                sprintf('SubKeyId::%s reuses int %d', $case->name, $case->value),
            );
            $values[] = $case->value;
        }
    }

    /**
     * The historical assignments below are baked into
     * shipped commits — flipping any of them silently re-keys an
     * existing subsystem. This regression test pins the values
     * so a refactor that reorders the enum cannot drift them.
     */
    #[Test]
    public function historicalAssignmentsArePinned(): void
    {
        self::assertSame(1, SubKeyId::Encryption->value);
        self::assertSame(2, SubKeyId::AuditChain->value);
        self::assertSame(3, SubKeyId::Pseudonymization->value);
        self::assertSame(4, SubKeyId::Csrf->value);
        self::assertSame(5, SubKeyId::Orm->value);
        self::assertSame(6, SubKeyId::SocialSso->value);
        self::assertSame(7, SubKeyId::Studio->value);
        self::assertSame(255, SubKeyId::Test->value);
    }
}
