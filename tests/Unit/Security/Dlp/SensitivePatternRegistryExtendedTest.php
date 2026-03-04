<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

#[CoversClass(SensitivePatternRegistry::class)]
#[CoversClass(SensitivePattern::class)]
final class SensitivePatternRegistryExtendedTest extends TestCase
{
    private function createRegistry(bool $enabled = true): SensitivePatternRegistry
    {
        return new SensitivePatternRegistry(new DlpConfig(enabled: $enabled));
    }

    public function testDisabledRegistryReturnsClean(): void
    {
        $registry = $this->createRegistry(enabled: false);
        $result = $registry->scan('4111111111111111');

        self::assertFalse($result->detected);
    }

    public function testEmptyContentReturnsClean(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('');

        self::assertFalse($result->detected);
    }

    public function testDetectsCreditCardNumber(): void
    {
        $registry = $this->createRegistry();
        // Visa test card that passes Luhn check
        $result = $registry->scan('Payment with card 4111111111111111 accepted');

        self::assertTrue($result->detected);
        self::assertNotEmpty($result->matches);

        $ccMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::CreditCard) {
                $ccMatch = $match;
                break;
            }
        }
        self::assertNotNull($ccMatch, 'Credit card should be detected');
    }

    public function testDetectsSsn(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('SSN: 123-45-6789 found');

        self::assertTrue($result->detected);

        $ssnMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::Ssn) {
                $ssnMatch = $match;
                break;
            }
        }
        self::assertNotNull($ssnMatch, 'SSN should be detected');
    }

    public function testDetectsApiKey(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('Using key: sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        self::assertTrue($result->detected);

        $keyMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::ApiKey) {
                $keyMatch = $match;
                break;
            }
        }
        self::assertNotNull($keyMatch, 'API key should be detected');
    }

    public function testDetectsIpAddress(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('Server at 192.168.1.100 responded');

        self::assertTrue($result->detected);

        $ipMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::IpAddress) {
                $ipMatch = $match;
                break;
            }
        }
        self::assertNotNull($ipMatch, 'IP address should be detected');
    }

    public function testCleanContentNotFlagged(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('This is a clean response without sensitive data.');

        self::assertFalse($result->detected);
        self::assertSame([], $result->matches);
    }

    public function testCustomPatternRegistration(): void
    {
        $registry = $this->createRegistry();
        $registry->register(new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/INTERNAL-\d{10}/',
        ));

        $result = $registry->scan('Reference: INTERNAL-1234567890');

        self::assertTrue($result->detected);
    }

    public function testCustomPatternWithValidator(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig(enabled: true));
        $registry->register(new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/CODE-\d{4}/',
            validator: static fn(string $v): bool => $v === 'CODE-1234',
        ));

        // Matching pattern but fails validator
        $result = $registry->scan('CODE-5678 is not the one');
        $customMatches = array_filter(
            $result->matches,
            static fn($m) => $m->type === SensitiveDataType::Custom,
        );
        self::assertEmpty($customMatches);

        // Matching pattern and passes validator
        $result2 = $registry->scan('CODE-1234 is the secret');
        $customMatches2 = array_filter(
            $result2->matches,
            static fn($m) => $m->type === SensitiveDataType::Custom,
        );
        self::assertNotEmpty($customMatches2);
    }

    public function testMaskingWithSuffix(): void
    {
        $config = new DlpConfig(enabled: true, mask: '*', maskSuffixLength: 4);
        $registry = new SensitivePatternRegistry($config);
        $result = $registry->scan('SSN: 123-45-6789');

        if ($result->detected) {
            self::assertStringContainsString('6789', $result->redactedContent);
        }
    }

    public function testMaskingWithZeroSuffix(): void
    {
        $config = new DlpConfig(enabled: true, mask: 'X', maskSuffixLength: 0);
        $registry = new SensitivePatternRegistry($config);
        $result = $registry->scan('SSN: 123-45-6789');

        self::assertTrue($result->detected);
        // With zero suffix, the matched value is fully masked (no trailing visible chars)
        self::assertStringContainsString('XXX', $result->redactedContent);
    }

    public function testPatternsAccessor(): void
    {
        $registry = $this->createRegistry();
        $patterns = $registry->patterns();

        // Should have built-in patterns
        self::assertNotEmpty($patterns);

        $types = array_map(static fn(SensitivePattern $p): SensitiveDataType => $p->type, $patterns);
        self::assertContains(SensitiveDataType::CreditCard, $types);
        self::assertContains(SensitiveDataType::Ssn, $types);
        self::assertContains(SensitiveDataType::ApiKey, $types);
        self::assertContains(SensitiveDataType::IpAddress, $types);
    }

    public function testRedactedContentPreservesNonSensitiveText(): void
    {
        $registry = $this->createRegistry();
        $result = $registry->scan('Hello World, SSN: 123-45-6789, bye');

        if ($result->detected) {
            self::assertStringContainsString('Hello World', $result->redactedContent);
            self::assertStringContainsString('bye', $result->redactedContent);
        }
    }

    #[DataProvider('invalidCreditCardProvider')]
    public function testLuhnCheckRejectsInvalidCards(string $cardNumber): void
    {
        $registry = $this->createRegistry();
        // Wrap in text so the regex finds it
        $result = $registry->scan("Card: $cardNumber end");

        // If matches exist, none should be credit card (Luhn fails)
        $ccMatches = array_filter(
            $result->matches,
            static fn($m) => $m->type === SensitiveDataType::CreditCard,
        );
        self::assertEmpty($ccMatches, "Invalid card $cardNumber should not pass Luhn");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCreditCardProvider(): iterable
    {
        yield 'sequential digits' => ['1234567890123456'];
        yield 'failing Luhn' => ['4111111111111112'];
    }
}
