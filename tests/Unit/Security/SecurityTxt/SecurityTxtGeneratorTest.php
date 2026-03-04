<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\SecurityTxt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\SecurityTxt\SecurityTxtConfig;
use Pulsar\Security\SecurityTxt\SecurityTxtGenerator;

use function explode;

#[CoversClass(SecurityTxtGenerator::class)]
#[CoversClass(SecurityTxtConfig::class)]
final class SecurityTxtGeneratorTest extends TestCase
{
    #[Test]
    public function generates_complete_security_txt(): void
    {
        $config = new SecurityTxtConfig(
            contacts: ['mailto:security@example.com', 'https://example.com/security'],
            expires: '2027-01-01T00:00:00Z',
            encryption: 'https://example.com/pgp-key.txt',
            acknowledgments: 'https://example.com/hall-of-fame',
            policy: 'https://example.com/security-policy',
            preferredLanguages: ['en', 'fr'],
            canonical: 'https://example.com/.well-known/security.txt',
            hiring: ['https://example.com/careers/security'],
        );

        $generator = new SecurityTxtGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('Contact: mailto:security@example.com', $output);
        self::assertStringContainsString('Contact: https://example.com/security', $output);
        self::assertStringContainsString('Expires: 2027-01-01T00:00:00Z', $output);
        self::assertStringContainsString('Encryption: https://example.com/pgp-key.txt', $output);
        self::assertStringContainsString('Acknowledgments: https://example.com/hall-of-fame', $output);
        self::assertStringContainsString('Policy: https://example.com/security-policy', $output);
        self::assertStringContainsString('Preferred-Languages: en, fr', $output);
        self::assertStringContainsString('Canonical: https://example.com/.well-known/security.txt', $output);
        self::assertStringContainsString('Hiring: https://example.com/careers/security', $output);
    }

    #[Test]
    public function omits_empty_fields(): void
    {
        $config = new SecurityTxtConfig(
            contacts: ['mailto:security@example.com'],
            expires: '2027-01-01T00:00:00Z',
        );

        $generator = new SecurityTxtGenerator($config);
        $output = $generator->generate();

        self::assertStringContainsString('Contact:', $output);
        self::assertStringContainsString('Expires:', $output);
        self::assertStringNotContainsString('Encryption:', $output);
        self::assertStringNotContainsString('Acknowledgments:', $output);
        self::assertStringNotContainsString('Policy:', $output);
        self::assertStringNotContainsString('Preferred-Languages:', $output);
        self::assertStringNotContainsString('Canonical:', $output);
        self::assertStringNotContainsString('Hiring:', $output);
    }

    #[Test]
    public function handles_request_as_handler(): void
    {
        $config = new SecurityTxtConfig(
            contacts: ['mailto:sec@example.com'],
            expires: '2027-01-01T00:00:00Z',
        );
        $generator = new SecurityTxtGenerator($config);

        $request = new ServerRequest(method: 'GET', uri: '/.well-known/security.txt');
        $response = $generator->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Contact: mailto:sec@example.com', (string) $response->getBody());
    }

    #[Test]
    public function output_ends_with_newline(): void
    {
        $config = new SecurityTxtConfig(contacts: ['mailto:a@b.com']);
        $generator = new SecurityTxtGenerator($config);

        self::assertStringEndsWith("\n", $generator->generate());
    }

    #[Test]
    public function multiple_contacts_each_on_own_line(): void
    {
        $config = new SecurityTxtConfig(
            contacts: ['mailto:a@b.com', 'mailto:c@d.com'],
        );
        $generator = new SecurityTxtGenerator($config);
        $output = $generator->generate();

        $lines = explode("\n", trim($output));
        self::assertSame('Contact: mailto:a@b.com', $lines[0]);
        self::assertSame('Contact: mailto:c@d.com', $lines[1]);
    }

    #[Test]
    public function from_array_parses_config(): void
    {
        $config = SecurityTxtConfig::fromArray([
            'contacts' => ['mailto:test@test.com'],
            'expires' => '2028-01-01T00:00:00Z',
            'encryption' => 'https://example.com/key',
            'preferred_languages' => ['en', 'de'],
            'hiring' => ['https://example.com/jobs'],
        ]);

        self::assertSame(['mailto:test@test.com'], $config->contacts);
        self::assertSame('2028-01-01T00:00:00Z', $config->expires);
        self::assertSame('https://example.com/key', $config->encryption);
        self::assertSame(['en', 'de'], $config->preferredLanguages);
        self::assertSame(['https://example.com/jobs'], $config->hiring);
    }

    #[Test]
    public function from_array_handles_empty_data(): void
    {
        $config = SecurityTxtConfig::fromArray([]);

        self::assertSame([], $config->contacts);
        self::assertSame('', $config->expires);
        self::assertSame('', $config->encryption);
    }
}
