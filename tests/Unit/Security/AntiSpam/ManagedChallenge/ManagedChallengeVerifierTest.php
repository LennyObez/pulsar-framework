<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\ManagedChallenge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeService;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeVerifier;

use function hash;
use function ord;
use function strlen;

#[CoversClass(ManagedChallengeVerifier::class)]
final class ManagedChallengeVerifierTest extends TestCase
{
    private const int BITS = 8;

    private function verifier(): ManagedChallengeVerifier
    {
        return new ManagedChallengeVerifier(
            new ManagedChallengeService('0123456789abcdef0123456789abcdef', self::BITS, 300),
        );
    }

    private function solvedToken(ManagedChallengeService $service): string
    {
        $token = $service->issue();
        $challenge = $service->parse($token);
        self::assertNotNull($challenge);

        for ($i = 0; $i < 1_000_000; $i++) {
            $digest = hash('sha256', $challenge->id . '.' . $i, true);
            if ($this->leadingZeroBits($digest) >= $challenge->bits) {
                return $token . '.' . $i;
            }
        }

        self::fail('No solution found');
    }

    private function leadingZeroBits(string $bytes): int
    {
        $count = 0;
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            if ($byte === 0) {
                $count += 8;

                continue;
            }
            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $count;
                }
                $count++;
            }

            return $count;
        }

        return $count;
    }

    private function context(?string $token): AntiSpamContext
    {
        return new AntiSpamContext(body: 'hello world', ipHash: 'iphash', captchaToken: $token);
    }

    #[Test]
    public function name_is_captcha(): void
    {
        self::assertSame('captcha', $this->verifier()->name());
    }

    #[Test]
    public function check_passes_for_a_solved_token(): void
    {
        $service = new ManagedChallengeService('0123456789abcdef0123456789abcdef', self::BITS, 300);
        $verifier = new ManagedChallengeVerifier($service);

        $result = $verifier->check($this->context($this->solvedToken($service)));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function check_fails_for_missing_token(): void
    {
        $result = $this->verifier()->check($this->context(null));

        self::assertFalse($result->passed);
        self::assertSame(30, $result->score);
        self::assertStringContainsString('missing', (string) $result->reason);
    }

    #[Test]
    public function check_fails_for_invalid_token(): void
    {
        $result = $this->verifier()->check($this->context('garbage.123'));

        self::assertFalse($result->passed);
        self::assertStringContainsString('failed', (string) $result->reason);
    }

    #[Test]
    public function verify_delegates_to_the_service(): void
    {
        $service = new ManagedChallengeService('0123456789abcdef0123456789abcdef', self::BITS, 300);
        $verifier = new ManagedChallengeVerifier($service);

        self::assertTrue($verifier->verify($this->solvedToken($service), 'iphash'));
        self::assertFalse($verifier->verify('garbage.123', 'iphash'));
    }
}
