<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Session\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\Session\SessionMetadata;
use Pulsar\Security\Session\Validator\UserAgentValidator;

#[CoversClass(UserAgentValidator::class)]
final class UserAgentValidatorTest extends TestCase
{
    private function createRequestWithUserAgent(string $ua): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['User-Agent' => $ua],
        );
    }

    private function createMetadataWithUserAgent(string $ua): SessionMetadata
    {
        return new SessionMetadata(
            createdAt: time(),
            lastActivity: time(),
            ipAddress: '127.0.0.1',
            userAgent: $ua,
        );
    }

    #[Test]
    public function test_normalized_mode_passes_when_major_version_matches(): void
    {
        $validator = new UserAgentValidator('normalized');

        // Same browser family + major version, different minor versions
        $stored = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.130 Safari/537.36';
        $current = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.200 Safari/537.36';

        $metadata = $this->createMetadataWithUserAgent($stored);
        $request = $this->createRequestWithUserAgent($current);

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_normalized_mode_fails_when_browser_differs(): void
    {
        $validator = new UserAgentValidator('normalized');

        $stored = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.130 Safari/537.36';
        $current = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0';

        $metadata = $this->createMetadataWithUserAgent($stored);
        $request = $this->createRequestWithUserAgent($current);

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_strict_mode_passes_exact_match(): void
    {
        $validator = new UserAgentValidator('strict');

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.130 Safari/537.36';

        $metadata = $this->createMetadataWithUserAgent($ua);
        $request = $this->createRequestWithUserAgent($ua);

        self::assertTrue($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_strict_mode_fails_minor_difference(): void
    {
        $validator = new UserAgentValidator('strict');

        $stored = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.130 Safari/537.36';
        $current = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.6099.131 Safari/537.36';

        $metadata = $this->createMetadataWithUserAgent($stored);
        $request = $this->createRequestWithUserAgent($current);

        self::assertFalse($validator->validate($metadata, $request));
    }

    #[Test]
    public function test_get_name_returns_user_agent(): void
    {
        $validator = new UserAgentValidator();

        self::assertSame('user_agent', $validator->getName());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function normalizedModeDataProvider(): array
    {
        return [
            'identical user agents pass' => [
                'Mozilla/5.0 Chrome/120 Safari/537',
                'Mozilla/5.0 Chrome/120 Safari/537',
                true,
            ],
            'same major versions different minor pass' => [
                'Mozilla/5.0 Chrome/120.0.1 Safari/537.36',
                'Mozilla/5.0 Chrome/120.0.2 Safari/537.36',
                true,
            ],
            'different major versions fail' => [
                'Mozilla/5.0 Chrome/120 Safari/537',
                'Mozilla/5.0 Chrome/121 Safari/537',
                false,
            ],
            'empty user agents match' => [
                '',
                '',
                true,
            ],
        ];
    }

    #[Test]
    #[DataProvider('normalizedModeDataProvider')]
    public function test_normalized_mode_with_data_provider(string $stored, string $current, bool $expected): void
    {
        $validator = new UserAgentValidator('normalized');
        $metadata = $this->createMetadataWithUserAgent($stored);
        $request = $this->createRequestWithUserAgent($current);

        self::assertSame($expected, $validator->validate($metadata, $request));
    }
}
