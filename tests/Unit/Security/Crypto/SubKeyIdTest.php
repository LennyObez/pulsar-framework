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
     * Each subsystem must have a unique subKeyId. The
     * KDF's domain separation depends on this — two cases sharing
     * an int would silently produce identical derived keys, the
     * exact failure mode the enum exists to prevent.
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
