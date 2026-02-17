<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Config\I18nConfig;
use Pulsar\Extension\Studio\Internal\Diagnostics\GitLogReader;
use Pulsar\Extension\Studio\Server\Controller\DeploymentController;
use Pulsar\I18n\CatalogInterface;
use Pulsar\I18n\Translator;

final class DeploymentControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $catalog = $this->createStub(CatalogInterface::class);
        $catalog->method('get')->willReturn(null);
        $catalog->method('has')->willReturn(false);
        Translator::setGlobalInstance(new Translator($catalog, new I18nConfig(
            defaultLocale: 'en',
            supportedLocales: ['en'],
            fallbackLocales: [],
            catalogPath: null,
            regulated: false,
            maxSupportedLocales: 50,
            strictMode: false,
        )));
    }

    protected function tearDown(): void
    {
        Translator::resetGlobalInstance();
    }

    #[Test]
    public function handleReturnsHtmlWithDeployments(): void
    {
        $gitLog = $this->createStub(GitLogReader::class);
        $gitLog->method('getVersionTags')->willReturn(['v1.0.1', 'v1.0.0']);
        $gitLog->method('getCurrentRef')->willReturn('v1.0.1');
        $gitLog->method('getCommitsBetween')->willReturn([
            [
                'hash' => 'abc123def456',
                'short_hash' => 'abc123d',
                'subject' => 'fix: resolve login issue',
                'author' => 'dev',
                'date' => '2025-01-15 10:00:00 +0000',
            ],
        ]);
        $gitLog->method('getDiffStats')->willReturn([
            'files_changed' => 5,
            'insertions' => 30,
            'deletions' => 10,
        ]);

        $controller = new DeploymentController($gitLog);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('deployment-viewer', $body);
        self::assertStringContainsString('v1.0.1', $body);
    }

    #[Test]
    public function handleWithNoTagsReturnsEmptyDeployments(): void
    {
        $gitLog = $this->createStub(GitLogReader::class);
        $gitLog->method('getVersionTags')->willReturn([]);
        $gitLog->method('getCurrentRef')->willReturn('abc123');

        $controller = new DeploymentController($gitLog);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // JSON payload is HTML-encoded in data-payload attribute
        self::assertStringContainsString('&quot;deployments&quot;:[]', $body);
        self::assertStringContainsString('&quot;tag_count&quot;:0', $body);
    }

    #[Test]
    public function handleSingleTagHasNoStats(): void
    {
        $gitLog = $this->createStub(GitLogReader::class);
        $gitLog->method('getVersionTags')->willReturn(['v1.0.0']);
        $gitLog->method('getCurrentRef')->willReturn('v1.0.0');
        $gitLog->method('getCommitsBetween')->willReturn([]);

        $controller = new DeploymentController($gitLog);

        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->handle($request);

        $body = (string) $response->getBody();
        // JSON payload is HTML-encoded in data-payload attribute
        self::assertStringContainsString('&quot;previous_tag&quot;:null', $body);
        self::assertStringContainsString('&quot;stats&quot;:null', $body);
    }
}
