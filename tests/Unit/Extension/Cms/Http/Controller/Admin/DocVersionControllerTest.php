<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Cms\Docs\DocVersionServiceInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\DocVersionController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DocVersionController::class)]
final class DocVersionControllerTest extends TestCase
{
    #[Test]
    public function index_returns_versions_with_default(): void
    {
        $service = $this->createStub(DocVersionServiceInterface::class);
        $service->method('listVersions')->willReturn([
            ['slug' => '1.0', 'label' => 'Version 1.0'],
            ['slug' => '2.0', 'label' => 'Version 2.0'],
        ]);
        $service->method('getDefaultVersion')->willReturn('2.0');

        $controller = new DocVersionController(versionService: $service);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $controller->index();

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body['data']);
        self::assertCount(2, $body['data']);
        self::assertSame('2.0', $body['default']);
    }

    #[Test]
    public function set_default_returns_success(): void
    {
        $service = $this->createStub(DocVersionServiceInterface::class);

        $controller = new DocVersionController(versionService: $service);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['version_slug' => '1.0']);

        $response = $controller->setDefault($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
    }

    #[Test]
    public function set_default_returns_400_when_slug_missing(): void
    {
        $service = $this->createStub(DocVersionServiceInterface::class);

        $controller = new DocVersionController(versionService: $service);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);

        $response = $controller->setDefault($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function set_default_returns_400_when_slug_empty(): void
    {
        $service = $this->createStub(DocVersionServiceInterface::class);

        $controller = new DocVersionController(versionService: $service);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['version_slug' => '']);

        $response = $controller->setDefault($request);

        self::assertSame(400, $response->getStatusCode());
    }
}
