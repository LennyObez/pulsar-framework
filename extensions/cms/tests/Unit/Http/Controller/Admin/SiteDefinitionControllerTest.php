<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\SiteDefinitionController;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportResult;

use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(SiteDefinitionController::class)]
final class SiteDefinitionControllerTest extends TestCase
{
    /**
     * Build a permissive authorization gate for the controller under test.
     *
     * After the MED-3 fix, `AbstractAdminController::authorize()` is
     * deny-by-default when no gate is wired — unit tests that exercise
     * an authenticated admin path must therefore inject a gate explicitly
     * rather than relying on the previous silent-allow behavior.
     */
    private function createPermissiveGate(): GateInterface
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('allows')->willReturn(true);
        $gate->method('denies')->willReturn(false);

        return $gate;
    }

    #[Test]
    public function dryRunUsesUnifiedImportAndReturnsStructuredResult(): void
    {
        $expectedResult = new ImportResult(
            created: ['content' => 5, 'taxonomies' => 3],
            updated: ['content' => 2],
            skipped: ['menus' => 1],
            warnings: ['Media ref not found'],
            errors: [],
            dryRun: true,
        );

        $service = $this->createMock(ImportExportServiceInterface::class);
        $service->expects(self::once())
            ->method('importUnifiedFile')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v)), true)
            ->willReturn($expectedResult);

        $controller = new SiteDefinitionController($service, $this->createPermissiveGate());

        $body = json_encode([
            'version' => '1.0',
            'site' => ['name' => 'Test'],
            'content' => [],
        ], JSON_THROW_ON_ERROR);

        $request = $this->createRequestWithBody($body);
        $response = $controller->dryRun($request);
        $responseBody = (string) $response->getBody();

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $decoded['created']['content']);
        self::assertSame(3, $decoded['created']['taxonomies']);
        self::assertSame(2, $decoded['updated']['content']);
        self::assertSame(1, $decoded['skipped']['menus']);
        self::assertSame(['Media ref not found'], $decoded['warnings']);
        self::assertTrue($decoded['dry_run']);
    }

    #[Test]
    public function dryRunReturnsErrorForMissingContent(): void
    {
        $service = $this->createStub(ImportExportServiceInterface::class);
        $controller = new SiteDefinitionController($service, $this->createPermissiveGate());

        $request = $this->createRequestWithBody('');
        $response = $controller->dryRun($request);

        self::assertSame(400, $response->getStatusCode());
    }

    private function createRequestWithBody(string $body): ServerRequestInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('admin-user-id');
        $identity->method('isAuthenticated')->willReturn(true);

        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->willReturnMap([
            ['identity', null, $identity],
            ['step_up_verified', false, true],
        ]);

        return $request;
    }
}
