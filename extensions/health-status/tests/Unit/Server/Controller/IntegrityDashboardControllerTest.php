<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\HealthStatus\Contracts\IntegrityVerificationRunnerInterface;
use Pulsar\Extension\HealthStatus\Server\Controller\IntegrityDashboardController;
use Pulsar\Integrity\FileVerificationResult;
use Pulsar\Integrity\FileVerificationStatus;
use Pulsar\Integrity\VerificationResult;

use function json_decode;

#[CoversClass(IntegrityDashboardController::class)]
final class IntegrityDashboardControllerTest extends TestCase
{
    #[Test]
    public function invokeReturnsHtmlWith200(): void
    {
        $runner = $this->createRunnerStub($this->createPassingResult());

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function invokeRendersFullHtmlDocument(): void
    {
        $runner = $this->createRunnerStub($this->createEmptyResult());

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('File Integrity', $body);
    }

    #[Test]
    public function invokeRendersEmptyStateWhenNoFiles(): void
    {
        $runner = $this->createRunnerStub($this->createEmptyResult());

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No files in the integrity manifest', $body);
        self::assertStringNotContainsString('integrity-table', $body);
    }

    #[Test]
    public function invokeRendersVerificationResults(): void
    {
        $files = [
            new FileVerificationResult('src/Kernel.php', FileVerificationStatus::Verified, 'abc123', 'abc123'),
            new FileVerificationResult('src/Router.php', FileVerificationStatus::Modified, 'def456', 'xyz789'),
            new FileVerificationResult('config/app.php', FileVerificationStatus::Missing, 'ghi012', null),
        ];

        $result = new VerificationResult(
            passed: false,
            verified: 1,
            modified: 1,
            missing: 1,
            added: 0,
            files: $files,
        );

        $runner = $this->createRunnerStub($result);

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('src/Kernel.php', $body);
        self::assertStringContainsString('src/Router.php', $body);
        self::assertStringContainsString('config/app.php', $body);
        self::assertStringContainsString('integrity-table__status--verified', $body);
        self::assertStringContainsString('integrity-table__status--modified', $body);
        self::assertStringContainsString('integrity-table__status--missing', $body);
    }

    #[Test]
    public function invokeRendersSummaryStats(): void
    {
        $result = new VerificationResult(
            passed: true,
            verified: 42,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );

        $runner = $this->createRunnerStub($result);

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('42', $body);
        self::assertStringContainsString('Verified', $body);
    }

    #[Test]
    public function apiReturnsJsonWith200(): void
    {
        $runner = $this->createRunnerStub($this->createEmptyResult());

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->api($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function apiReturnsVerificationStructure(): void
    {
        $files = [
            new FileVerificationResult('src/Kernel.php', FileVerificationStatus::Verified, 'abc123', 'abc123'),
            new FileVerificationResult('src/Router.php', FileVerificationStatus::Modified, 'def456', 'xyz789'),
        ];

        $result = new VerificationResult(
            passed: false,
            verified: 1,
            modified: 1,
            missing: 0,
            added: 0,
            files: $files,
        );

        $runner = $this->createRunnerStub($result);

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->api($request);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertFalse($body['passed']);
        self::assertSame(1, $body['verified']);
        self::assertSame(1, $body['modified']);
        self::assertSame(0, $body['missing']);
        /** @var list<array{path: string, status: string}> $files */
        $files = $body['files'];
        self::assertCount(2, $files);
        self::assertSame('src/Kernel.php', $files[0]['path']);
        self::assertSame('verified', $files[0]['status']);
    }

    #[Test]
    public function invokeEscapesFilePathsInHtml(): void
    {
        $files = [
            new FileVerificationResult('<img onerror=alert(1)>', FileVerificationStatus::Verified, 'abc', 'abc'),
        ];

        $result = new VerificationResult(passed: true, verified: 1, modified: 0, missing: 0, added: 0, files: $files);

        $runner = $this->createRunnerStub($result);

        $controller = new IntegrityDashboardController($runner);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller($request);
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('<img onerror=alert(1)>', $body);
        self::assertStringContainsString('&lt;img', $body);
    }

    private function createRunnerStub(VerificationResult $result): IntegrityVerificationRunnerInterface
    {
        $runner = $this->createStub(IntegrityVerificationRunnerInterface::class);
        $runner->method('run')->willReturn($result);

        return $runner;
    }

    private function createPassingResult(): VerificationResult
    {
        return new VerificationResult(
            passed: true,
            verified: 10,
            modified: 0,
            missing: 0,
            added: 0,
            files: [
                new FileVerificationResult('src/Kernel.php', FileVerificationStatus::Verified, 'abc123', 'abc123'),
            ],
        );
    }

    private function createEmptyResult(): VerificationResult
    {
        return new VerificationResult(
            passed: true,
            verified: 0,
            modified: 0,
            missing: 0,
            added: 0,
            files: [],
        );
    }
}
