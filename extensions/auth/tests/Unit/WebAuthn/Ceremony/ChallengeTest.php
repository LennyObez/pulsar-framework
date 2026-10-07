<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\WebAuthn\Ceremony;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\WebAuthn\Ceremony\Challenge;

use function base64_decode;
use function base64_encode;
use function chr;
use function ord;
use function random_bytes;
use function rtrim;
use function str_repeat;
use function strlen;
use function strtr;

#[CoversClass(Challenge::class)]
final class ChallengeTest extends TestCase
{
    private const string NOW = '2026-03-01T12:00:00+00:00';

    #[Test]
    public function issuedChallengeDecodesToThirtyTwoBytes(): void
    {
        $raw = self::base64UrlDecode(Challenge::issue($this->at(self::NOW)));

        self::assertSame(32, strlen($raw));
        self::assertSame(Challenge::FORMAT_VERSION, ord($raw[0]));
    }

    #[Test]
    public function issuedChallengeRoundTripsItsIssuanceInstant(): void
    {
        $now = $this->at(self::NOW);

        $issuedAt = Challenge::issuedAt(Challenge::issue($now));

        self::assertNotNull($issuedAt);
        self::assertSame($now->getTimestamp(), $issuedAt->getTimestamp());
    }

    /**
     * The random tail is what keeps a challenge unguessable; the timestamp
     * prefix must not have eaten it.
     */
    #[Test]
    public function twoChallengesMintedInTheSameSecondDiffer(): void
    {
        $now = $this->at(self::NOW);

        self::assertNotSame(Challenge::issue($now), Challenge::issue($now));
    }

    #[Test]
    public function aFreshlyIssuedChallengeIsFresh(): void
    {
        $now = $this->at(self::NOW);

        self::assertTrue(Challenge::isFresh(Challenge::issue($now), 300, $now));
    }

    #[Test]
    public function aChallengeIsFreshOnTheFinalSecondOfTheWindow(): void
    {
        $challenge = Challenge::issue($this->at(self::NOW));

        self::assertTrue(Challenge::isFresh($challenge, 300, $this->at('2026-03-01T12:05:00+00:00')));
    }

    #[Test]
    public function aChallengeIsStaleOneSecondPastTheWindow(): void
    {
        $challenge = Challenge::issue($this->at(self::NOW));

        self::assertFalse(Challenge::isFresh($challenge, 300, $this->at('2026-03-01T12:05:01+00:00')));
    }

    /**
     * A challenge dated further into the future than the window cannot have
     * come from this server's clock. Calling it "not yet expired" would give an
     * unbounded lifetime to exactly the values that deserve none — including a
     * random string that happens to carry the format byte.
     */
    #[Test]
    public function aChallengeDatedBeyondTheWindowInTheFutureIsNotFresh(): void
    {
        $challenge = Challenge::issue($this->at('2026-03-01T12:05:01+00:00'));

        self::assertFalse(Challenge::isFresh($challenge, 300, $this->at(self::NOW)));
    }

    /**
     * A zero or negative TTL is read as "no window", not as "no limit".
     */
    #[Test]
    public function aNonPositiveTtlRefusesEveryChallenge(): void
    {
        $now = $this->at(self::NOW);
        $challenge = Challenge::issue($now);

        self::assertTrue(Challenge::isFresh($challenge, 0, $now));
        self::assertFalse(Challenge::isFresh($challenge, 0, $this->at('2026-03-01T12:00:01+00:00')));
        self::assertFalse(Challenge::isFresh($challenge, -1, $now));
    }

    /**
     * @return list<array{string}>
     */
    public static function notServerMinted(): array
    {
        return [
            'empty' => [''],
            'plain text' => ['hand-rolled-challenge'],
            'not base64url' => ['!!!!'],
            'right length, wrong version byte' => [
                self::base64UrlEncode(chr(0x02) . str_repeat("\x01", 31)),
            ],
            'right version, zero timestamp' => [
                self::base64UrlEncode(chr(Challenge::FORMAT_VERSION) . str_repeat("\x00", 7) . str_repeat("\x01", 24)),
            ],
            'too short' => [
                self::base64UrlEncode(chr(Challenge::FORMAT_VERSION) . str_repeat("\x01", 20)),
            ],
            'too long' => [
                self::base64UrlEncode(chr(Challenge::FORMAT_VERSION) . str_repeat("\x01", 40)),
            ],
        ];
    }

    #[Test]
    #[DataProvider('notServerMinted')]
    public function aValueThisServerNeverMintedHasNoIssuanceInstantAndIsNotFresh(string $challenge): void
    {
        self::assertNull(Challenge::issuedAt($challenge));
        self::assertFalse(Challenge::isFresh($challenge, 300, $this->at(self::NOW)));
    }

    /**
     * 32 bytes of pure randomness — what the ceremony used to emit — is not
     * accepted as a dated challenge. Without this the change would leave a
     * downgrade path: keep using an old-style challenge and skip the window.
     */
    #[Test]
    public function undatedRandomBytesAreRefused(): void
    {
        $legacyStyle = self::base64UrlEncode(random_bytes(32));

        // One byte value in 256 collides with the format marker by chance; the
        // timestamp it would then decode to is astronomically far from `now`,
        // so the freshness answer is stable either way.
        self::assertFalse(Challenge::isFresh($legacyStyle, 300, $this->at(self::NOW)));
    }

    /**
     * The expiry a challenge store schedules against must be the same instant the
     * freshness window ends on, or a record is dropped while the ceremony still
     * considers the challenge answerable — which is a replay window reopened by a
     * rounding disagreement rather than by any decision.
     */
    #[Test]
    public function expiresAtIsTheLastInstantFreshnessStillAccepts(): void
    {
        $issuedAt = $this->at(self::NOW);
        $challenge = Challenge::issue($issuedAt);

        $expiresAt = Challenge::expiresAt($challenge, 300);

        self::assertNotNull($expiresAt);
        self::assertSame($issuedAt->getTimestamp() + 300, $expiresAt->getTimestamp());
        self::assertTrue(Challenge::isFresh($challenge, 300, $expiresAt));
        self::assertFalse(Challenge::isFresh($challenge, 300, $expiresAt->modify('+1 second')));
    }

    /**
     * A value this server never minted has no issuance instant and therefore no
     * expiry. Reporting one — "now plus the TTL", say — would hand an unbounded
     * lifetime to exactly the values that deserve none, by letting a store hold a
     * record for a challenge whose freshness can never be established.
     */
    #[Test]
    public function expiresAtIsNullForAValueThisServerNeverMinted(): void
    {
        self::assertNull(Challenge::expiresAt('hand-rolled-challenge', 300));
        self::assertNull(Challenge::expiresAt('', 300));
        self::assertNull(
            Challenge::expiresAt(self::base64UrlEncode(chr(99) . str_repeat("\x00", 31)), 300),
        );
    }

    /**
     * A zero window still names an instant: the second of issuance. `0` reads as
     * "no window", not "no expiry", and a store must be able to schedule against
     * it like any other.
     */
    #[Test]
    public function expiresAtHandlesAZeroWindow(): void
    {
        $issuedAt = $this->at(self::NOW);
        $expiresAt = Challenge::expiresAt(Challenge::issue($issuedAt), 0);

        self::assertNotNull($expiresAt);
        self::assertSame($issuedAt->getTimestamp(), $expiresAt->getTimestamp());
    }

    private function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded !== false ? $decoded : '';
    }
}
