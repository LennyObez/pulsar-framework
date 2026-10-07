<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Dlp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Dlp\DlpAction;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\SensitiveDataType;
use Pulsar\Security\Dlp\SensitivePattern;
use Pulsar\Security\Dlp\SensitivePatternRegistry;

use function count;

#[CoversClass(SensitivePatternRegistry::class)]
final class SensitivePatternRegistryTest extends TestCase
{
    public function testRegistersBuiltinPatterns(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $patterns = $registry->patterns();

        // Should have at least credit card, SSN, API key, IP address builtins
        self::assertGreaterThanOrEqual(4, count($patterns));
    }

    public function testRegisterAddsCustomPattern(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $initialCount = count($registry->patterns());

        $registry->register(new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/SECRET-[A-Z0-9]{16}/',
        ));

        self::assertCount($initialCount + 1, $registry->patterns());
    }

    public function testScanReturnsCleanForNoMatches(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $result = $registry->scan('This is a safe message with no sensitive data.');

        self::assertFalse($result->detected);
        self::assertSame([], $result->matches);
        self::assertSame('This is a safe message with no sensitive data.', $result->redactedContent);
    }

    public function testScanReturnsCleanWhenDisabled(): void
    {
        $config = new DlpConfig(enabled: false);
        $registry = new SensitivePatternRegistry($config);

        $result = $registry->scan('SSN: 123-45-6789');
        self::assertFalse($result->detected);
    }

    public function testScanReturnsCleanForEmptyContent(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $result = $registry->scan('');

        self::assertFalse($result->detected);
    }

    public function testDetectsSsn(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $result = $registry->scan('User SSN is 123-45-6789 on file.');

        self::assertTrue($result->detected);
        self::assertNotEmpty($result->matches);

        $ssnMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::Ssn) {
                $ssnMatch = $match;
                break;
            }
        }

        self::assertNotNull($ssnMatch);
    }

    public function testDetectsApiKey(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $result = $registry->scan('Authorization: token_live_abcdefghij1234567890extra');

        self::assertTrue($result->detected);

        $apiKeyMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::ApiKey) {
                $apiKeyMatch = $match;
                break;
            }
        }

        self::assertNotNull($apiKeyMatch);
    }

    public function testDetectsIpAddress(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $result = $registry->scan('Server at 192.168.1.100 responded.');

        self::assertTrue($result->detected);

        $ipMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::IpAddress) {
                $ipMatch = $match;
                break;
            }
        }

        self::assertNotNull($ipMatch);
    }

    public function testMaskingWithSuffix(): void
    {
        $config = new DlpConfig(mask: '*', maskSuffixLength: 4);
        $registry = new SensitivePatternRegistry($config);
        $result = $registry->scan('SSN: 123-45-6789');

        self::assertTrue($result->detected);
        // The redacted content should mask the SSN but keep last 4 chars
        self::assertStringContainsString('6789', $result->redactedContent);
        self::assertStringContainsString('*', $result->redactedContent);
    }

    public function testMaskingWithFullMask(): void
    {
        $config = new DlpConfig(mask: '#', maskSuffixLength: 0);
        $registry = new SensitivePatternRegistry($config);
        $result = $registry->scan('SSN: 123-45-6789');

        self::assertTrue($result->detected);
        // Verify the original SSN digits are masked in the output
        self::assertStringContainsString('#', $result->redactedContent);
        self::assertStringContainsString('SSN: ', $result->redactedContent);
    }

    public function testCustomPatternDetection(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $registry->register(new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/PATIENT-\d{8}/',
        ));

        $result = $registry->scan('Record for PATIENT-12345678 accessed.');

        self::assertTrue($result->detected);
        $customMatch = null;
        foreach ($result->matches as $match) {
            if ($match->type === SensitiveDataType::Custom) {
                $customMatch = $match;
                break;
            }
        }
        self::assertNotNull($customMatch);
    }

    public function testCustomPatternWithValidator(): void
    {
        $registry = new SensitivePatternRegistry(new DlpConfig());
        $registry->register(new SensitivePattern(
            type: SensitiveDataType::Custom,
            regex: '/CODE-\d{4}/',
            validator: static fn(string $v): bool => $v === 'CODE-1234',
        ));

        // CODE-1234 matches and passes validator
        $result1 = $registry->scan('Found CODE-1234 in data.');
        self::assertTrue($result1->detected);

        // CODE-5678 matches regex but fails validator
        $result2 = $registry->scan('Found CODE-5678 in data.');
        // Check that no custom match exists
        $hasCustom = false;
        foreach ($result2->matches as $match) {
            if ($match->type === SensitiveDataType::Custom) {
                $hasCustom = true;
            }
        }
        self::assertFalse($hasCustom);
    }

    public function testDefaultActionIsApplied(): void
    {
        $config = new DlpConfig(defaultAction: DlpAction::Block);
        $registry = new SensitivePatternRegistry($config);

        $result = $registry->scan('SSN: 123-45-6789');
        self::assertSame(DlpAction::Block, $result->actionTaken);
    }
}
